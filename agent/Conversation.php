<?php

declare(strict_types=1);

namespace nova\plugin\ai\agent;

use nova\framework\core\Context;
use nova\framework\core\Logger;
use nova\plugin\ai\AiConfig;
use Throwable;

/**
 * Agent 多轮对话的历史存储与压缩，配合 Agent::history() / Agent::persist() 使用。
 *
 * 【为什么用 Cache 而不是 Session】
 * Agent 常跑在 SSE 长连接里，一次请求可能几十轮工具调用、持续数分钟。
 * PHP 的 session 在请求期间独占文件锁，写进 session 就意味着这段时间里
 * 同一浏览器的其他请求全部排队等待，页面上别的功能会集体卡死。
 * Cache 没有这个问题，而且对话本就是临时数据，靠 TTL 自然过期，不必建表也不必清理。
 *
 * 【消息序列的硬约束】
 * OpenAI 兼容接口要求 role=tool 的消息必须紧跟在带 tool_calls 的 assistant 之后。
 * 所以历史绝不能以孤立的 tool 消息开头，压缩时的切分点必须避开它们（见 splitPoint）。
 */
final class Conversation
{
    /** 对话闲置多久后丢弃。够长到中途走开一会儿再回来，又不至于让隔天的旧上下文冒出来 */
    private const int TTL = 7200;

    /**
     * 历史估算 token 超过此值就压缩。这是留给历史的份额，不是模型的上下文总长。
     *
     * 一次请求还要装下 system prompt（人格与随附手册）、工具的 JSON Schema 定义，
     * 以及本轮输出的预留。人格特别长时要同步下调这里，否则先撑爆的是模型上下文，
     * 而不是这个阈值。按下面 estimate 的口径，两万 token 约合一万三千个汉字。
     */
    private const int COMPRESS_AT = 20000;

    /** 摘要时单条 tool 结果最多取多少字符：工具吐出的全文没必要进摘要，结论才有价值 */
    private const int TOOL_EXCERPT = 600;

    private readonly string $key;

