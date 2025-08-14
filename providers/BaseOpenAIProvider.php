<?php

declare(strict_types=1);

namespace nova\plugin\ai\providers;

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
        $apiBase = rtrim($this->getApiUri() ?: $this->getDefaultApiUri(), '/');
        $url     = $apiBase . '/v1/models';

        try {
            $proxy = $this->getProxy();
            if ($proxy !== '') {
                $this->http->proxy($proxy);
            }
            $response = $this->http
                ->setHeader('Authorization', 'Bearer ' . $this->getApiKey())
                ->setHeader('Accept', 'application/json')
                ->get()
                ->send($url);

            if (!$response instanceof HttpResponse) {
                return [];
            }

            $body = $response->getBody();
            $json = $this->jsonDecode($body);

            $models = [];
            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                foreach ($json['data'] as $item) {
                    if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                        $models[] = $item['id'];
                    }
                }
            }

            return $models;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 发送对话请求
     * 如果 $options 包含流式回调，则使用 HttpClient::stream 推送分片并返回 null
     */
    public function request(string $system, string $user, array $options = []): ?string
    {
        $apiBase = rtrim($this->getApiUri() ?: $this->getDefaultApiUri(), '/');
        $url     = $apiBase . '/v1/chat/completions';

        $messages = [];
        if ($system !== '') {
            $messages[] = [
                'role'    => 'system',
                'content' => $system,
            ];
        }
        $messages[] = [
            'role'    => 'user',
            'content' => $user,
        ];

        $payload = [
            'model'       => $this->getModel() ?: $this->getDefaultModel(),
            'messages'    => $messages,
            'temperature' => 0.7,
        ];

        $proxy = $this->getProxy();
        if ($proxy !== '') {
            $this->http->proxy($proxy);
        }


        // 流式：存在任一回调则流式
        $hasStreamCallbacks = isset($options['onChunk']) || isset($options['onComplete']);
        if ($hasStreamCallbacks) {
            $payload['stream'] = true;

            $onChunk    = $options['onChunk']    ?? null;
            $onComplete = $options['onComplete'] ?? null;

            $proxy = $this->getProxy();
            if ($proxy !== '') {
                $this->http->proxy($proxy);
            }

            $this->http
                ->setHeader('Authorization', 'Bearer ' . $this->getApiKey())
                ->setHeader('Accept', 'text/event-stream')
                ->setHeader('Content-Type', 'application/json')
                ->post($this->jsonEncode($payload), 'json')
                ->stream(
                    $url,
                    [],
                    $onChunk,
                    $onComplete
                );
            return null;
        }

        try {

            $response = $this->http
                ->setHeader('Authorization', 'Bearer ' . $this->getApiKey())
                ->setHeader('Accept', 'application/json')
                ->post($this->jsonEncode($payload), 'json')
                ->send($url);

            if (!$response instanceof HttpResponse) {
                return null;
            }

            $body = $response->getBody();
            $json = $this->jsonDecode($body);

            if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
                return null;
            }

            $content = (string)$json['choices'][0]['message']['content'];
            return $this->removeThink($content);
        } catch (HttpException|\Throwable $e) {
            return null;
        }
    }
}


