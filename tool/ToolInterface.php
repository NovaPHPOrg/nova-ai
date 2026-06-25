<?php

declare(strict_types=1);

namespace nova\plugin\ai\tool;

/**
 * 工具统一抽象。
 *
 * 本地 PHP 工具与远程 MCP 工具都实现此接口，工具调用循环中无需区分来源。
 */
interface ToolInterface
{
    /** 工具名，用于 function-calling 分发，需在一次会话内唯一 */
    public function name(): string;

    /**
     * 返回 OpenAI function-calling 的工具定义。
     *
     * @return array{type:string, function:array{name:string, description:string, parameters:mixed}}
     */
    public function definition(): array;

    /**
     * 执行工具，返回供模型消费的文本结果。
     *
     * @param array<string, mixed> $arguments 模型给出的参数（已 json_decode）
     */
    public function call(array $arguments): string;
}
