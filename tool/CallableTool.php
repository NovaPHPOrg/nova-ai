<?php

declare(strict_types=1);

namespace nova\plugin\ai\tool;

/**
 * 由闭包驱动的本地工具，避免为每个工具单独建类。
 */
class CallableTool implements ToolInterface
{
    /** @var callable(array<string,mixed>):string */
    private $handler;

    /**
     * @param string               $name        工具名
     * @param string               $description 给模型看的工具说明
     * @param array<string, mixed> $parameters  JSON Schema（object 类型），空则表示无参数
     * @param callable             $handler     function(array $arguments): string
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $parameters,
        callable $handler
    ) {
        $this->handler = $handler;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->normalizeSchema($this->parameters),
            ],
        ];
    }

    public function call(array $arguments): string
    {
        return ($this->handler)($arguments);
    }

    /** 保证空参数也能序列化成合法的 JSON Schema object（{} 而非 []） */
    private function normalizeSchema(array $schema): array
    {
        if ($schema === []) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }
        return $schema;
    }
}
