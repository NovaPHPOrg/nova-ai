<?php

declare(strict_types=1);

namespace nova\plugin\ai\agent;

use nova\framework\core\Instance;
use nova\plugin\ai\AiConfig;
use nova\plugin\ai\mcp\Mcp;
use nova\plugin\ai\tool\HttpTools;
use nova\plugin\ai\tool\ToolInterface;
use nova\plugin\ai\tool\ToolRegistry;

/**
 * Agent 人格父类。
 *
 * 业务继承本类，定义人格(persona)与可用工具(tools)，
 * 调用 run() 即可跑完整的 function-calling 工具调用循环（单次 run 内的多轮）。
 *
 * 子类至少实现 persona()；其余按需覆盖。
 *
 * 跨请求的多轮对话是本类自带的能力，子类不需要写任何代码：
 *
 *     (new MyAgent())->withConversation(new Conversation($scope, $cid))->run($prompt);
 *
 * 挂了会话就自动接续历史并在收尾时存回；不挂就是一次性任务，行为与从前一致。
 */
abstract class Agent extends Instance
{
    /** 挂上会话即进入多轮模式；为 null 时每次 run() 都是独立的一次性任务 */
    private ?Conversation $conversation = null;

    /** 人格定义，作为 system prompt */
    abstract public function persona(): string;

    /**
     * 本地工具列表。
     *
     * @return array<int, ToolInterface>
     */
    protected function tools(): array
    {
        return HttpTools::getInstance()->tools();
    }

    /** 指定提供商显示名；null 表示用当前配置的提供商 */
    protected function providerName(): ?string
    {
        return null;
    }

    protected function temperature(): float
    {
        return 0.7;
    }

    /** 工具调用轮数上限，防止循环失控 */
    protected function maxIterations(): int
    {
        return 50;
    }

    /**
     * 挂上一段会话，run() 就会自动接续它的历史并在收尾时存回去。
     *
     * 不挂就是一次性任务，每次 run() 各自独立——一次性 Agent（如 FileSystemAgent）
     * 不必为此写任何代码，这也是原有行为。
     */
    final public function withConversation(Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    /**
     * 每轮附加到 system prompt 末尾的工具调用预算提示，让模型知道还剩多少轮并尽量直接完成。
     * 返回空串则不提示。
     */
    protected function budgetHint(int $remaining, int $max): string
    {
        return "\n\n[工具调用预算] 本次任务最多可进行 {$max} 轮工具调用，当前还剩 {$remaining} 轮。"
            . "请用尽量少的调用直接完成任务：先想清楚步骤再动手，避免重复的探索性调用；"
            . "剩余不多时立即收尾并给出结论，不要因为用尽轮数而没有回复。";
    }

    /**
     * 执行对话。带 onChunk 回调时流式输出，回调签名 function(string $text, string $type)，
     * type ∈ {content, thinking, tool_call, tool_result}。
     *
     * @param  string|array<int, string>                     $input         用户输入
     * @param  array{onChunk?:callable,onComplete?:callable} $streamOptions
     * @return string|null                                   最终回答文本；无回答返回 null
     */
    final public function run(string|array $input, array $streamOptions = [], bool $reasoning = true): ?string
    {
        $provider = AiConfig::getInstance()->resolveProvider($this->providerName());
        if ($provider === null) {
            return null;
        }

        $registry = $this->buildRegistry();
        $tools = $registry->definitions();
        $onChunk = $streamOptions['onChunk'] ?? null;

        $max = $this->maxIterations();
        $basePersona = $this->persona();
        $messages = [['role' => 'system', 'content' => $basePersona]];
        foreach ($this->conversation?->load() ?? [] as $past) {
            $messages[] = $past;
        }
        foreach ((array)$input as $item) {
            $messages[] = ['role' => 'user', 'content' => $item];
        }

        for ($round = 0; $round < $max; $round++) {
            $messages[0]['content'] = $basePersona . $this->budgetHint($max - $round, $max);

            $options = ['temperature' => $this->temperature(),'reasoning' => $reasoning];
            if ($tools !== []) {
                $options['tools'] = $tools;
            }
            if ($onChunk !== null) {
                $options['onChunk'] = $onChunk;
            }
            if (isset($streamOptions['onComplete'])) {
                $options['onComplete'] = $streamOptions['onComplete'];
            }

            $result = $provider->chat($messages, $options);

            if ($result['tool_calls'] === []) {
                $answer = $result['content'];
                if ($answer !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $answer];
                }
                $this->remember($messages);

                return $answer !== '' ? $answer : null;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $result['content'],
                'tool_calls' => $result['tool_calls'],
            ];

            foreach ($result['tool_calls'] as $call) {
                $fn = $call['function'] ?? [];
                $name = (string)($fn['name'] ?? '');

                if (is_callable($onChunk)) {
                    $onChunk($name, 'tool_call');
                }

                $output = $registry->call($name, $this->decodeArgs((string)($fn['arguments'] ?? '')));

                if (is_callable($onChunk)) {
                    $onChunk($output, 'tool_result');
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string)($call['id'] ?? ''),
                    'content' => $output,
                ];
            }
        }

        // 轮数耗尽：序列以 tool 响应结尾，是合法的，存下来用户才能追问让它接着干
        $this->remember($messages);

        return null;
    }

    /**
     * 存回会话，去掉 system——人格每轮都会重新生成，存下来只会在下次拼出两份。
     *
     * 只在正常收尾时调用。工具抛异常的那一刻，最后一条 assistant 的 tool_calls
     * 往往还没配齐 tool 响应，把这种残缺序列存下去，下一轮加载就会让接口报错。
     * 宁可丢掉本轮记录，也要让已存的历史始终合法。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function remember(array $messages): void
    {
        $this->conversation?->save(array_slice($messages, 1));
    }

    private function buildRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry();
        // 先注册全局 MCP 工具，再注册本地工具：同名时本地为准
        foreach (Mcp::getInstance()->tools() as $tool) {
            $registry->register($tool);
        }
        foreach ($this->tools() as $tool) {
            $registry->register($tool);
        }
        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArgs(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
