<?php

declare(strict_types=1);

namespace nova\plugin\ai\agent;

use nova\framework\core\Instance;
use nova\plugin\ai\AiConfig;
use nova\plugin\ai\tool\ToolInterface;
use nova\plugin\ai\tool\ToolRegistry;

/**
 * Agent 人格父类。
 *
 * 业务继承本类，定义人格(persona)与可用工具(tools)，
 * 调用 run() 即可跑完整的 function-calling 多轮工具调用循环。
 *
 * 子类至少实现 persona()；其余按需覆盖。
 */
abstract class Agent extends Instance
{
    /** 人格定义，作为 system prompt */
    abstract public function persona(): string;

    /**
     * 本地工具列表。
     *
     * @return array<int, ToolInterface>
     */
    protected function tools(): array
    {
        return [];
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
        return 8;
    }

    /**
     * 执行对话。带 onChunk 回调时流式输出，回调签名 function(string $text, string $type)，
     * type ∈ {content, thinking, tool_call, tool_result}。
     *
     * @param  string|array<int, string>                     $input         用户输入
     * @param  array{onChunk?:callable,onComplete?:callable} $streamOptions
     * @return string|null                                   最终回答文本；无回答返回 null
     */
    final public function run(string|array $input, array $streamOptions = []): ?string
    {
        $provider = AiConfig::getInstance()->resolveProvider($this->providerName());
        if ($provider === null) {
            return null;
        }

        $registry = $this->buildRegistry();
        $tools = $registry->definitions();
        $onChunk = $streamOptions['onChunk'] ?? null;

        $messages = [['role' => 'system', 'content' => $this->persona()]];
        foreach ((array)$input as $item) {
            $messages[] = ['role' => 'user', 'content' => $item];
        }

        for ($round = 0; $round < $this->maxIterations(); $round++) {
            $options = ['temperature' => $this->temperature()];
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
                return $result['content'] !== '' ? $result['content'] : null;
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

        return null;
    }

    private function buildRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry();
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
