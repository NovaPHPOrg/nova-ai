<title id="title">AI 配置 - {$title}</title>
<style id="style">


    </style>
<div id="container" class="container p-4">
    <div class="row col-space16">
        <div class="col-xs-12 title-large center-vertical mb-4">
            <mdui-icon name="smart_toy" class="refresh mr-2"></mdui-icon>
            <span>AI 配置</span>
        </div>


        <div class="col-xs-12">
            <form class="row col-space16" id="form_ai">
                <div class="col-xs-12">
                    <mdui-select name="provider" label="提供者"></mdui-select>
                </div>

                <div class="col-xs-12" style="display:flex; align-items:center; gap:.5rem;">
                    <mdui-text-field
                            label="API Key"
                            name="api_key"
                            type="password"
                            variant="outlined"
                            toggle-password
                            style="flex:1"
                    ></mdui-text-field>
                    <mdui-button-icon id="openKey" icon="open_in_new" title="创建 API Key"></mdui-button-icon>
                </div>

                <div class="col-xs-12">
                    <mdui-text-field
                            label="API URL"
                            name="api_url"
                            type="text"
                            variant="outlined"
                            helper="留空使用默认"
                    ></mdui-text-field>
                </div>

                <div class="col-xs-12">
                    <mdui-text-field
                            label="代理"
                            name="proxy"
                            type="text"
                            variant="outlined"
                            helper="留空不使用；示例：http://127.0.0.1:7890 或 socks5h://user:pass@127.0.0.1:1080"
                    ></mdui-text-field>
                </div>

                <div class="col-xs-12 col-md-6" style="display:flex; align-items:center; gap:.5rem;">
                    <mdui-search-input name="api_model" label="模型（输入或选择）" placeholder="输入关键字过滤" style="flex:1"></mdui-search-input>
                    <mdui-button-icon id="refreshModels" icon="refresh" title="刷新模型"></mdui-button-icon>
                </div>


                

                <div class="col-xs-12 action-buttons" style="display:flex; justify-content:flex-end;">
                    <mdui-button id="save_ai" icon="save" type="submit">
                        保存修改
                    </mdui-button>
                </div>
            </form>
        </div>
    </div>
</div>

<script id="script" src="/ai/static/js/config.js?v={$__v}"></script>


