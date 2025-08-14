<?php

namespace nova\plugin\ai\providers;

use nova\framework\core\Context;
use nova\plugin\http\HttpClient;


/**
 * AI 提供商的基础抽象类（PHP 版）
 * - 统一的 Key/URI/Model 读取（优先读全局配置，缺省走子类默认值 / 本地属性）
 * - 提供 HTTP 客户端与可选日志
 * - 提供 removeThink() 清洗工具
 */
abstract class BaseAIProvider
{
    /** 提供商级备用 API Key（若配置无值时使用） */
    protected string $apiKey = '';

    /** HTTP 客户端，供子类复用 */
    protected HttpClient $http;

    protected bool $debug;

    public function __construct()
    {
        $this->debug = Context::instance()->isDebug();
        $this->http  = HttpClient::init();
    }

    /** 提供商名称，用于拼装设置键名，如 API_KEY_{name} */
    abstract public function getName(): string;


    /** 引导用户去创建 API Key 的页面 */
    abstract public function getCreateKeyUri(): string;

    /** 返回可用模型列表 */
    abstract public function getAvailableModels(): array;


    /**
     * 实际向 AI 服务发起请求
     * 若 $options 中包含 onChunk/onComplete/onHeader 回调，则以流式方式执行并返回 null；
     * 否则返回完整文本。
     *
     * @param string $system
     * @param string $user
     * @param array{onChunk?:callable,onComplete?:callable} $options
     * @return string|null
     */
    abstract public function request(string $system, string $user, array $options = []): ?string;

    /** 子类给出缺省 API Base URL（当设置仓库无值时使用） */
    abstract protected function getDefaultApiUri(): string;

    /** 子类给出缺省模型名（当设置仓库无值时使用） */
    abstract protected function getDefaultModel(): string;

    /** 读取 API Key：优先全局配置，否则回退本地属性 */
    public function getApiKey(): string
    {
        $code = $this->getName();
        $cfg  = Context::instance()->config();
        $val  = (string)$cfg->get("ai.providers.$code.api_key", '');
        return $val !== '' ? $val : $this->apiKey;
    }

    /** 读取 API URI：优先全局配置，否则回退子类默认值 */
    public function getApiUri(): string
    {
        $code = $this->getName();
        $cfg  = Context::instance()->config();
        $val  = (string)$cfg->get("ai.providers.$code.api_url", '');
        return $val !== '' ? $val : $this->getDefaultApiUri();
    }

    /** 读取模型名：优先全局配置，否则回退子类默认值 */
    public function getModel(): string
    {
        $code = $this->getName();
        $cfg  = Context::instance()->config();
        $val  = (string)$cfg->get("ai.providers.$code.api_model", '');
        return $val !== '' ? $val : $this->getDefaultModel();
    }

    /** 读取代理配置：留空为不使用代理 */
    public function getProxy(): string
    {
        $code = $this->getName();
        $cfg  = Context::instance()->config();
        return (string)$cfg->get("ai.providers.$code.proxy", '');
    }

    /** 运行时设置代理，传入空字符串可关闭代理 */
    public function setProxy(string $proxy): void
    {
        $this->http->proxy($proxy);
    }

    /** 可选：允许在运行时注入备用 API Key（与 Kotlin var apiKey 等价） */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }


    /** 与 Kotlin 的扩展函数 String.removeThink 等价 */
    protected function removeThink(string $text): string
    {
        // /si = DOTALL + IGNORECASE
        $text = preg_replace('/<think\b[^>]*?>.*?<\/think>/si', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** JSON 工具（可供子类用，等价于 Gson） */
    protected function jsonEncode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function jsonDecode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
