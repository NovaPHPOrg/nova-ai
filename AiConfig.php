<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\framework\core\ConfigObject;
use nova\framework\core\Context;
use nova\framework\core\Text;
use nova\plugin\ai\providers\BaseAIProvider;

/**
 * AI 模块配置
 */
class AiConfig extends ConfigObject
{
    private const string DEFAULT_PROVIDER = 'ChatGPT';

    /** 支持的提供商显示名；新增提供商在此登记，类名约定为 "{显示名}Provider" */
    private const array PROVIDERS = ['ChatGPT', 'OpenRouter'];

    private const array EMPTY_SETTINGS = [
        'api_key' => '',
        'api_url' => '',
        'api_model' => '',
        'proxy' => '',
    ];

    public string $currentProvider = self::DEFAULT_PROVIDER;

    /** @var array<string, array<string, string>> 以 Provider::getName() 为键 */
    public array $providers = [];

    public function currentProviderName(): string
    {
        return $this->currentProvider ?: self::DEFAULT_PROVIDER;
    }

    public function providerSetting(string $code, string $key): string
    {
        return (string)($this->providers[$code][$key] ?? '');
    }

    public function setProviderSetting(string $code, string $key, string $value): void
    {
        $this->providers[$code] ??= self::EMPTY_SETTINGS;
        $this->providers[$code][$key] = $value;
    }

    /** 按显示名实例化提供商；未知则返回 null */
    public function resolveProvider(?string $displayName = null): ?BaseAIProvider
    {
        $displayName ??= $this->currentProviderName();
        $class = __NAMESPACE__ . '\\providers\\' . $displayName . 'Provider';

        return class_exists($class) ? new $class() : null;
    }

    /**
     * 配置页 GET 数据（含 Provider 解析后的有效值）
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $provider = $this->currentProviderName();
        $instance = $this->resolveProvider($provider);

        return [
            'providers' => self::PROVIDERS,
            'provider' => $provider,
            'createKeyUri' => $instance?->getCreateKeyUri() ?? '',
            'api_key' => $instance?->getApiKey() ?? '',
            'api_url' => $instance?->getApiUri() ?? '',
            'api_model' => $instance?->getModel() ?? '',
            'proxy' => $instance?->getProxy() ?? '',
            'availableModels' => [],
        ];
    }

    /** 保存配置页表单；provider 缺省时沿用当前提供商，未知则忽略 */
    public function applyForm(array $post): void
    {
        $code = $this->resolveProvider($post['provider'] ?? null)?->getName();
        if ($code === null) {
            return;
        }

        foreach (['api_key', 'api_url', 'api_model', 'proxy'] as $field) {
            $value = $post[$field] ?? '';
            if (is_string($value)) {
                $this->setProviderSetting($code, $field, Text::parseType($this->providerSetting($code, $field), $value));
            }
        }
    }

    /**
     * @return array<string>
     */
    public function availableModelsFor(string $provider, string $apiKey = '', ?string $proxy = null): array
    {
        $instance = $this->resolveProvider($provider);
        if (!$instance) {
            return [];
        }

        if ($apiKey !== '') {
            $instance->setApiKey($apiKey);
        }
        if ($proxy !== null) {
            $instance->setProxy($proxy);
        }

        $cache = Context::instance()->cache;
        $key = 'ai_models_' . md5($instance->getName() . '|' . $instance->getApiUri() . '|' . $instance->getApiKey() . '|' . (string)$proxy);

        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $models = $instance->getAvailableModels();
        if ($models !== []) {
            $cache->set($key, $models, 86400);
        }

        return $models;
    }

    public static function migrateLegacy(): void
    {
        $config = Context::instance()->config();
        $ai = $config->get('ai');
        if (!is_array($ai) || !isset($ai['current_provider']) || isset($ai['currentProvider'])) {
            return;
        }

        $ai['currentProvider'] = $ai['current_provider'];
        unset($ai['current_provider']);
        $config->set('ai', $ai);
    }
}
