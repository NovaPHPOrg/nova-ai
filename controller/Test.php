<?php

declare(strict_types=1);

namespace nova\plugin\ai\controller;

use nova\framework\http\Response;
use nova\plugin\ai\agent\FileSystemAgent;
use nova\plugin\login\controller\BaseAPIController;

class Test extends BaseAPIController
{
    /** 测试 Agent 的工作目录 */
    private const string WORKSPACE = '/tmp';

    /**
     * 流式运行默认文件助手 Agent（处理 /tmp 目录），通过 SSE 实时推送。
     * 事件：chunk -> {type,text}（type ∈ content/thinking/tool_call/tool_result/error）；done -> [DONE]
     */
    public function run(): Response
    {
        $prompt = trim((string)$this->request->get('q', ''));

        return Response::asSSE(function (callable $emit) use ($prompt): void {
            $send = static function (string $type, string $text) use ($emit): void {
                $emit(json_encode(['type' => $type, 'text' => $text], JSON_UNESCAPED_UNICODE), 'chunk');
            };

            if ($prompt === '') {
                $send('error', '请输入内容');
                $emit('end', 'done');
                return;
            }

            $emitted = false;

            try {
                (new FileSystemAgent(self::WORKSPACE))->run($prompt, [
                    'onChunk' => function (string $text, string $type) use ($send, &$emitted): void {
                        $emitted = true;
                        $send($type, $text);
                    },
                ]);
            } catch (\Throwable $e) {
                $send('error', $e->getMessage());
                $emitted = true;
            }

            if (!$emitted) {
                $send('error', '无输出，请检查 AI 配置（API Key / 模型）是否正确。');
            }

            $emit('end', 'done');
        });
    }
}
