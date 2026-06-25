<?php

declare(strict_types=1);

namespace nova\plugin\ai\providers;

use nova\plugin\ai\AiConfig;
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
        $this->debug = \nova\framework\core\Context::instance()->isDebug();
        $this->http  = HttpClient::init();
    }

    /** 提供商名称，用于拼装设置键名，如 API_KEY_{name} */
    abstract public function getName(): string;

    /** 引导用户去创建 API Key 的页面 */
    abstract public function getCreateKeyUri(): string;

    /** 返回可用模型列表 */
    abstract public function getAvailableModels(): array;

    /**
     * 简单对话入口（向后兼容）：把 system + user 组装成 messages 后委托 chat()。
     * 若 $options 含 onChunk/onComplete 回调，则以流式方式执行并返回 null；否则返回清洗后的完整文本。
     *
     * @param  string                                        $system
     * @param  string|array                                  $user
     * @param  array{onChunk?:callable,onComplete?:callable} $options
     * @return string|null
     */
    public function request(string $system, string|array $user, array $options = []): ?string
    {
        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        foreach ((array)$user as $item) {
            $messages[] = ['role' => 'user', 'content' => $item];
        }

        $result = $this->chat($messages, $options);

        // 流式：内容已通过回调推送，沿用旧契约返回 null
        if (isset($options['onChunk']) || isset($options['onComplete'])) {
            return null;
        }

        return $result['content'] !== '' ? $this->removeThink($result['content']) : null;
    }

    /**
     * 底层对话：直接发送 messages，支持工具(tools)与流式回调。
     *
     * $options:
     * - tools?: array       OpenAI function-calling 工具定义
     * - temperature?: float
     * - onChunk?: callable   function(string $text, string $type): void  ('content'|'thinking')
     * - onComplete?: callable HttpClient 流式完成回调
     *
     * @param  array<int, array<string, mixed>>                                                       $messages
     * @param  array<string, mixed>                                                                   $options
     * @return array{content:string, tool_calls:array<int,array<string,mixed>>, finish_reason:string}
     */
    abstract public function chat(array $messages, array $options = []): array;

    /** 子类给出缺省 API Base URL（当设置仓库无值时使用） */
    abstract protected function getDefaultApiUri(): string;

    /** 子类给出缺省模型名（当设置仓库无值时使用） */
    abstract protected function getDefaultModel(): string;

    /** 读取本提供商的某项全局配置，缺省为空串 */
    private function setting(string $key): string
    {
        return AiConfig::getInstance()->providerSetting($this->getName(), $key);
    }

    /** 读取 API Key：优先全局配置，否则回退本地属性 */
    public function getApiKey(): string
    {
        return $this->setting('api_key') ?: $this->apiKey;
    }

    /** 读取 API URI：优先全局配置，否则回退子类默认值 */
    public function getApiUri(): string
    {
        return $this->setting('api_url') ?: $this->getDefaultApiUri();
    }

    /** 读取模型名：优先全局配置，否则回退子类默认值 */
    public function getModel(): string
    {
        return $this->setting('api_model') ?: $this->getDefaultModel();
    }

    /** 读取代理配置：留空为不使用代理 */
    public function getProxy(): string
    {
        return $this->setting('proxy');
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
