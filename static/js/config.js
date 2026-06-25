window.pageLoadFiles = [
    'Form',
    'Request',
    'Toaster',
    'SearchInput',
];

window.pageOnLoad = function () {
    const modelEl = function () {
        return document.querySelector('#form_ai [name=api_model]');
    };

    // 模型 id 既是 value 也是显示文本；并让自由输入也能作为最终值
    const setupModelInput = function () {
        const el = modelEl();
        if (!el || el._aiBound) {
            return;
        }
        el._aiBound = true;
        el.renderItem = function (row) {
            return { key: String(row), value: String(row) };
        };
        // 未从下拉选择、直接手输的模型名也要能提交
        el.addEventListener('input', function () {
            el.value = el.text;
        });
    };

    $.form.manage('/ai/api/config', '#form_ai', {
        beforeSet: function (response) {
            $.form.setSelectOptions('[name=provider]', response.data.providers);
        },
        afterSet: function (response) {
            setupModelInput();
            const el = modelEl();
            const model = response.data.api_model || '';
            if (el && model) {
                el.setValue(model, model);
            }
        },
    });

    let lastProvider = null;
    $('#form_ai [name=provider]').on('change', function () {
        const data = $.form.val('#form_ai');
        const currentProvider = data.provider || $('#form_ai [name=provider]').val();
        if (!currentProvider || currentProvider === lastProvider) {
            return;
        }
        lastProvider = currentProvider;
        $.request.postForm('/ai/api/config/api', data, function (res) {
            if (res.code === 200) {
                $('[name="api_url"]').val(res.data.api_url);
            }
        });
    });

    // 刷新：用当前表单凭据拉取全量模型并落库缓存（服务端 24h），不填充搜索框
    $('#refreshModels').on('click', function () {
        $('#container').showLoading('正在获取模型列表...');
        $.request.postForm('/ai/api/config/models', $.form.val('#form_ai'), function (res) {
            $('#container').closeLoading();
            if (res.code === 200) {
                const count = Array.isArray(res.data) ? res.data.length : 0;
                $.toaster.info('模型列表已刷新，共 ' + count + ' 个');
            }
        }, function () {
            $('#container').closeLoading();
        });
    });

    $('#openKey').on('click', function () {
        const data = $.form.val('#form_ai');
        $.request.postForm('/ai/api/config/url', data, function (res) {
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
