<?php

declare(strict_types=1);

namespace nova\plugin\ai\tool;

/**
 * 工具注册表：name => ToolInterface 的映射。
 *
 * 统一本地与 MCP 工具，导出 OpenAI tools 数组并按名分发，分发时不区分工具来源。
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /**
     * 导出 OpenAI function-calling 的 tools 数组。
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(
            static fn (ToolInterface $tool): array => $tool->definition(),
            array_values($this->tools)
        );
    }

    /**
     * 按名执行工具。未知工具或执行异常都转成文本反馈给模型，避免打断工具调用循环。
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments): string
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return "Error: unknown tool '{$name}'";
        }

        try {
            return $tool->call($arguments);
        } catch (\Throwable $e) {
            return "Error: tool '{$name}' failed: " . $e->getMessage();
        }
    }
}
