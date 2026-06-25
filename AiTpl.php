<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\Instance;
use nova\framework\http\Request;
use nova\framework\http\Response;

use function nova\framework\route;

use nova\framework\route\Route;
use nova\plugin\login\AdminPageInterface;
use nova\plugin\tpl\ViewResponse;

class AiTpl extends Instance implements AdminPageInterface
{
    public function registerRouter(string $model, string $controller): void
    {
        $default = route($model, $controller, 'init');
        Route::getInstance()
            ->get('/ai/config', $default)
            ->get('/ai/test', $default);
    }

    public function route(ViewResponse $view, Request $request): ?Response
    {
        if ($request->getPath() === '/ai/config') {
            return $view->asTpl(ROOT_PATH . DS . 'nova/plugin/ai/tpl/config');
        } elseif ($request->getPath() === '/ai/test') {
            return $view->asTpl(ROOT_PATH . DS . 'nova/plugin/ai/tpl/test');
        }

        return null;
    }

    public function menu(): array
    {
        return [
            'title' => 'AI',
            'icon' => 'smart_toy',
            'sub' => [
                [
                    'title' => '配置',
                    'icon' => 'tune',
                    'url' => '/ai/config',
                    'pjax' => true,
                ],
                [
                    'title' => '测试',
                    'icon' => 'science',
                    'url' => '/ai/test',
                    'pjax' => true,
                ],
            ],
        ];
    }
}
