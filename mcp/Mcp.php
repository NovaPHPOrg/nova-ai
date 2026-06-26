<?php

declare(strict_types=1);

namespace nova\plugin\ai\mcp;

use nova\framework\core\Context;
use nova\framework\core\Instance;
use nova\framework\core\Logger;
use nova\plugin\ai\McpConfig;
use nova\plugin\ai\tool\ToolInterface;

/**
 * 聚合所有已配置 MCP server 的工具。
 *
 * 工具「定义」缓存 5 分钟，避免每次 Agent 运行都去各 server 拉一遍 tools/list；
 * 真正的 tools/call 仍走实时网络。单个 server 不可用时跳过，不连累其它 server。
 */
class Mcp extends Instance
{
    private const int CACHE_TTL = 300;

    /**
     * @return array<int, ToolInterface>
     */
    public function tools(): array
    {
        $tools = [];
        foreach (McpConfig::getInstance()->servers as $url) {
            try {
                $client = new McpClient($url);
                foreach ($this->definitions($url, $client) as $schema) {
                    if (($schema['name'] ?? '') !== '') {
                        $tools[] = new McpTool($client, $schema);
                    }
                }
            } catch (\Throwable $e) {
                Logger::warning("MCP server unavailable: {$url} - " . $e->getMessage());
            }
        }

        return $tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(string $url, McpClient $client): array
    {
        $key = 'mcp_tools_' . md5($url);
        $cached = Context::instance()->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $defs = $client->listTools();
        Context::instance()->cache->set($key, $defs, self::CACHE_TTL);

        return $defs;
    }
}
