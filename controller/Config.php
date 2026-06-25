<?php

declare(strict_types=1);

namespace nova\plugin\ai\controller;

use nova\framework\http\Response;
use nova\plugin\ai\AiConfig;
use nova\plugin\login\controller\BaseAPIController;

class Config extends BaseAPIController
{
    public function config(): Response
    {
        $cfg = AiConfig::getInstance();

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

    public function models(): Response
    {
        $cfg = AiConfig::getInstance();

        return Response::asJson([
            'code' => 200,
            'data' => $cfg->fetchModels(
                $this->request->post('provider', ''),
                $this->request->post('api_key', ''),
                $this->request->post('api_url', ''),
                $this->request->post('proxy', ''),
            ),
        ]);
    }

    public function search(): Response
    {
        $cfg = AiConfig::getInstance();

        return Response::asJson([
            'code' => 200,
            'data' => $cfg->searchModels(trim((string)$this->request->post('keyword', ''))),
        ]);
    }

    public function api(): Response
    {
        $provider = AiConfig::getInstance()->resolveProvider($this->request->post('provider', ''));

        return Response::asJson([
            'code' => 200,
            'data' => ['api_url' => $provider?->getApiUri() ?? ''],
        ]);
    }

    public function url(): Response
    {
        $provider = AiConfig::getInstance()->resolveProvider($this->request->post('provider', ''));

        return Response::asJson([
            'code' => 200,
            'data' => ['createKeyUri' => $provider?->getCreateKeyUri() ?? ''],
        ]);
    }
}
