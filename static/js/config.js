window.pageLoadFiles = [
    'Form',
    'Request',
    'Toaster',
    'Loading',
    'SearchInput',
];

window.pageOnLoad = function () {
    const modelEl = function () {
        return document.querySelector('#form_ai [name=api_model]');
    };

    const setModelOptions = function (list) {
        const el = modelEl();
        if (el) {
            el.options = list || [];
        }
    };

    $.form.manage('/ai/api/config', '#form_ai', {
        beforeSet: function (response) {
            $.form.setSelectOptions('[name=provider]', response.data.providers);
        },
        afterSet: function (response) {
            setModelOptions(response.data.availableModels);
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

    $('#refreshModels').on('click', function () {
        const data = $.form.val('#form_ai');
        $('#form_ai').showLoading('正在获取模型列表...');
        $.request.postForm('/ai/api/config/models', data, function (res) {
            $('#form_ai').closeLoading();
            if (res.code === 200) {
                setModelOptions(res.data.availableModels);
                $.toaster.info('模型列表已刷新');
            }
        }, function () {
            $('#form_ai').closeLoading();
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
