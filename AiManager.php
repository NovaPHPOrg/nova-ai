<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\Context;
use nova\plugin\ai\providers\BaseAIProvider;
// 不再直接依赖具体 Provider 类；通过类名动态创建

/**
 * AI 管理器
 * - 管理 AI 提供者的选择与配置（api_key / api_url / api_model）
 * - 提供统一的对话与模型获取接口
 */
class AiManager
{
    /** 默认提供者显示名 */
    private string $defaultProvider = 'ChatGPT';

    /** 显示名到内部代号的映射（用于配置存储路径） */
    private array $displayToCode = [
        'ChatGPT'    => 'chatgpt',
        'OpenRouter' => 'openrouter',
    ];

    /**
     * 获取全部提供者显示名
     *
     * @return array<string>
     */
    public function getProviderNames(): array
    {
        return array_keys($this->displayToCode);
    }

    /**
     * 获取当前选择的提供者显示名
     */
    public function getCurrentProviderName(): string
    {
        $config = Context::instance()->config();
        return (string)($config->get('ai.current_provider', $this->defaultProvider));
    }

    /**
     * 设置当前选择的提供者
     */
    public function setCurrentProvider(string $providerName): bool
    {
        if (!isset($this->displayToCode[$providerName])) {
            return false;
        }
        $config = Context::instance()->config();
        $config->set('ai.current_provider', $providerName);
        return true;
    }


    /**
     * 获取当前提供者实例
     */
    public function getCurrentProvider(?string $name = null): ?BaseAIProvider
    {
        $name = $name ?? $this->getCurrentProviderName();
        return $this->createProvider($name);
    }

    /**
     * 读取当前提供者的 API Key
     */
    public function getCurrentApiKey(?string $name = null): string
    {
        $provider = $this->getCurrentProvider($name);
        return $provider?->getApiKey() ?? '';
    }

    /**
     * 设置当前提供者的 API Key
     */
    public function setCurrentApiKey(string $apiKey, ?string $name = null): void
    {
        $provider = $this->getCurrentProvider($name);
        if (!$provider) {
            return;
        }
        $code = $provider->getName();
        $config = Context::instance()->config();
        $config->set("ai.providers.$code.api_key", $apiKey);
    }

    /**
     * 读取当前提供者的 API URL
     */
    public function getCurrentApiUrl(?string $name = null): string
    {
        $provider = $this->getCurrentProvider($name);
        return $provider?->getApiUri() ?? '';
    }

    /**
     * 设置当前提供者的 API URL
     */
    public function setCurrentApiUrl(string $apiUrl, ?string $name = null): void
    {
        $provider = $this->getCurrentProvider($name);
        if (!$provider) {
            return;
        }
        $code = $provider->getName();
        $config = Context::instance()->config();
        $config->set("ai.providers.$code.api_url", $apiUrl);
    }

    /**
     * 读取当前提供者的模型
     */
    public function getCurrentModel(?string $name = null): string
    {
        $provider = $this->getCurrentProvider($name);
        return $provider?->getModel() ?? '';
    }

    /**
     * 设置当前提供者的模型
     */
    public function setCurrentModel(string $model, ?string $name = null): void
    {
        $provider = $this->getCurrentProvider($name);
        if (!$provider) {
            return;
        }
        $code = $provider->getName();
        $config = Context::instance()->config();
        $config->set("ai.providers.$code.api_model", $model);
    }

    /**
     * 读取当前提供者的代理
     */
    public function getCurrentProxy(?string $name = null): string
    {
        $provider = $this->getCurrentProvider($name);
        if (!$provider) {
            return '';
        }
        // 直接读取配置中的代理键
        $code = $provider->getName();
        $config = Context::instance()->config();
        return (string)$config->get("ai.providers.$code.proxy", '');
    }

    /**
     * 设置当前提供者的代理
     */
    public function setCurrentProxy(string $proxy, ?string $name = null): void
    {
        $provider = $this->getCurrentProvider($name);
        if (!$provider) {
            return;
        }
        $code = $provider->getName();
        $config = Context::instance()->config();
        $config->set("ai.providers.$code.proxy", $proxy);
    }

    /**
     * 获取当前提供者可用的模型列表
     *
     * @return array<string>
     */
    public function getAvailableModels(?string $name = null,?string $key = null, ?string $proxy = null): array
    {
        $provider = $this->getCurrentProvider($name);
        if (!$provider) {
            return [];
        }
        if ($key!== null){
            $provider->setApiKey($key);
        }
        if ($proxy !== null) {
            $provider->setProxy($proxy);
        }
        return $provider->getAvailableModels();
    }


    /**
     * 获取当前提供者的创建 Key 页面
     */
    public function getCreateKeyUri(?string $name = null): string
    {
        $provider = $this->getCurrentProvider($name);
        return $provider?->getCreateKeyUri() ?? '';
    }

    /**
     * 发送请求到当前提供者
     */
    public function request(string $system, string $user): ?string
    {
        $provider = $this->getCurrentProvider();
        return $provider?->request($system, $user);
    }

    /**
     * 工厂：根据显示名创建 Provider 实例，并注入配置
     */
    private function createProvider(string $displayName): ?BaseAIProvider
    {
        $class = 'nova\\plugin\\ai\\providers\\' . $displayName . 'Provider';
        if (class_exists($class)) {
            /** @var BaseAIProvider $instance */
            $instance = new $class();
            return $instance;
        }
        return null;

    }

    /**
     * 获取单例
     */
    public static function instance(): AiManager
    {
        /** @var AiManager $mgr */
        $mgr = Context::instance()->getOrCreateInstance('ai_manager', static function () {
            return new AiManager();
        });
        return $mgr;
    }
}


