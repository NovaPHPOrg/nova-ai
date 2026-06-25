<title id="title">AI 测试 - {$title}</title>
<style id="style">
    #ai_output {
        min-height: 240px;
        max-height: 60vh;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: break-word;
        line-height: 1.6;
        padding: 16px;
        border-radius: 8px;
        background: rgb(var(--mdui-color-surface-container-low));
    }
    #ai_output .ai-content { color: rgb(var(--mdui-color-on-surface)); }
    #ai_output .ai-think { margin: 6px 0; }
    #ai_output .ai-think > summary {
        cursor: pointer;
        font-size: .85rem;
        color: rgb(var(--mdui-color-on-surface-variant));
    }
    #ai_output .ai-think-dots {
        display: inline-flex;
        gap: 3px;
        margin-left: 6px;
        vertical-align: middle;
    }
    #ai_output .ai-think-dots > i {
        width: 4px;
        height: 4px;
        border-radius: 50%;
        background: currentColor;
        animation: ai-think-bounce 1s infinite ease-in-out;
    }
    #ai_output .ai-think-dots > i:nth-child(2) { animation-delay: .15s; }
    #ai_output .ai-think-dots > i:nth-child(3) { animation-delay: .30s; }
    #ai_output .ai-think-done .ai-think-dots { display: none; }
    @keyframes ai-think-bounce {
        0%, 80%, 100% { opacity: .25; transform: translateY(0); }
        40% { opacity: 1; transform: translateY(-3px); }
    }
    #ai_output .ai-thinking { color: rgb(var(--mdui-color-on-surface-variant)); font-style: italic; }
    #ai_output .ai-tool {
        display: block;
        margin: 6px 0;
        padding: 6px 10px;
        border-left: 3px solid rgb(var(--mdui-color-primary));
        background: rgb(var(--mdui-color-surface-container));
        border-radius: 4px;
        font-size: .85rem;
        white-space: pre-wrap;
    }
    #ai_output .ai-error { color: rgb(var(--mdui-color-error)); }
</style>
<div id="container" class="container p-4">
    <div class="row col-space16">
        <div class="col-xs-12 title-large center-vertical mb-4">
            <mdui-icon name="science" class="mr-2"></mdui-icon>
            <span>AI 测试（文件助手 · 工作目录 /tmp）</span>
        </div>

        <div class="col-xs-12">
            <div id="ai_output"><span class="ai-thinking">在下方输入指令，例如：在 /tmp 下创建 demo.txt 并写入 hello，然后读出来。</span></div>
        </div>

        <div class="col-xs-12" style="display:flex; align-items:flex-end; gap:.5rem;">
            <mdui-text-field
                    id="ai_input"
                    label="输入指令"
                    rows="2"
                    autosize
                    variant="outlined"
                    style="flex:1"
                    value="依次演示你的全部文件操作能力（工作目录 /tmp），每步请说明用到的工具：1) 新建目录 demo 和 demo/sub；2) 在 demo/a.txt 写入「hello world」；3) 向 demo/a.txt 追加一行「second line」；4) 把 demo/a.txt 里的 hello 替换为「你好」；5) 读取 demo/a.txt；6) 查看 demo/a.txt 的文件信息；7) 复制 demo/a.txt 为 demo/b.txt；8) 把 demo/b.txt 重命名为 c.txt；9) 将 demo/c.txt 移动到 demo/sub/ 下；10) 列出 demo 目录；11) 以树形展示 demo 结构；12) 在 demo 下按文件名搜索包含 a 的条目；13) 用通配符 *.txt 查找 demo 下的文本文件；14) 同时读取 demo/a.txt 和 demo/sub/c.txt；15) 最后删除 demo/a.txt 和 demo/sub/c.txt 两个文件。"
            ></mdui-text-field>
        </div>

        <div class="col-xs-12 action-buttons" style="display:flex; justify-content:flex-end; gap:.5rem;">
            <mdui-button id="ai_clear" variant="text" icon="delete_sweep">清空</mdui-button>
            <mdui-button id="ai_send" icon="send" type="button">发送</mdui-button>
        </div>
    </div>
</div>

<script id="script" src="/ai/static/js/test.js?v={$__v}"></script>
