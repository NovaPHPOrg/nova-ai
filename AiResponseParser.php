<?php

declare(strict_types=1);

namespace nova\plugin\ai;

/**
 * AI 流式响应解析器
 *
 * 解析 OpenAI / OpenRouter 的 Chat Completions SSE 流，同时处理：
 * - 可见文本(content) 与 思考过程(reasoning)，实时通过回调输出；
 * - function-calling 的 tool_calls 分片，按 index 累加，流结束后整体产出。
 *
 * 单次流复用一个实例；跨流请先 reset()。
 */
class AiResponseParser
{
    /** SSE 缓冲区，累积跨分片的不完整数据 */
    private string $sseBuffer = '';

    /** @var array<int, array{id:string,type:string,function:array{name:string,arguments:string}}> */
    private array $toolCalls = [];

    private string $finishReason = '';

    /**
     * 解析单条 SSE payload，提取可见文本(content)或思考过程(reasoning)。
     * 仅用于外部按需解析单片；流式累积请用 processSSEBuffer()。
     *
     * @return array{content: string, type: string}
     */
    public function parseChunk(string $payload): array
    {
        $empty = ['content' => '', 'type' => 'unknown'];

        if ($payload === '' || $payload === '[DONE]') {
            return $empty;
        }

        $decoded = json_decode($payload, true);
        $delta = $decoded['choices'][0]['delta'] ?? null;
        if (!is_array($delta)) {
            return $empty;
        }

        if (!empty($delta['content'])) {
            return ['content' => $delta['content'], 'type' => 'content'];
        }
        if (!empty($delta['reasoning'])) {
            return ['content' => $delta['reasoning'], 'type' => 'thinking'];
        }

        return $empty;
    }

    /**
     * 累积分片并处理其中所有完整的 SSE 事件（以空行分隔），不完整部分留待下次。
     * 可见文本/思考过程通过 $send(string $content, string $type) 实时回调；
     * tool_calls 累积在内部，流结束后用 getToolCalls() 取回。
     */
    public function processSSEBuffer(string $chunk, callable $send): void
    {
        $this->sseBuffer .= $chunk;

        // 截取到最后一个事件分隔符（\n\n 或 \r\n\r\n）为止，剩余留在缓冲区
        if (!preg_match('/.*(?:\r?\n\r?\n)/s', $this->sseBuffer, $m)) {
            return;
        }
        $complete = $m[0];
        $this->sseBuffer = substr($this->sseBuffer, strlen($complete));

        foreach (preg_split("/\r?\n\r?\n/", $complete, -1, PREG_SPLIT_NO_EMPTY) as $event) {
            $event = trim($event);
            if (!str_starts_with($event, 'data:')) {
                continue;
            }

            $payload = trim(substr($event, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }

            $this->consume($payload, $send);
        }
    }

    /** @return array<int, array{id:string,type:string,function:array{name:string,arguments:string}}> */
    public function getToolCalls(): array
    {
        ksort($this->toolCalls);
        return array_values($this->toolCalls);
    }

    public function getFinishReason(): string
    {
        return $this->finishReason;
    }

    public function reset(): void
    {
        $this->sseBuffer = '';
        $this->toolCalls = [];
        $this->finishReason = '';
    }

    /** 解析单条已去掉 "data:" 前缀的 JSON-RPC payload */
    private function consume(string $payload, callable $send): void
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return;
        }

        $choice = $decoded['choices'][0] ?? [];
        $delta = $choice['delta'] ?? [];

        if (!empty($delta['content'])) {
            $send($delta['content'], 'content');
        }
        if (!empty($delta['reasoning'])) {
            $send($delta['reasoning'], 'thinking');
        }
        if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $this->accumulateToolCalls($delta['tool_calls']);
        }
        if (!empty($choice['finish_reason'])) {
            $this->finishReason = (string)$choice['finish_reason'];
        }
    }

    /**
     * 合并流式 tool_calls 分片：id/type/name 取首个出现值，arguments 按 index 拼接。
     *
     * @param array<int, array<string, mixed>> $deltas
     */
    private function accumulateToolCalls(array $deltas): void
    {
        foreach ($deltas as $i => $tc) {
            $index = is_int($tc['index'] ?? null) ? $tc['index'] : $i;
            $this->toolCalls[$index] ??= [
                'id' => '',
                'type' => 'function',
                'function' => ['name' => '', 'arguments' => ''],
            ];

            if (!empty($tc['id'])) {
                $this->toolCalls[$index]['id'] = (string)$tc['id'];
            }
            if (!empty($tc['type'])) {
                $this->toolCalls[$index]['type'] = (string)$tc['type'];
            }

            $fn = $tc['function'] ?? [];
            if (!empty($fn['name'])) {
                $this->toolCalls[$index]['function']['name'] = (string)$fn['name'];
            }
            if (isset($fn['arguments']) && is_string($fn['arguments'])) {
                $this->toolCalls[$index]['function']['arguments'] .= $fn['arguments'];
            }
        }
    }
}
