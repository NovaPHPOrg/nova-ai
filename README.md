# nova-ai

Nova 框架的 AI 插件。统一多家提供商的对话接口，并在其上提供三层能力：

- **提供商层**：OpenAI 兼容的流式对话，含 `tool_calls` 分片重组。
- **Agent 层**：带 function-calling 工具循环的人格化助手，可挂会话实现跨请求多轮对话。
- **任务层**：把模型输出收敛成结构化 JSON 的 `AiTask`。

工具来源分本地 PHP 与远程 MCP 两种，在调用循环里不作区分。

## 安装

依赖 `tpl`、`login`、`http` 三个插件（见 `package.php`）。插件通过 `framework_start`
挂载 `AiPluginManager`，自动注册路由、权限与后台菜单，无需在应用侧写任何注册代码。

后台页面（权限标识 `ai_manage`，覆盖 `ANY /ai*`）：

| 路径 | 用途 |
| --- | --- |
| `/ai/config` | 提供商、API Key、模型、代理 |
| `/ai/mcp` | MCP server 列表与连通性测试 |
| `/ai/test` | 对话联调 |

用前先在 `/ai/config` 配好 Key 和模型，否则 `resolveProvider()` 虽能拿到实例，但请求会失败。

## 三种用法

### 1. 直接对话

不需要工具和历史时最省事：

```php
$provider = AiConfig::getInstance()->resolveProvider();   // null 表示提供商未配置
$answer = $provider?->request('你是一个翻译助手', '把这句话译成英文：今天天气不错');
```

`request()` 是便捷入口。要自己控制完整消息序列就用 `chat()`：

```php
$result = $provider->chat([
    ['role' => 'system', 'content' => '...'],
    ['role' => 'user', 'content' => '...'],
], ['temperature' => 0.3, 'reasoning' => false]);

// $result = ['content' => string, 'tool_calls' => array, 'finish_reason' => string]
```

传入 `onChunk` 即转为流式，此时 `request()` 返回 `null`，内容全部走回调。

### 2. Agent

继承 `Agent`，至少实现 `persona()`。`run()` 会自动跑完 function-calling 循环：模型要调工具就执行、
把结果回灌，直到它给出最终答复或用尽轮数。

```php
class TranslateAgent extends Agent
{
    public function persona(): string
    {
        return '你是翻译助手，必要时用 fetch_url 查证术语。完成后只输出译文。';
    }

    protected function maxIterations(): int
    {
        return 20;
    }
}

$answer = (new TranslateAgent())->run('把 README 译成日文');
```

可覆盖的钩子：

| 方法 | 默认 | 说明 |
| --- | --- | --- |
| `persona()` | 抽象 | system prompt |
| `tools()` | `HttpTools` | 本地工具列表 |
| `providerName()` | `null` | 指定提供商，`null` 用当前激活的 |
| `temperature()` | `0.7` | |
| `maxIterations()` | `50` | 工具调用轮数上限，防循环失控 |
| `budgetHint()` | 见源码 | 每轮附到 system 末尾的剩余轮数提示，返回空串即关闭 |

`run()` 是 `final`，签名为 `run(string|array $input, array $streamOptions = [], bool $reasoning = true): ?string`。
流式输出通过 `onChunk(string $text, string $type)` 推送，`type` 有四种：

```php
$agent->run($prompt, [
    'onChunk' => function (string $text, string $type): void {
        // content | thinking | tool_call | tool_result
    },
    'onComplete' => function (): void { /* 流结束 */ },
]);
```

工具执行前后各推一次：`tool_call` 给出工具名，`tool_result` 给出返回文本。

### 3. AiTask

需要的是结构化数据而不是自然语言时用它。管道是「prompt → Agent → 提取 JSON → 字段白名单 → 后处理」：

