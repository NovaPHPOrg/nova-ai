<?php

declare(strict_types=1);

namespace nova\plugin\ai\agent;

use nova\plugin\ai\tool\FileSystemTools;
use nova\plugin\ai\tool\ToolInterface;

/**
 * 默认文件助手 Agent：在限定的 root 目录内读写文件。
 */
class FileSystemAgent extends Agent
{
    public function __construct(private readonly string $root)
    {
    }

    public function persona(): string
    {
        return <<<TXT
            你是一个文件系统助手，只能操作工作目录「{$this->root}」内的文件，所有路径都相对该目录。
            你可以读取、创建、修改、移动、删除文件，以及列目录、搜索、查看目录树。
            根据用户需求选择合适的工具完成任务，操作完成后用简洁中文说明你做了什么。
            禁止尝试访问工作目录之外的路径。
            TXT;
    }

    /**
     * @return array<int, ToolInterface>
     */
    protected function tools(): array
    {
        return FileSystemTools::getInstance($this->root)->tools();
    }
}
