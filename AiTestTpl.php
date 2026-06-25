<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\Instance;
use nova\framework\http\Request;
use nova\framework\http\Response;
use nova\plugin\login\AdminPageInterface;
use nova\plugin\tpl\ViewResponse;

class AiTestTpl extends Instance implements AdminPageInterface
{
    public function registerRouter(string $model, string $controller): void
    {
        $default = \nova\framework\route($model, $controller, 'init');
        \nova\framework\route\Route::getInstance()
            ->get('/ai/test', $default);
    }

    public function route(ViewResponse $viewResponse, Request $request): ?Response
    {
        $parts = explode('/', $request->getPath());
        if (count($parts) !== 3 || $parts[2] !== 'test') {
            return null;
        }

        return $viewResponse->asTpl(ROOT_PATH . DS . 'nova/plugin/ai/tpl/test');
    }

    public function menu(): array
    {
        return [
            'title' => 'AI 测试',
            'icon' => 'science',
            'url' => '/ai/test',
            'pjax' => true,
        ];
    }
}