    /**
     * @param string $scope          调用方的隔离维度，如「站点:用户」。来自服务端，视为可信
     * @param string $conversationId 会话标识，通常由前端生成，不可信，净化后才进 key
     */
    public function __construct(string $scope, string $conversationId)
    {
        // conversationId 直接拼进 cache key 等于让外部控制键名；
        // 文件缓存驱动下带 ../ 的键会写到意料之外的位置，必须先削成纯 token
        $safe = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $conversationId) ?? '', 0, 64);
        $this->key = 'ai:conv:' . $scope . ':' . $safe;
    }

    /**
     * 读取历史，过长时先压缩。
     *
     * 压缩结果不单独回写：本轮结束时 save() 会用「压缩后历史 + 本轮消息」整体覆盖，
     * 压缩成果自然落盘。只有本轮异常中断才会白压一次，代价可以接受。
     *
     * @return array<int, array<string, mixed>>
     */
    public function load(): array
    {
        $messages = Context::instance()->cache->get($this->key, []);
        if (!is_array($messages) || $messages === []) {
            return [];
        }

        if ($this->countTokens($messages) < self::COMPRESS_AT) {
            return $messages;
        }

        return $this->compress($messages);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function save(array $messages): void
    {
        Context::instance()->cache->set($this->key, $messages, self::TTL);
    }

    /**
     * 把早期消息换成一段纪要，保留最近的原文。
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function compress(array $messages): array
    {
        $at = $this->splitPoint($messages);
        $older = array_slice($messages, 0, $at);
        $recent = array_slice($messages, $at);

        if ($older === []) {
            return $recent;
        }

        $summary = $this->summarize($older);
        if ($summary === '') {
            // 摘要拿不到就直接丢掉早期历史。宁可少一段上下文，
            // 也不要因为压缩失败让整个对话功能不可用
            Logger::warning('对话摘要失败，已丢弃早期历史', ['key' => $this->key]);
            return $recent;
        }

        array_unshift($recent, [
            'role' => 'user',
            'content' => "以下是本次对话前面部分的纪要，供你延续上下文：\n\n" . $summary,
        ]);

        return $recent;
    }

    /**
     * 保留区的起始下标。
     *
     * 先按 token 从后往前收，攒到半个预算为止——不用固定条数，因为一条读文件的
     * 工具结果可能比十轮问答还长，按条数保留等于没有上限。
     * 收完再往后跳过 tool 消息：保留区一旦以 tool 开头，它对应的 assistant(tool_calls)
     * 已被压进摘要，接口会直接报错。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function splitPoint(array $messages): int
    {
        $total = count($messages);
        $budget = intdiv(self::COMPRESS_AT, 2);

        $at = $total;
        $acc = 0;
        while ($at > 0) {
            $acc += $this->tokensOf($messages[$at - 1]);
            if ($acc > $budget) {
                break;
            }
            $at--;
        }

        while ($at < $total && ($messages[$at]['role'] ?? '') === 'tool') {
            $at++;
        }

        return $at;
    }

    /**
     * 让模型把早期对话压成纪要；失败返回空串由调用方降级。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function summarize(array $messages): string
    {
        $provider = AiConfig::getInstance()->resolveProvider();
        if ($provider === null) {
            return '';
        }

        $prompt = <<<'TXT'
            把下面这段助手与用户的对话压缩成简洁的中文纪要，只保留后续对话必须知道的信息：
            1. 用户的目标、偏好与明确提出的约束；
            2. 已经完成的操作，逐一写明涉及的对象标识（路径、ID、名称等）与结果状态；
            3. 尚未完成或用户已经否决的事项；
            4. 过程中得出的、影响后续判断的结论。
            不要复述工具的原始输出，不要保留寒暄。直接给纪要正文，不要写开场白。
            TXT;

        try {
            $result = $provider->chat([
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $this->flatten($messages)],
            ], ['temperature' => 0.3, 'reasoning' => false]);
        } catch (Throwable $e) {
            Logger::warning('对话摘要请求异常', ['msg' => $e->getMessage()]);
            return '';
        }

        return trim((string)($result['content'] ?? ''));
    }

    /**
     * 消息序列摊平成纯文本，喂给摘要模型。
     * tool 结果按 TOOL_EXCERPT 截断：摘要要的是「做了什么」，不是工具吐出的全文。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function flatten(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = (string)($m['role'] ?? '');
            $content = trim((string)($m['content'] ?? ''));

            foreach ($m['tool_calls'] ?? [] as $call) {
                $name = (string)($call['function']['name'] ?? '');
                $args = (string)($call['function']['arguments'] ?? '');
                $lines[] = '[调用工具] ' . $name . ' ' . $args;
            }

            if ($content === '') {
                continue;
            }

            if ($role === 'tool') {
                if (mb_strlen($content) > self::TOOL_EXCERPT) {
                    $content = mb_substr($content, 0, self::TOOL_EXCERPT) . '…（已截断）';
                }
                $lines[] = '[工具结果] ' . $content;
                continue;
            }

            $lines[] = ($role === 'user' ? '[用户] ' : '[助手] ') . $content;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function countTokens(array $messages): int
    {
        $sum = 0;
        foreach ($messages as $m) {
            $sum += $this->tokensOf($m);
        }

        return $sum;
    }

    /**
     * 粗估单条消息的 token 数。
     *
     * @param array<string, mixed> $message
     */
    private function tokensOf(array $message): int
    {
        $text = (string)($message['content'] ?? '');
        foreach ($message['tool_calls'] ?? [] as $call) {
            $text .= (string)($call['function']['arguments'] ?? '');
        }

        return self::estimate($text);
    }

    /**
     * 非 ASCII 字符按 1.5 token 计，ASCII 按 4 字符 1 token。
     *
     * 别改成「字符数除以二」那种省事写法：中文内容下一个汉字在 cl100k 系列
     * tokenizer 里就要 1～2 个 token，按二折算会把真实用量低估到三分之一，
     * 压缩迟迟不触发，直接冲爆上下文。
     */
    private static function estimate(string $text): int
    {
        $wide = (int)preg_match_all('/[^\x00-\x7F]/u', $text);
        $ascii = max(0, mb_strlen($text) - $wide);

        return (int)($wide * 1.5 + $ascii / 4);
    }
}
