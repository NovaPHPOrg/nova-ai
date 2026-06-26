<?php

declare(strict_types=1);

namespace nova\plugin\ai\tool;

use nova\framework\core\Instance;
use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpResponse;

/**
 * 网络抓取工具（带 SSRF 防护）。
 *
 * 让 AI 拉取公网网页/接口；只允许 http(s)，解析后的 IP 若为内网/环回/保留地址一律拒绝，
 * 并限制超时与响应体大小。这是「让 AI 在 bash 里 curl」无法提供的安全层。
 */
class HttpTools extends Instance
{
    private const int TIMEOUT = 20;
    private const int MAX_BODY = 16000;

    /**
     * @return array<int, ToolInterface>
     */
    public function tools(): array
    {
        return [
            new CallableTool(
                'fetch_url',
                'Fetch a public http(s) URL and return status, content-type and a truncated body. Private/loopback hosts are blocked.',
                ['type' => 'object', 'properties' => [
                    'url' => ['type' => 'string', 'description' => 'Absolute http(s) URL.'],
                ], 'required' => ['url']],
                $this->fetch(...)
            ),
        ];
    }

    /** @param array<string,mixed> $a */
    private function fetch(array $a): string
    {
        $url = $a['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException('missing argument: url');
        }
        $this->guard($url);

        $resp = HttpClient::init()
            ->timeout(self::TIMEOUT)
            ->gzip()
            ->setHeader('User-Agent', 'NovaBookAI/1.0')
            ->get()
            ->send($url);

        if (!$resp instanceof HttpResponse) {
            return 'request failed (no response)';
        }

        $body = $resp->getBody();
        if (strlen($body) > self::MAX_BODY) {
            $body = substr($body, 0, self::MAX_BODY) . "\n... (truncated)";
        }

        $type = $this->contentType($resp);

        return "HTTP {$resp->getHttpCode()}"
            . ($type !== '' ? "\nContent-Type: {$type}" : '')
            . "\n\n" . $body;
    }

    private function contentType(HttpResponse $resp): string
    {
        foreach ($resp->getHeaders() as $k => $v) {
            if (strcasecmp((string)$k, 'content-type') === 0) {
                return is_array($v) ? implode(',', $v) : (string)$v;
            }
        }
        return '';
    }

    /** SSRF 防护：仅 http/https，且解析后的 IP 不得为内网/环回/保留地址 */
    private function guard(string $url): void
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = $p['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('only absolute http(s) URLs are allowed');
        }

        $host = trim($host, '[]'); // 去掉 IPv6 字面量的方括号
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        // gethostbyname 解析失败会原样返回主机名 → filter_var 失败 → 拒绝
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \RuntimeException("blocked host (private/loopback/unresolved): {$host}");
        }
    }
}
