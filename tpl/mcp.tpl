<title id="title">MCP 工具 - {$title}</title>
<style id="style">

    </style>
<div id="container" class="container p-4">
    <div class="row col-space16">
        <div class="col-xs-12 title-large center-vertical mb-4">
            <mdui-icon name="extension" class="refresh mr-2"></mdui-icon>
            <span>MCP 工具</span>
        </div>

        <div class="col-xs-12">
            <form class="row col-space16" id="form_mcp">
                <div class="col-xs-12">
                    <mdui-text-field
                            label="MCP Server URL"
                            name="servers"
                            variant="outlined"
                            rows="6"
                            helper="每行一个 MCP server 的 HTTP 地址，例如 http://127.0.0.1:8000/mcp"
                    ></mdui-text-field>
                </div>

                <div class="col-xs-12 action-buttons" style="display:flex; justify-content:flex-end; gap:.5rem;">
                    <mdui-button id="test_mcp" icon="lan" variant="tonal" type="button">
                        测试连接
                    </mdui-button>
                    <mdui-button id="save_mcp" icon="save" type="submit">
                        保存修改
                    </mdui-button>
                </div>

                <div class="col-xs-12" id="mcp_result"></div>
            </form>
        </div>
    </div>
</div>

<script id="script" src="/ai/static/js/mcp.js?v={$__v}"></script>

