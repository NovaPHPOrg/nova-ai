<?php

declare(strict_types=1);

namespace nova\plugin\ai\mcp;

use nova\plugin\ai\tool\ToolInterface;

/**
 * 把一个远程 MCP 工具适配成统一的 ToolInterface。
 *
 * 定义在构造时就拿到（来自 tools/list），调用时才走网络（tools/call）。
 */
class McpTool implements ToolInterface
{
    /**
     * @param array<string, mixed> $schema MCP tools/list 中的单个工具定义
     */
    public function __construct(
        private readonly McpClient $client,
        private readonly array $schema,
    ) {
    }

    public function name(): string
    {
        return (string)($this->schema['name'] ?? '');
    }

    public function definition(): array
    {
        $parameters = $this->schema['inputSchema'] ?? null;
        if (!is_array($parameters) || $parameters === []) {
            $parameters = ['type' => 'object', 'properties' => new \stdClass()];
        }

        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => (string)($this->schema['description'] ?? ''),
                'parameters'  => $parameters,
            ],
        ];
    }

    public function call(array $arguments): string
    {
        return $this->client->callTool($this->name(), $arguments);
    }
}
