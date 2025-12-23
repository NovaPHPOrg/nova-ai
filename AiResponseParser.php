<?php

declare(strict_types=1);

namespace nova\plugin\ai;

/**
 * AI响应解析器
 * 用于解析不同AI厂商的流式响应格式
 */
class AiResponseParser
{
    /**
     * SSE缓冲区，用于累积不完整的数据块
     */
    private string $sseBuffer = '';

    /**
     * 解析SSE数据块，提取思考过程和可见文本
     */
    public function parseChunk(string $payload): array
    {

        $result = [
            'content' => '',
            'type' => 'unknown'
        ];

        if ($payload === '' || $payload === '[DONE]') {
            return $result;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $result;
        }

        // {
        //    "id": "gen-1755147565-0ZSd3QOT6JSpDk5OV1Tl",
        //    "provider": "Chutes",
        //    "model": "qwen/qwen3-14b:free",
        //    "object": "chat.completion.chunk",
        //    "created": 1755147565,
        //    "choices": [
        //        {
        //            "index": 0,
        //            "delta": {
        //                "role": "assistant",
        //                "content": "",
        //                "reasoning": "\n",
        //                "reasoning_details": [
        //
        //                ]
        //            },
        //            "finish_reason": null,
        //            "native_finish_reason": null,
        //            "logprobs": null
        //        }
        //    ]
        //}

        // {
        //    "id": "gen-1755147565-0ZSd3QOT6JSpDk5OV1Tl",
        //    "provider": "Chutes",
        //    "model": "qwen/qwen3-14b:free",
        //    "object": "chat.completion.chunk",
        //    "created": 1755147565,
        //    "choices": [
        //        {
        //            "index": 0,
        //            "delta": {
        //                "role": "assistant",
        //                "content": "的",
        //                "reasoning": null,
        //                "reasoning_details": [
        //
        //                ]
        //            },
        //            "finish_reason": null,
        //            "native_finish_reason": null,
        //            "logprobs": null
        //        }
        //    ]
        //}

        // 1. OpenAI/OpenRouter Chat Completions格式
        if (isset($decoded['choices'][0]['delta'])) {
            return $this->parseOpenAIFormat($decoded, $result);
        }

        return $result;
    }

    /**
     * 解析OpenAI格式的响应
     */
    private function parseOpenAIFormat(array $decoded, array $result): array
    {
        $delta = $decoded['choices'][0]['delta'];
        if (!is_array($delta)) {
            return $result;
        }

        // 处理content字段
        if (!empty($delta['content'])) {
            $result['content'] = $delta['content'];
            $result['type'] = 'content';
            return $result;
        }

        // 处理reasoning字段（思考过程）
        if (!empty($delta['reasoning'])) {
            $result['content'] = $delta['reasoning'];
            $result['type'] = 'thinking';
        }

        return $result;
    }

    public function processSSEBuffer(string $chunk, callable $send): void
    {
        // 累积数据到缓冲区
        $this->sseBuffer .= $chunk;

        // 找到最后一个完整的SSE事件结束位置（\r\n\r\n 或 \n\n）
        $lastCompletePosCRLF = strrpos($this->sseBuffer, "\r\n\r\n");
        $lastCompletePosNL = strrpos($this->sseBuffer, "\n\n");

        // 计算分隔符后的位置
        $posCRLF = $lastCompletePosCRLF !== false ? $lastCompletePosCRLF + 4 : false;
        $posNL = $lastCompletePosNL !== false ? $lastCompletePosNL + 2 : false;

        // 取更靠后的位置（如果有的话）
        $lastCompletePos = false;
        if ($posCRLF !== false && $posNL !== false) {
            $lastCompletePos = max($posCRLF, $posNL);
        } elseif ($posCRLF !== false) {
            $lastCompletePos = $posCRLF;
        } elseif ($posNL !== false) {
            $lastCompletePos = $posNL;
        }

        if ($lastCompletePos === false) {
            // 没有完整的事件，等待更多数据
            return;
        }

        // 提取所有完整的事件
        $completeData = substr($this->sseBuffer, 0, $lastCompletePos);
        // 保留剩余的不完整部分
        $this->sseBuffer = substr($this->sseBuffer, $lastCompletePos);

        // 处理完整的SSE事件
        $buffers = preg_split("/\r?\n\r?\n/", $completeData, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($buffers as $buffer) {
            $buffer = trim($buffer);
            if ($buffer === '') {
                continue;
            }

            if (str_starts_with($buffer, 'data:')) {
                $payload = trim(substr($buffer, 5));
                if ($payload === '' || $payload === '[DONE]') {
                    $this->sseBuffer = '';
                    return;
                }

                $parsed = $this->parseChunk($payload);
                if ($parsed['content'] !== '') {
                    $content = str_replace(["\r\n", "\n", "\r"], "\\n", $parsed['content']);
                    $send($content, $parsed['type']);
                }
            }
        }
    }
}
