<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\ConfigObject;
use nova\framework\core\Context;
use nova\plugin\ai\providers\BaseAIProvider;

/**
 * AI 模块配置
 *
 * 持久化数据：currentProvider（激活提供商显示名）+ providers（各提供商凭据）。
 * 由 ConfigObject 在析构时整体落库，本类不主动写配置文件。
 */
class AiConfig extends ConfigObject
{
    private const string DEFAULT_PROVIDER = 'ChatGPT';

    /** 支持的提供商显示名；类名约定为 "{显示名}Provider" */
    private const array PROVIDERS = ['ChatGPT', 'OpenRouter'];

    /** 当前激活的提供商显示名 */
    public string $currentProvider = self::DEFAULT_PROVIDER;

    /** @var array<string, array<string, string>> 以 Provider::getName() 为键的凭据表 */
    public array $providers = [];

    public function providerSetting(string $code, string $key): string
    {
        return (string)($this->providers[$code][$key] ?? '');
    }

    public function setProviderSetting(string $code, string $key, string $value): void
    {
        $this->providers[$code][$key] = $value;
    }

    /** 按显示名实例化提供商；传 null 表示当前激活提供商，未知则返回 null */
    public function resolveProvider(?string $displayName = null): ?BaseAIProvider
    {
        $displayName = $displayName ?: $this->currentProvider;
        $class = __NAMESPACE__ . '\\providers\\' . $displayName . 'Provider';

        return class_exists($class) ? new $class() : null;
    }

    /**
     * 配置页 GET 数据（提供商解析后的有效值）
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $instance = $this->resolveProvider();

        return [
            'providers'    => self::PROVIDERS,
            'provider'     => $this->currentProvider,
            'createKeyUri' => $instance?->getCreateKeyUri() ?? '',
            'api_key'      => $instance?->getApiKey() ?? '',
            'api_url'      => $instance?->getApiUri() ?? '',
            'api_model'    => $instance?->getModel() ?? '',
            'proxy'        => $instance?->getProxy() ?? '',
        ];
    }

    /** 保存配置页表单；provider 未知则整体忽略 */
    public function applyForm(array $post): void
    {
        $instance = $this->resolveProvider($post['provider'] ?? null);
        if (!$instance) {
            return;
        }

        $this->currentProvider = (string)$post['provider'];
        $code = $instance->getName();
        foreach (['api_key', 'api_url', 'api_model', 'proxy'] as $field) {
            $this->setProviderSetting($code, $field, (string)($post[$field] ?? ''));
        }
    }

    /**
     * 持久化表单凭据并切换激活提供商，再从接口拉取「全量」模型缓存 24h。
     * 供「刷新模型」按钮调用：刷新即落库当前编辑状态，保证后续搜索命中同一提供商。
     *
     * @return array<string>
     */
    public function fetchModels(string $provider, string $apiKey = '', string $apiUri = '', string $proxy = ''): array
    {
        $instance = $this->resolveProvider($provider);
        if (!$instance) {
            return [];
        }

        $this->currentProvider = $provider;
        $code = $instance->getName();
        foreach (['api_key' => $apiKey, 'api_url' => $apiUri, 'proxy' => $proxy] as $field => $value) {
            if ($value !== '') {
                $this->setProviderSetting($code, $field, $value);
            }
        }

        return $this->cacheModels($instance);
    }

    /**
     * 从已缓存的全量模型中按关键字过滤。供模型搜索框（search-uri）调用。
     * 缓存未命中时用已保存凭据拉取一次，使搜索无需先点刷新。
     *
     * @return array<string>
     */
    public function searchModels(string $keyword): array
    {
        $instance = $this->resolveProvider();
        if (!$instance) {
            return [];
        }

        $all = Context::instance()->cache->get($this->modelsCacheKey($instance->getApiUri()));
        if (!is_array($all)) {
            $all = $this->cacheModels($instance);
        }

        if ($keyword === '') {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (string $m): bool => stripos($m, $keyword) !== false
        ));
    }

    /**
     * @return array<string>
     */
    private function cacheModels(BaseAIProvider $instance): array
    {
        $models = $instance->getAvailableModels();
        if (!empty($models)) {
            Context::instance()->cache->set($this->modelsCacheKey($instance->getApiUri()), $models, 86400);
        }

        return $models;
    }

    private function modelsCacheKey(string $code): string
    {
        return 'ai_models_' . md5($code);
    }
}