```php
class ExtractMeta extends AiTask
{
    protected function agent(): Agent
    {
        return new MetaAgent();
    }

    protected function allowedFields(): array
    {
        return ['title', 'summary', 'tags'];   // 白名单外的字段一律丢弃
    }

    public function run(string $text): ?array
    {
        return $this->execute("提取元信息，用 JSON 输出：\n{$text}");
    }
}
```

`execute()` 会从回答里截取第一个 `{` 到最后一个 `}` 解析。任何一步失败都返回 `null` 并记日志，
不抛异常。可再覆盖 `toolLabels()`（工具名 → 进度标签）、`summarize()`、`postProcess()`。

## 多轮对话

`Agent` 原生支持跨请求延续对话，挂一段 `Conversation` 即可，子类不需要写任何代码：

```php
$conversation = new Conversation($tenantId . ':' . $userId, $conversationId);

(new MyAgent())
    ->withConversation($conversation)
    ->run($prompt, ['onChunk' => $onChunk]);
```

不挂就是一次性任务，每次 `run()` 各自独立。`withConversation()` 返回 `$this`，可链式调用。

### 两个参数的信任级别不同

- `$scope`：调用方自己的隔离维度，如「租户:用户」。来自服务端，视为可信。
- `$conversationId`：通常由前端生成，**不可信**。它会被削成 `[A-Za-z0-9_-]` 并截到 64 字符
  才拼进缓存键——否则带 `../` 的值在文件缓存驱动下会写到意料之外的位置。

换一个 `$conversationId` 就是开一段新对话，旧的靠 TTL（2 小时）自行过期。

### 历史存在 Cache，不存 Session

Agent 常跑在 SSE 长连接里，一次请求可能几十轮工具调用、持续数分钟。PHP 的 session 在请求期间
独占文件锁，写进 session 就意味着这段时间里同一浏览器的其他请求全部排队，页面上别的功能会集体卡死。
Cache 没有这个问题，而且对话本就是临时数据，靠 TTL 自然过期，不必建表也不必清理。

### 压缩

历史估算超过 20000 token 时，早期消息会被交给模型压成一段中文纪要，最近的原文保留。
切分点按 token 从后往前累加到半个预算为止，不用固定条数——一条读文件的工具结果可能比十轮问答还长。
摘要请求失败时降级为直接丢弃早期历史，不让压缩故障拖垮整个对话。

阈值是留给历史的份额，不是模型上下文总长；system prompt 与工具定义还要另占。人格特别长时
要同步下调 `Conversation::COMPRESS_AT`。token 用非 ASCII 按 1.5、ASCII 按 4 字符 1 个粗估，
按「字符数除以二」算会把中文用量低估到三分之一。

### 一条硬约束

OpenAI 兼容接口要求 `role=tool` 的消息必须紧跟在带 `tool_calls` 的 `assistant` 之后。因此历史
绝不能以孤立的 `tool` 消息开头，压缩时的切分点会主动跳过它们。同理，只有正常收尾才会存档：
工具抛异常那一刻，最后一条 `assistant` 的 `tool_calls` 往往还没配齐 `tool` 响应，存下这种残缺
序列会让下一轮请求直接报错。宁可丢掉本轮记录，也要让已存历史始终合法。

## 工具

### 契约

```php
interface ToolInterface
{
    public function name(): string;        // 一次会话内唯一
    public function definition(): array;   // OpenAI function-calling 定义
    public function call(array $arguments): string;
}
```

多数情况不必建类，用 `CallableTool` 包一个闭包：

```php
protected function tools(): array
{
    return [
        new CallableTool(
            'get_weather',
            'Query weather by city name.',
            ['type' => 'object', 'properties' => [
                'city' => ['type' => 'string', 'description' => 'City name.'],
            ], 'required' => ['city']],
            fn (array $a): string => $this->weather((string)($a['city'] ?? '')),
        ),
    ];
}
```

工具抛出的异常会被 `ToolRegistry` 转成 `Error: ...` 文本回灌给模型，不会打断循环——模型看到错误
往往会自己改参数重试。返回值必须是字符串，因为它要作为 `tool` 消息进入对话。

