<?php

declare(strict_types=1);

namespace nova\plugin\ai\controller;

use nova\framework\http\Response;
use nova\plugin\ai\mcp\McpClient;
use nova\plugin\ai\McpConfig;
use nova\plugin\login\controller\BaseAPIController;

class Mcp extends BaseAPIController
{
    public function config(): Response
    {
        $cfg = McpConfig::getInstance();

        if ($this->request->isGet()) {
            return Response::asJson([
                'code' => 200,
                'data' => $cfg->formData(),
            ]);
        }

        $cfg->applyForm($this->request->post());

        return Response::asJson([
            'code' => 200,
            'msg' => '保存成功',
        ]);
    }

    /** 逐个连通表单里的 URL，回报每个 server 发现的工具名（保存前即可验证） */
    public function test(): Response
    {
        $data = [];
        foreach (McpConfig::parse((string)$this->request->post('servers', '')) as $url) {
            try {
                $names = array_map(
                    static fn (array $t): string => (string)($t['name'] ?? ''),
                    (new McpClient($url))->listTools()
                );
                $data[] = ['url' => $url, 'ok' => true, 'tools' => array_values(array_filter($names))];
            } catch (\Throwable $e) {
                $data[] = ['url' => $url, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return Response::asJson(['code' => 200, 'data' => $data]);
    }
}
