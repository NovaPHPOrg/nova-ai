<?php

declare(strict_types=1);

namespace nova\plugin\ai\task;

use nova\framework\core\Instance;
use nova\framework\core\Logger;
use nova\plugin\ai\agent\Agent;

/**
 * AI 任务基类：prompt → Agent function-calling → JSON 提取 → 白名单过滤。
 *
 * 子类定义 agent()、allowedFields()、prompt 构造逻辑，
 * 调用 execute() 即可拿到结构化结果。
 */
abstract class AiTask extends Instance
{
    abstract protected function agent(): Agent;

    /** @return string[] 允许输出的字段名 */
    abstract protected function allowedFields(): array;

    /** @return array<string, string> 工具名 → 日志标签 */
    protected function toolLabels(): array
    {
        return [];
    }

    /** @param array<string, mixed> $out */
    protected function summarize(array $out): string
    {
        return '已获取数据';
    }

    /**
     * 业务后处理：校验枚举、丢弃非法值等。
     *
     * @param  array<string, mixed> $out
     * @return array<string, mixed>
     */
    protected function postProcess(array $out): array
    {
        return $out;
    }

    /**
     * 执行 AI 调用管道：Agent::run → JSON 提取 → 白名单过滤 → 后处理。
     *
     * @param  callable|null             $onProgress function(string $msg): void
     * @return array<string, mixed>|null
     */
    final protected function execute(string $prompt, ?callable $onProgress = null): ?array
    {
        $labels = $this->toolLabels();
        $thinking = '';
        $step = 0;

        try {
            $answer = $this->agent()->run($prompt, [
                'onChunk' => function (string $text, string $type) use ($onProgress, $labels, &$thinking, &$step): void {
                    if ($onProgress === null) {
                        return;
                    }
                    switch ($type) {
                        case 'thinking':
                            $thinking .= $text;
                            break;
                        case 'tool_call':
                            if ($thinking !== '') {
                                $onProgress('[思考] ' . self::clip($thinking));
                                $thinking = '';
                            }
                            $step++;
                            $label = $labels[$text] ?? ($text !== '' ? ('调用 ' . $text) : '调用工具');
                            $onProgress("[第{$step}步] {$label}");
                            break;
                        case 'tool_result':
                            $onProgress('[结果] ' . self::clip($text));
                            break;
                    }
                },
            ]);
        } catch (\Throwable $e) {
            Logger::error('[' . static::class . '] AI 调用异常：' . $e->getMessage());
            return null;
        }

        if ($thinking !== '' && $onProgress !== null) {
            $onProgress('[思考] ' . self::clip($thinking));
        }

        if ($answer === null || trim($answer) === '') {
            Logger::warning('[' . static::class . '] AI 无返回（请检查 AI 提供商是否已正确配置）');
            return null;
        }

        $data = self::extractJson($answer);
        if ($data === null) {
            Logger::warning('[' . static::class . '] AI 返回内容无法解析出 JSON：' . $answer);
            return null;
        }

        $out = [];
        foreach ($this->allowedFields() as $field) {
            $value = $data[$field] ?? '';
            if (is_array($value)) {
                $value = implode("\n", array_map(static fn ($v): string => trim((string)$v), $value));
            }
            $value = trim((string)$value);
            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        $out = $this->postProcess($out);
        if ($out === []) {
            return null;
        }

        if ($onProgress !== null) {
            $onProgress('[结论] ' . $this->summarize($out));
        }
        return $out;
    }

    private static function clip(string $text, int $max = 200): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > $max ? (mb_substr($text, 0, $max) . '…') : $text;
    }

    /** @return array<string, mixed>|null */
    private static function extractJson(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
