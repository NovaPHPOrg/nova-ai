<?php

declare(strict_types=1);

namespace nova\plugin\ai\providers;

use nova\framework\core\Logger;
use nova\plugin\ai\AiResponseParser;
use nova\plugin\http\HttpException;
use nova\plugin\http\HttpResponse;

/**
 * 基于 OpenAI 风格接口的基础提供者
 *
 * - 提供通用的 getAvailableModels()/request() 实现
 * - 子类仅需覆盖名称、创建 Key 路径、默认 API 基址与默认模型名
 */
abstract class BaseOpenAIProvider extends BaseAIProvider
{
    /**
     * 获取可用模型列表
     *
     * @return array<string>
     */
    public function getAvailableModels(): array
    {
        $url = rtrim($this->getApiUri(), '/') . '/v1/models';
        $this->applyProxy();

        try {
            $response = $this->http
                ->setHeader('Authorization', 'Bearer ' . $this->getApiKey())
                ->setHeader('Accept', 'application/json')
                ->get()
                ->send($url);

            if (!$response instanceof HttpResponse) {
                return [];
            }

            $models = [];
            foreach ((array)($this->jsonDecode($response->getBody())['data'] ?? []) as $item) {
                if (isset($item['id']) && is_string($item['id'])) {
                    $models[] = $item['id'];
                }
            }

            return $models;
        } catch (\RuntimeException $e) {
            Logger::error($e->getMessage(), $e->getTrace());
            return [];
        }
    }

    /**
     * 发送对话请求。
     * 含 onChunk/onComplete 回调时走流式（SSE 实时解析 content/thinking，并累积 tool_calls）；
     * 否则非流式，一次性解析 message。
     *
     * @param  array<int, array<string, mixed>>                                                       $messages
     * @param  array<string, mixed>                                                                   $options
     * @return array{content:string, tool_calls:array<int,array<string,mixed>>, finish_reason:string}
     */
    public function chat(array $messages, array $options = []): array
    {
        $url = rtrim($this->getApiUri(), '/') . '/v1/chat/completions';

        $payload = [
            'model'       => $this->getModel(),
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
        ];
        if (!empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $this->applyProxy();
        $this->http
            ->setHeader('Authorization', 'Bearer ' . $this->getApiKey())
            ->setHeader('Content-Type', 'application/json');

        if (isset($options['onChunk']) || isset($options['onComplete'])) {
            return $this->streamChat($url, $payload, $options);
        }

        return $this->blockingChat($url, $payload);
    }

    /**
     * @param  array<string, mixed>                                                                   $payload
     * @param  array<string, mixed>                                                                   $options
     * @return array{content:string, tool_calls:array<int,array<string,mixed>>, finish_reason:string}
     */
    private function streamChat(string $url, array $payload, array $options): array
    {
        $payload['stream'] = true;

        $parser = new AiResponseParser();
        $content = '';
        $userOnChunk = $options['onChunk'] ?? null;

        $send = function (string $text, string $type) use (&$content, $userOnChunk): void {
            if ($type === 'content') {
                $content .= $text;
            }
            if (is_callable($userOnChunk)) {
                $userOnChunk($text, $type);
            }
        };

        $this->http
            ->setHeader('Accept', 'text/event-stream')
            ->post($this->jsonEncode($payload), 'json')
            ->stream(
                $url,
                [],
                static fn (string $chunk) => $parser->processSSEBuffer($chunk, $send),
                $options['onComplete'] ?? null
            );

        return [
            'content'       => $content,
            'tool_calls'    => $parser->getToolCalls(),
            'finish_reason' => $parser->getFinishReason(),
        ];
    }

    /**
     * @param  array<string, mixed>                                                                   $payload
     * @return array{content:string, tool_calls:array<int,array<string,mixed>>, finish_reason:string}
     */
    private function blockingChat(string $url, array $payload): array
    {
        $empty = ['content' => '', 'tool_calls' => [], 'finish_reason' => ''];

        try {
            $response = $this->http
                ->setHeader('Accept', 'application/json')
                ->post($this->jsonEncode($payload), 'json')
                ->send($url);

            if (!$response instanceof HttpResponse) {
                return $empty;
            }

            $choice = $this->jsonDecode($response->getBody())['choices'][0] ?? [];
            $message = $choice['message'] ?? [];

            return [
                'content'       => (string)($message['content'] ?? ''),
                'tool_calls'    => is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [],
                'finish_reason' => (string)($choice['finish_reason'] ?? ''),
            ];
        } catch (HttpException|\Throwable $e) {
            return $empty;
        }
    }

    /** 有配置代理时应用到 HTTP 客户端 */
    private function applyProxy(): void
    {
        $proxy = $this->getProxy();
        if ($proxy !== '') {
            $this->http->proxy($proxy);
        }
    }
}
