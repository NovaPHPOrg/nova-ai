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
                    <mdui-select name="api_model" label="模型（下拉选择）" class="h-50"></mdui-select>
                    <mdui-button-icon id="refreshModels" icon="refresh" title="刷新模型"></mdui-button-icon>
                </div>


                

                <div class="col-xs-12 action-buttons">
                    <mdui-button id="save_ai" icon="save" type="submit">
                        保存修改
                    </mdui-button>
                </div>
            </form>
        </div>
    </div>
</div>

<script id="script">
    window.pageLoadFiles = [
        'Form',
        'Request',
        'Toaster',
    ];


    window.pageOnLoad = function (loading) {
        const updateApiModelMenuHeight = function () {
            const selectEl = document.querySelector('[name=api_model]');
            if (!selectEl || !selectEl.shadowRoot) {
                return;
            }
            const menuEl = selectEl.shadowRoot.querySelector('mdui-menu');
            if (!menuEl) {
                return;
            }
            // 获取当前位置信息，计算相对高度：可视区域 - api_model 的位置 - api_model 高度 - 20 像素
            const rect = selectEl.getBoundingClientRect();
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            const reserved = 20;
            const available = Math.max(160, viewportHeight - rect.top - rect.height - reserved);
            menuEl.style.overflowY = 'auto';
            menuEl.style.maxHeight = available + 'px';
        };

        $('#form_ai [name=api_model]').on('focus', function () {
            setTimeout(updateApiModelMenuHeight,200)
        });

        // 通过 $.form.manage 接管 GET/POST
        $.form.manage('/ai/config', "#form_ai", {
            beforeSet: function (response) {
                // 设置后可做额外处理
                $.form.setSelectOptions("[name=provider]",response.data.providers)
            }
        });

            let lastProvider = null;
            // 当切换提供者时：通知后端切换当前提供者，并刷新表单获取该提供者下的 api_url 等配置
            $('#form_ai [name=provider]').on('change', function () {
                const data = $.form.val("#form_ai");
                const currentProvider = data.provider || $('#form_ai [name=provider]').val();
                if (!currentProvider || currentProvider === lastProvider) {
                    return;
                }
                lastProvider = currentProvider;
                // 使用当前输入（provider/api_key/api_url/api_model_text优先）刷新模型
                $.request.postForm('/ai/config/api', data, function (res) {
                    if (res.code === 200) {
                        $('[name="api_url"]').val( res.data.api_url);
                    }
                });
            });

        // 刷新模型
        $('#refreshModels').on('click', function () {
            const data = $.form.val("#form_ai") ;
            // 使用当前输入（provider/api_key/api_url/api_model_text优先）刷新模型
            $.request.postForm('/ai/config/models', data, function (res) {
                if (res.code === 200) {
                    $.form.setSelectOptions('[name="api_model"]',res.data.availableModels);
                    $.toaster.info('模型列表已刷新');
                }
            });
        });

        // 创建 API Key（根据当前选择的提供者 / 模型动态获取链接）
        $('#openKey').on('click', function () {
            const data = $.form.val("#form_ai") ;

            $.request.postForm('/ai/config/url',data, function (res) {
                if (res.code === 200 && res.data && res.data.createKeyUri) {
                    window.open(res.data.createKeyUri, '_blank');
                } else {
                    $.toaster.error('未获取到创建 API Key 的链接');
                }
            });
        });

        window.pageOnUnLoad = function () {
        };
    };
</script>


