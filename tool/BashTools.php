<?php

declare(strict_types=1);

namespace nova\plugin\ai\tool;

use nova\framework\core\Instance;

/**
 * Bash 命令工具集。
 *
 * 在给定的工作目录(cwd)内执行 shell 命令，捕获 stdout/stderr/退出码返回给模型。
 *
 * 安全边界说明：shell 命令本质上无法像文件路径那样做硬沙箱（cd、绝对路径均可越界），
 * 这里只提供真实有效的约束——固定初始工作目录、执行超时（到点 kill）、输出长度截断。
 * 是否放行由调用方（persona 约束 + 部署环境）决定，本类不做无效的越界校验幻觉。
 */
class BashTools extends Instance
{
    /** 默认/最大超时（秒） */
    private const int DEFAULT_TIMEOUT = 30;
    private const int MAX_TIMEOUT = 300;

    /** stdout/stderr 各自保留的最大字节数，超出截断，避免撑爆模型上下文 */
    private const int MAX_OUTPUT = 16000;

    /** 工作目录（绝对路径） */
    private readonly string $cwd;

    public function __construct(string $cwd)
    {
        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("BashTools cwd not found: {$cwd}");
        }
        $this->cwd = $real;
    }

    /**
     * @return array<int, ToolInterface>
     */
    public function tools(): array
    {
        return [
            new CallableTool(
                'run_command',
                'Run a shell command via bash in the working directory and return its stdout, stderr and exit code.',
                [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => 'The shell command to execute, e.g. "ls -al" or "grep -r foo .".',
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'description' => 'Max seconds before the command is killed; default '
                                . self::DEFAULT_TIMEOUT . ', max ' . self::MAX_TIMEOUT . '.',
                        ],
                    ],
                    'required' => ['command'],
                ],
                $this->runCommand(...)
            ),
        ];
    }

    /** @param array<string,mixed> $a */
    private function runCommand(array $a): string
    {
        $command = $a['command'] ?? null;
        if (!is_string($command) || trim($command) === '') {
            throw new \RuntimeException('missing argument: command');
        }

        $timeout = $a['timeout'] ?? self::DEFAULT_TIMEOUT;
        $timeout = is_int($timeout) ? $timeout : (is_string($timeout) && ctype_digit($timeout) ? (int)$timeout : self::DEFAULT_TIMEOUT);
        $timeout = max(1, min($timeout, self::MAX_TIMEOUT));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(['bash', '-c', $command], $descriptors, $pipes, $this->cwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException('failed to start command');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $timedOut = false;
        $deadline = microtime(true) + $timeout;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                $timedOut = true;
                break;
            }
            usleep(20000);
        }

        // 收尾：清空残留管道
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $this->format($command, $stdout, $stderr, $exitCode, $timedOut, $timeout);
    }

    private function format(string $command, string $stdout, string $stderr, int $exitCode, bool $timedOut, int $timeout): string
    {
        $lines = ['$ ' . $command];
        $lines[] = $timedOut
            ? "[timed out after {$timeout}s, process killed]"
            : "[exit code: {$exitCode}]";

        $out = $this->truncate(rtrim($stdout));
        $err = $this->truncate(rtrim($stderr));

        if ($out !== '') {
            $lines[] = "--- stdout ---\n" . $out;
        }
        if ($err !== '') {
            $lines[] = "--- stderr ---\n" . $err;
        }
        if ($out === '' && $err === '') {
            $lines[] = '(no output)';
        }

        return implode("\n", $lines);
    }

    private function truncate(string $text): string
    {
        if (strlen($text) <= self::MAX_OUTPUT) {
            return $text;
        }
        return substr($text, 0, self::MAX_OUTPUT) . "\n... (truncated)";
    }
}
