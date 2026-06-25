<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\Instance;
use nova\framework\http\Request;
use nova\framework\http\Response;
use nova\plugin\login\AdminPageInterface;
use nova\plugin\tpl\ViewResponse;

class AiTpl extends Instance implements AdminPageInterface
{
    public function registerRouter(string $model, string $controller): void
    {
        $default = \nova\framework\route($model, $controller, 'init');
        \nova\framework\route\Route::getInstance()
            ->get('/ai/config', $default);
    }

    public function route(ViewResponse $view, Request $request): ?Response
    {
        if ($request->getPath() !== '/ai/config') {
            return null;
        }

        return $view->asTpl(ROOT_PATH . DS . 'nova/plugin/ai/tpl/config');
    }

    public function menu(): array
    {
        return [
            'title' => 'AI 配置',
            'icon' => 'smart_toy',
            'url' => '/ai/config',
            'pjax' => true,
        ];
    }
}
