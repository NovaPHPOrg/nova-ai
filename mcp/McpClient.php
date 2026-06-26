<?php

declare(strict_types=1);

namespace nova\plugin\ai\mcp;

use nova\plugin\http\HttpClient;
use RuntimeException;

/**
 * 单个 MCP server 的客户端（Streamable HTTP / JSON-RPC 2.0）。
 *
 * 一条交互链路：initialize → notifications/initialized → tools/list / tools/call。
 * 握手只做一次（首个 rpc 触发），之后复用 server 返回的 Mcp-Session-Id。
 */
class McpClient
{
    private const string PROTOCOL_VERSION = '2025-06-18';

    private int $id = 0;

    private bool $connected = false;

    private string $sessionId = '';

    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * 列出 server 暴露的工具。
     *
     * @return array<int, array<string, mixed>> 每项含 name / description / inputSchema
     */
    public function listTools(): array
    {
        $result = $this->rpc('tools/list');

        return is_array($result['tools'] ?? null) ? $result['tools'] : [];
    }

    /**
     * 调用工具，返回拍平后的文本结果。
     *
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments): string
    {
        $result = $this->rpc('tools/call', [
            'name'      => $name,
            'arguments' => empty($arguments) ? new \stdClass() : $arguments,
        ]);

        $text = $this->flattenContent($result['content'] ?? []);
        if (!empty($result['isError'])) {
            return 'Error: ' . $text;
        }

        return $text;
    }

    private function ensureConnected(): void
    {
        if ($this->connected) {
            return;
        }

        $reqId = ++$this->id;
        $resp = $this->post([
            'jsonrpc' => '2.0',
            'id'      => $reqId,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'nova-ai', 'version' => '1.0'],
            ],
        ]);

        $this->sessionId = $this->header($resp['headers'], 'mcp-session-id');
        $this->decode($resp, $reqId); // 校验握手结果，失败即抛

        // initialized 是通知，无 id、无响应体
        $this->post([
            'jsonrpc' => '2.0',
            'method'  => 'notifications/initialized',
        ]);

        // 只有完整握手后才算连上：失败时 ensureConnected 会重新走一遍
        $this->connected = true;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        try {
            return $this->doRpc($method, $params);
        } catch (RuntimeException $e) {
            // session 过期 / server 重启 / 首次调用把会话搞挂：丢弃旧连接，重握手再试一次
            if (!$this->isSessionLost($e)) {
                throw $e;
            }
            $this->connected = false;
            $this->sessionId = '';

            return $this->doRpc($method, $params);
        }
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function doRpc(string $method, array $params = []): array
    {
        $this->ensureConnected();

        $reqId = ++$this->id;
        $resp = $this->post([
            'jsonrpc' => '2.0',
            'id'      => $reqId,
            'method'  => $method,
            'params'  => empty($params) ? new \stdClass() : $params,
        ]);

        return $this->decode($resp, $reqId);
    }

    /**
     * 判断异常是否属于「会话丢失/连接抖动」——这类错误重握手后大概率能恢复。
     */
    private function isSessionLost(RuntimeException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'HTTP 404')
            || stripos($msg, 'session') !== false
            || str_contains($msg, 'invalid response');
    }

    /**
     * @param  array<string, mixed>                                       $payload
     * @return array{code:int, body:string, headers:array<string,string>}
     */
    private function post(array $payload): array
    {
        $client = HttpClient::init()
            ->timeout($this->timeout)
            ->setHeader('Accept', 'application/json, text/event-stream')
            ->setHeader('MCP-Protocol-Version', self::PROTOCOL_VERSION);

        if ($this->sessionId !== '') {
            $client->setHeader('Mcp-Session-Id', $this->sessionId);
        }

        $resp = $client->post($payload, 'json')->send($this->url);
        if ($resp === null) {
            throw new RuntimeException("MCP no response from {$this->url}");
        }

        return [
            'code'    => $resp->getHttpCode(),
            'body'    => $resp->getBody(),
            'headers' => $resp->getHeaders(),
        ];
    }

    /**
     * 解析 JSON 或 SSE 响应，返回 JSON-RPC 的 result；error 则抛出。
     *
     * @param  array{code:int, body:string, headers:array<string,string>} $resp
     * @return array<string, mixed>
     */
    private function decode(array $resp, int $reqId): array
    {
        if ($resp['code'] >= 400) {
            throw new RuntimeException("MCP HTTP {$resp['code']}: " . trim($resp['body']));
        }

        $contentType = strtolower($this->header($resp['headers'], 'content-type'));
        $message = str_contains($contentType, 'text/event-stream')
            ? $this->parseSse($resp['body'], $reqId)
            : json_decode($resp['body'], true);

        if (!is_array($message)) {
            throw new RuntimeException('MCP invalid response: ' . trim($resp['body']));
        }
        if (isset($message['error'])) {
            $err = $message['error'];
            throw new RuntimeException('MCP error: ' . ($err['message'] ?? json_encode($err)));
        }

        return is_array($message['result'] ?? null) ? $message['result'] : [];
    }

    /**
     * 从 SSE 流里挑出 id 匹配的 JSON-RPC 消息（跳过无 id 的进度通知）。
     *
     * @return array<string, mixed>|null
     */
    private function parseSse(string $body, int $reqId): ?array
    {
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = json_decode(trim(substr($line, 5)), true);
            if (is_array($json) && ($json['id'] ?? null) === $reqId) {
                return $json;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $content
     */
    private function flattenContent(mixed $content): string
    {
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            $parts[] = ($block['type'] ?? '') === 'text'
                ? (string)($block['text'] ?? '')
                : json_encode($block, JSON_UNESCAPED_UNICODE);
        }

        return trim(implode("\n", $parts));
    }

    /** @param array<string, string> $headers */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }
}
