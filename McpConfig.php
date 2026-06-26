<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\ConfigObject;

/**
 * MCP 模块配置。
 *
 * 只持久化一组 MCP server 的 URL（仅 URL，无鉴权/无 header）。
 * 节点名由 ConfigObject 推导为 "mcp"。
 */
class McpConfig extends ConfigObject
{
    /** @var array<int, string> 已配置的 MCP server URL 列表 */
    public array $servers = [];

    /** 配置页 GET 数据：每行一个 URL */
    public function formData(): array
    {
        return ['servers' => implode("\n", $this->servers)];
    }

    /** 保存配置页表单：按行拆分，去空白、去重、只留 http(s) */
    public function applyForm(array $post): void
    {
        $this->servers = self::parse((string)($post['servers'] ?? ''));
    }

    /**
     * @return array<int, string>
     */
    public static function parse(string $text): array
    {
        $urls = array_map('trim', explode("\n", $text));
        $urls = array_filter($urls, static fn (string $u): bool => str_starts_with($u, 'http'));

        return array_values(array_unique($urls));
    }
}
