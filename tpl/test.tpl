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
            ></mdui-text-field>
        </div>

        <div class="col-xs-12 action-buttons" style="display:flex; justify-content:flex-end; gap:.5rem;">
            <mdui-button id="ai_clear" variant="text" icon="delete_sweep">清空</mdui-button>
            <mdui-button id="ai_send" icon="send" type="button">发送</mdui-button>
        </div>
    </div>
</div>

<script id="script" src="/ai/static/js/test.js?v={$__v}"></script>
