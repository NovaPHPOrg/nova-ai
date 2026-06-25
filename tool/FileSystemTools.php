<?php

declare(strict_types=1);

namespace nova\plugin\ai\tool;

use nova\framework\core\Instance;

/**
 * 文件系统工具集（带作用范围限制）。
 *
 * 所有操作都被限制在构造时给定的 root 目录内：用户传入的任意路径都会被规范化
 * 并校验是否越界（防止 ../ 路径穿越）。唯一的安全逻辑集中在 resolve()，所有工具共用。
 *
 * 用法：
 *   $agent->tools() => (new FileSystemTools('/data/workspace'))->tools();
 *
 * 工具内部出错直接抛异常，由 ToolRegistry 捕获为文本反馈给模型。
 */
class FileSystemTools extends Instance
{
    /** 单次列举/搜索/树的结果条数上限，避免撑爆上下文 */
    private const int MAX_RESULTS = 1000;

    /** 规范化后的沙箱根（绝对路径，*nix 以 / 开头） */
    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("FileSystemTools root not found: {$root}");
        }
        $this->root = str_replace('\\', '/', $real);
    }

    /**
     * @return array<int, ToolInterface>
     */
    public function tools(): array
    {
        $str = ['type' => 'string'];
        $strArr = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            new CallableTool(
                'read_file',
                'Read the full text content of a single file.',
                self::schema(['path' => $str + ['description' => 'File path relative to the workspace root.']], ['path']),
                $this->readFile(...)
            ),
            new CallableTool(
                'read_multiple_files',
                'Read several files at once; each result is prefixed with its path.',
                self::schema(['paths' => $strArr + ['description' => 'List of file paths.']], ['paths']),
                $this->readMultipleFiles(...)
            ),
            new CallableTool(
                'write_file',
                'Create or overwrite a file with the given content (parent dirs auto-created).',
                self::schema(['path' => $str, 'content' => $str], ['path', 'content']),
                $this->writeFile(...)
            ),
            new CallableTool(
                'edit_file',
                'Replace all occurrences of old_string with new_string in a file.',
                self::schema(['path' => $str, 'old_string' => $str, 'new_string' => $str], ['path', 'old_string', 'new_string']),
                $this->editFile(...)
            ),
            new CallableTool(
                'append_file',
                'Append content to the end of a file (created if missing).',
                self::schema(['path' => $str, 'content' => $str], ['path', 'content']),
                $this->appendFile(...)
            ),
            new CallableTool(
                'delete_file',
                'Delete a single file.',
                self::schema(['path' => $str], ['path']),
                $this->deleteFile(...)
            ),
            new CallableTool(
                'move_file',
                'Move a file or directory to a new location.',
                self::schema(['source' => $str, 'destination' => $str], ['source', 'destination']),
                $this->moveFile(...)
            ),
            new CallableTool(
                'copy_file',
                'Copy a single file to a new location.',
                self::schema(['source' => $str, 'destination' => $str], ['source', 'destination']),
                $this->copyFile(...)
            ),
            new CallableTool(
                'rename_file',
                'Rename a file or directory in place (new_name is a bare name, no slashes).',
                self::schema(['path' => $str, 'new_name' => $str], ['path', 'new_name']),
                $this->renameFile(...)
            ),
            new CallableTool(
                'list_directory',
                'List entries of a directory (defaults to the root).',
                self::schema(['path' => $str + ['description' => 'Directory path; omit for root.']]),
                $this->listDirectory(...)
            ),
            new CallableTool(
                'create_directory',
                'Create a directory (recursively).',
                self::schema(['path' => $str], ['path']),
                $this->createDirectory(...)
            ),
            new CallableTool(
                'search_files',
                'Recursively find files/dirs whose name contains the given substring (case-insensitive).',
                self::schema(['pattern' => $str, 'path' => $str + ['description' => 'Start dir; omit for root.']], ['pattern']),
                $this->searchFiles(...)
            ),
            new CallableTool(
                'glob_search',
                'Recursively find paths matching a glob pattern, e.g. "*.php" or "src/*.js".',
                self::schema(['pattern' => $str, 'path' => $str + ['description' => 'Start dir; omit for root.']], ['pattern']),
                $this->globSearch(...)
            ),
            new CallableTool(
                'tree',
                'Print a directory tree up to max_depth (default 3).',
                self::schema([
                    'path' => $str + ['description' => 'Directory; omit for root.'],
                    'max_depth' => ['type' => 'integer', 'description' => 'Max depth, default 3.'],
                ]),
                $this->tree(...)
            ),
            new CallableTool(
                'get_file_info',
                'Get metadata of a file or directory: type, size, mtime, permissions.',
                self::schema(['path' => $str], ['path']),
                $this->getFileInfo(...)
            ),
        ];
    }

    // ---------------------------------------------------------------- 工具实现

    /** @param array<string,mixed> $a */
    private function readFile(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        if (!is_file($abs)) {
            throw new \RuntimeException('not a file: ' . $this->rel($abs));
        }
        $content = file_get_contents($abs);
        if ($content === false) {
            throw new \RuntimeException('read failed: ' . $this->rel($abs));
        }
        return $content;
    }

    /** @param array<string,mixed> $a */
    private function readMultipleFiles(array $a): string
    {
        $paths = $a['paths'] ?? null;
        if (!is_array($paths) || $paths === []) {
            throw new \RuntimeException('missing argument: paths');
        }

        $out = [];
        foreach ($paths as $p) {
            if (!is_string($p)) {
                continue;
            }
            try {
                $abs = $this->resolve($p);
                if (!is_file($abs)) {
                    throw new \RuntimeException('not a file');
                }
                $out[] = "===== {$this->rel($abs)} =====\n" . file_get_contents($abs);
            } catch (\Throwable $e) {
                $out[] = "===== {$p} =====\nError: " . $e->getMessage();
            }
        }
        return implode("\n\n", $out);
    }

    /** @param array<string,mixed> $a */
    private function writeFile(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        $content = $this->optStr($a, 'content');
        $this->ensureParent($abs);
        if (file_put_contents($abs, $content) === false) {
            throw new \RuntimeException('write failed: ' . $this->rel($abs));
        }
        return 'wrote ' . strlen($content) . ' bytes to ' . $this->rel($abs);
    }

    /** @param array<string,mixed> $a */
    private function editFile(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        $old = $this->reqStr($a, 'old_string');
        $new = $this->optStr($a, 'new_string');
        if (!is_file($abs)) {
            throw new \RuntimeException('not a file: ' . $this->rel($abs));
        }
        $content = (string)file_get_contents($abs);
        $count = substr_count($content, $old);
        if ($count === 0) {
            throw new \RuntimeException('old_string not found in ' . $this->rel($abs));
        }
        file_put_contents($abs, str_replace($old, $new, $content));
        return "replaced {$count} occurrence(s) in " . $this->rel($abs);
    }

    /** @param array<string,mixed> $a */
    private function appendFile(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        $content = $this->optStr($a, 'content');
        $this->ensureParent($abs);
        if (file_put_contents($abs, $content, FILE_APPEND) === false) {
            throw new \RuntimeException('append failed: ' . $this->rel($abs));
        }
        return 'appended ' . strlen($content) . ' bytes to ' . $this->rel($abs);
    }

    /** @param array<string,mixed> $a */
    private function deleteFile(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        if (!is_file($abs)) {
            throw new \RuntimeException('not a file: ' . $this->rel($abs));
        }
        if (!unlink($abs)) {
            throw new \RuntimeException('delete failed: ' . $this->rel($abs));
        }
        return 'deleted ' . $this->rel($abs);
    }

    /** @param array<string,mixed> $a */
    private function moveFile(array $a): string
    {
        $src = $this->resolve($this->reqStr($a, 'source'));
        $dst = $this->resolve($this->reqStr($a, 'destination'));
        if (!file_exists($src)) {
            throw new \RuntimeException('source not found: ' . $this->rel($src));
        }
        $this->ensureParent($dst);
        if (!rename($src, $dst)) {
            throw new \RuntimeException('move failed');
        }
        return 'moved ' . $this->rel($src) . ' -> ' . $this->rel($dst);
    }

    /** @param array<string,mixed> $a */
    private function copyFile(array $a): string
    {
        $src = $this->resolve($this->reqStr($a, 'source'));
        $dst = $this->resolve($this->reqStr($a, 'destination'));
        if (!is_file($src)) {
            throw new \RuntimeException('source is not a file: ' . $this->rel($src));
        }
        $this->ensureParent($dst);
        if (!copy($src, $dst)) {
            throw new \RuntimeException('copy failed');
        }
        return 'copied ' . $this->rel($src) . ' -> ' . $this->rel($dst);
    }

    /** @param array<string,mixed> $a */
    private function renameFile(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        $name = $this->reqStr($a, 'new_name');
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \RuntimeException('new_name must be a bare filename without slashes');
        }
        if (!file_exists($abs)) {
            throw new \RuntimeException('not found: ' . $this->rel($abs));
        }
        $dst = dirname($abs) . '/' . $name;
        if (!rename($abs, $dst)) {
            throw new \RuntimeException('rename failed');
        }
        return 'renamed ' . $this->rel($abs) . ' -> ' . $this->rel($dst);
    }

    /** @param array<string,mixed> $a */
    private function listDirectory(array $a): string
    {
        $abs = $this->resolve($this->optStr($a, 'path', '.'));
        if (!is_dir($abs)) {
            throw new \RuntimeException('not a directory: ' . $this->rel($abs));
        }
        $entries = scandir($abs) ?: [];
        $lines = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $lines[] = (is_dir($abs . '/' . $e) ? '[DIR]  ' : '[FILE] ') . $e;
        }
        sort($lines);
        return $lines === [] ? '(empty)' : implode("\n", $lines);
    }

    /** @param array<string,mixed> $a */
    private function createDirectory(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        if (is_dir($abs)) {
            return 'already exists: ' . $this->rel($abs);
        }
        if (!mkdir($abs, 0777, true) && !is_dir($abs)) {
            throw new \RuntimeException('create directory failed: ' . $this->rel($abs));
        }
        return 'created ' . $this->rel($abs);
    }

    /** @param array<string,mixed> $a */
    private function searchFiles(array $a): string
    {
        $pattern = $this->reqStr($a, 'pattern');
        $base = $this->resolve($this->optStr($a, 'path', '.'));

        $matches = [];
        foreach ($this->walk($base) as $abs) {
            if (stripos(basename($abs), $pattern) !== false) {
                $matches[] = $this->rel($abs);
                if (count($matches) >= self::MAX_RESULTS) {
                    break;
                }
            }
        }
        return $matches === [] ? '(no matches)' : implode("\n", $matches);
    }

    /** @param array<string,mixed> $a */
    private function globSearch(array $a): string
    {
        $pattern = $this->reqStr($a, 'pattern');
        $base = $this->resolve($this->optStr($a, 'path', '.'));
        $baseLen = strlen($base) + 1;

        $matches = [];
        foreach ($this->walk($base) as $abs) {
            $relToBase = substr($abs, $baseLen);
            if (fnmatch($pattern, $relToBase) || fnmatch($pattern, basename($abs))) {
                $matches[] = $this->rel($abs);
                if (count($matches) >= self::MAX_RESULTS) {
                    break;
                }
            }
        }
        return $matches === [] ? '(no matches)' : implode("\n", $matches);
    }

    /** @param array<string,mixed> $a */
    private function tree(array $a): string
    {
        $abs = $this->resolve($this->optStr($a, 'path', '.'));
        if (!is_dir($abs)) {
            throw new \RuntimeException('not a directory: ' . $this->rel($abs));
        }
        $maxDepth = max(1, $this->optInt($a, 'max_depth', 3));

        $lines = [$this->rel($abs)];
        $count = 0;
        $this->buildTree($abs, '', 1, $maxDepth, $lines, $count);
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $a */
    private function getFileInfo(array $a): string
    {
        $abs = $this->resolve($this->reqStr($a, 'path'));
        if (!file_exists($abs)) {
            throw new \RuntimeException('not found: ' . $this->rel($abs));
        }
        $type = is_dir($abs) ? 'directory' : (is_file($abs) ? 'file' : 'other');
        $info = [
            'path: ' . $this->rel($abs),
            'type: ' . $type,
            'size: ' . (is_file($abs) ? (string)filesize($abs) : '0') . ' bytes',
            'modified: ' . date('Y-m-d H:i:s', (int)filemtime($abs)),
            'permissions: ' . substr(sprintf('%o', fileperms($abs)), -4),
            'readable: ' . (is_readable($abs) ? 'yes' : 'no'),
            'writable: ' . (is_writable($abs) ? 'yes' : 'no'),
        ];
        return implode("\n", $info);
    }

    // ---------------------------------------------------------------- 内部工具

    /**
     * 把用户路径解析为沙箱内的绝对路径，越界则抛异常。这是唯一的安全边界。
     */
    private function resolve(string $path): string
    {
        if ($path === '') {
            throw new \RuntimeException('empty path');
        }
        $path = str_replace('\\', '/', $path);
        $full = str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
        $norm = $this->normalize($full);

        if ($norm !== $this->root && !str_starts_with($norm, $this->root . '/')) {
            throw new \RuntimeException("path escapes workspace: {$path}");
        }
        return $norm;
    }

    /** 纯字符串规范化（处理 . 与 ..），不依赖文件是否存在 */
    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    /** 绝对路径转回相对 root 的展示路径 */
    private function rel(string $abs): string
    {
        $r = ltrim(substr($abs, strlen($this->root)), '/');
        return $r === '' ? '.' : $r;
    }

    private function ensureParent(string $abs): void
    {
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create directory: ' . $this->rel($dir));
        }
    }

    /**
     * 递归遍历目录，产出每个条目的绝对路径。
     *
     * @return \Generator<int, string>
     */
    private function walk(string $base): \Generator
    {
        if (!is_dir($base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            yield str_replace('\\', '/', $item->getPathname());
        }
    }

    /**
     * @param array<int, string> $lines
     */
    private function buildTree(string $dir, string $prefix, int $depth, int $maxDepth, array &$lines, int &$count): void
    {
        if ($depth > $maxDepth || $count >= self::MAX_RESULTS) {
            return;
        }
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);
        $last = count($entries) - 1;

        foreach ($entries as $i => $name) {
            if ($count >= self::MAX_RESULTS) {
                $lines[] = $prefix . '... (truncated)';
                return;
            }
            $full = $dir . '/' . $name;
            $isDir = is_dir($full);
            $branch = $i === $last ? '└── ' : '├── ';
            $lines[] = $prefix . $branch . $name . ($isDir ? '/' : '');
            $count++;

            if ($isDir) {
                $this->buildTree($full, $prefix . ($i === $last ? '    ' : '│   '), $depth + 1, $maxDepth, $lines, $count);
            }
        }
    }

    /**
     * @param  array<string, mixed> $props
     * @param  array<int, string>   $required
     * @return array<string, mixed>
     */
    private static function schema(array $props, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $props, 'required' => $required];
    }

    /** @param array<string,mixed> $a */
    private function reqStr(array $a, string $key): string
    {
        $v = $a[$key] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException("missing argument: {$key}");
        }
        return $v;
    }

    /** @param array<string,mixed> $a */
    private function optStr(array $a, string $key, string $default = ''): string
    {
        $v = $a[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @param array<string,mixed> $a */
    private function optInt(array $a, string $key, int $default): int
    {
        $v = $a[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int)$v;
        }
        return $default;
    }
}
