<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\StaticRegister;
use nova\framework\route\RouteTrait;
use nova\plugin\login\AdminPage;
use nova\plugin\login\route\Permission;

class AiPluginManager extends StaticRegister
{
    use RouteTrait;

    public function __construct()
    {
        $this->controllerNamespace = 'nova\\plugin\\ai\\controller\\';
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $this->getOrPost('/ai/api/config', $this->map('config', 'config'));
        $this->post('/ai/api/config/models', $this->map('config', 'models'));
        $this->post('/ai/api/config/search', $this->map('config', 'search'));
        $this->post('/ai/api/config/api', $this->map('config', 'api'));
        $this->post('/ai/api/config/url', $this->map('config', 'url'));
        $this->getOrPost('/ai/api/mcp', $this->map('mcp', 'config'));
        $this->post('/ai/api/mcp/test', $this->map('mcp', 'test'));
        $this->get('/ai/api/test/run', $this->map('test', 'run'));
    }

    public static function registerInfo(): void
    {

        Permission::getInstance()->registerPermissions('AI 配置', 'ai_manage', [
            'ANY /ai*',
        ]);

        self::getInstance()->bindPrefixDispatch('/ai');
        AdminPage::bind(AiTpl::getInstance());
    }
}
