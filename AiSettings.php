<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\Context;
use nova\framework\core\StaticRegister;
use nova\framework\event\EventManager;
use nova\framework\exception\AppExitException;
use nova\framework\http\Response;
use nova\plugin\login\LoginManager;
use nova\plugin\tpl\ViewResponse;
use function nova\framework\dump;

/**
 * AI 配置页面与接口
 */
class AiSettings extends StaticRegister
{
    public static function registerInfo(): void
    {
        EventManager::addListener('route.before', function ($event, &$uri) {

            if (!str_starts_with($uri, '/admin/ai')) {
                return;
            }
            if ($redirect = self::needLogin()) {
                throw new AppExitException($redirect);
            }

            $mgr = AiManager::instance();

            // 统一 /ai/config 为表单接口：GET 获取配置，POST 保存配置
            if ($uri === '/admin/ai/api/config') {
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    throw new AppExitException(self::handleGetData($mgr));
                }
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    throw new AppExitException(self::handleSave($mgr));
                }
                throw new AppExitException(Response::asText('Method Not Allowed', [], 405));
            }

            if ($uri === '/admin/ai/api/config/models') {


                $provider = $_POST["provider"] ?? '';
                $key = $_POST["api_key"] ?? '';
                $proxy = $_POST["proxy"] ?? null;

                $models = $mgr->getAvailableModels($provider,$key,$proxy);
                throw new AppExitException(Response::asJson([
                    'code' => 200,
                    'data' => [
                        'availableModels' => $models,
                    ],
                ]));
            }
            if ($uri === '/admin/ai/api/config/api') {
                $provider = $_POST["provider"] ?? '';
                $url = $mgr->getCurrentApiUrl($provider);
                throw new AppExitException(Response::asJson([
                    'code' => 200,
                    'data' => [
                        'api_url' => $url,
                    ],
                ]));
            }
            // 实时获取创建 API Key 的链接（受当前提供者影响）
            if ($uri === '/admin/ai/api/config/url') {
                $provider = $_POST["provider"] ?? '';
                $createKeyUri = $mgr->getCreateKeyUri($provider);
                throw new AppExitException(Response::asJson([
                    'code' => 200,
                    'data' => [
                        'createKeyUri' => $createKeyUri,
                    ],
                ]));
            }


        });
    }

    private static function needLogin(): ?Response
    {
        $user = LoginManager::getInstance()->checkLogin();
        if (!$user) {
            return Response::asJson([
                'code' => 301,
                'msg'  => '/login',
            ]);
        }
        return null;
    }

    const string TPL = ROOT_PATH . DS . 'nova' . DS . 'plugin' . DS . 'ai' . DS . 'tpl' . DS.'config';



    private static function handleGetData(AiManager $mgr): Response
    {
        return Response::asJson([
            'code' => 200,
            'data' => [
                'providers'      => $mgr->getProviderNames(),
                'provider'        => $mgr->getCurrentProviderName(),
                'createKeyUri'   => $mgr->getCreateKeyUri(),
                'api_key'        => $mgr->getCurrentApiKey(),
                'api_url'        => $mgr->getCurrentApiUrl(),
                'api_model'      => $mgr->getCurrentModel(),
                'proxy'          => $mgr->getCurrentProxy(),
                'availableModels'=> [],
            ],
        ]);

    }

    private static function handleSave(AiManager $mgr): Response
    {


        $provider = $_POST['provider'] ?? null;
        $apiKey   = $_POST['api_key'] ?? null;
        $apiUrl   = $_POST['api_url'] ?? null;
        $model    = $_POST['api_model'] ?? null;
        $proxy    = $_POST['proxy'] ?? null;

        if (is_string($provider) && $provider !== '') {
            $mgr->setCurrentProvider($provider);
        }
        if (is_string($apiKey)) {
            $mgr->setCurrentApiKey($apiKey,$provider);
        }
        if (is_string($apiUrl)) {
            $mgr->setCurrentApiUrl($apiUrl,$provider);
        }
        if (is_string($model)) {
            $mgr->setCurrentModel($model,$provider);
        }
        if (is_string($proxy)) {
            $mgr->setCurrentProxy($proxy,$provider);
        }

        return Response::asJson([
            'code' => 200,
            'msg'  => '保存成功',
        ]);
    }
}