### 内置工具

| 类 | 构造 | 工具 |
| --- | --- | --- |
| `HttpTools` | 无参 | `fetch_url` |
| `FileSystemTools` | `getInstance($root)` | `read_file` `read_multiple_files` `write_file` `edit_file` `append_file` `delete_file` `move_file` `copy_file` `rename_file` `list_directory` `create_directory` `search_files` `glob_search` `tree` `get_file_info` |
| `BashTools` | `getInstance($root)` | `run_command` |

`HttpTools` 是 `Agent::tools()` 的默认值，带 SSRF 防护：只放行 http(s)，解析出的 IP 若是内网、
环回或保留地址一律拒绝，并限制超时与响应体大小。这是「让模型在 bash 里 curl」给不了的安全层。

文件与命令工具都被限制在构造时传入的 `$root` 内。`FileSystemAgent` 是现成的组合示例。

### MCP

在 `/ai/mcp` 填入 server URL（每行一个，仅 URL，不支持鉴权和自定义 header）即可。所有已配置
server 的工具会自动进入每个 Agent 的注册表，无需改代码。

工具**定义**缓存 5 分钟，避免每次运行都去拉一遍 `tools/list`；`tools/call` 始终走实时网络。
单个 server 不可用时跳过并记警告，不连累其他 server。

注册顺序是先 MCP 后本地，因此**同名时本地工具覆盖 MCP 工具**。

## 提供商

内置 `ChatGPT` 与 `OpenRouter`。类名约定为 `{显示名}Provider`，放在 `providers/` 下，
`AiConfig::resolveProvider()` 靠这个约定反射定位，所以新增提供商不需要注册表。

只要目标服务是 OpenAI 兼容的，继承 `BaseOpenAIProvider` 后实现四个方法即可，`chat()` 与流式解析
都已由基类处理：

```php
class MyProvider extends BaseOpenAIProvider
{
    public function getName(): string { return 'myprovider'; }          // 配置存储键
    public function getCreateKeyUri(): string { return 'https://...'; } // 引导用户建 Key 的页面
    protected function getDefaultApiUri(): string { return 'https://api.example.com'; }
    protected function getDefaultModel(): string { return 'default-model'; }
}
```

再把显示名加进 `AiConfig::PROVIDERS`，配置页就会出现该选项。协议不兼容 OpenAI 的话，
直接继承 `BaseAIProvider` 并自行实现 `chat()`，返回
`['content' => string, 'tool_calls' => array, 'finish_reason' => string]`。

`getAvailableModels()` 的结果缓存 24 小时，配置页的模型搜索基于这份缓存过滤。

## 配置存储

`AiConfig` 与 `McpConfig` 都是 `ConfigObject`，由框架在析构时整体落库，本插件不直接写配置文件。
凭据按 `providers[提供商键][字段]` 存放，字段为 `api_key`、`api_url`、`api_model`、`proxy`；
读取时优先取配置，缺省回落到子类默认值。

## 目录结构

```
ai/
├── AiConfig.php           提供商配置与模型缓存
├── McpConfig.php          MCP server URL 列表
├── AiPluginManager.php    路由、权限、菜单注册
├── AiTpl.php              后台页面与菜单定义
├── AiResponseParser.php   SSE 流解析（content / reasoning / tool_calls 分片重组）
├── agent/
│   ├── Agent.php          工具调用循环与人格基类
│   ├── Conversation.php   多轮对话的存储与压缩
│   └── FileSystemAgent.php
├── task/AiTask.php        结构化输出管道
├── tool/                  ToolInterface / CallableTool / ToolRegistry / 内置工具
├── mcp/                   MCP 客户端与工具适配
├── providers/             提供商实现
├── controller/            配置、MCP、测试接口
├── tpl/ + static/js/      后台页面
└── package.php
```

## 许可证

与主项目保持一致。
