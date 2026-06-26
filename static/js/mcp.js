window.pageLoadFiles = [
    'Form',
    'Request',
    'Toaster',
];

window.pageOnLoad = function () {
    $.form.manage('/ai/api/mcp', '#form_mcp');

    const renderResult = function (list) {
        const html = (list || []).map(function (item) {
            if (item.ok) {
                const tools = (item.tools || []).join(', ') || '(无工具)';
                return '<div class="mb-2"><b>✓ ' + item.url + '</b><br>' + tools + '</div>';
            }
            return '<div class="mb-2" style="color:var(--mdui-color-error)"><b>✗ ' + item.url + '</b><br>' + (item.error || '连接失败') + '</div>';
        }).join('');
        $('#mcp_result').html(html);
    };

    $('#test_mcp').on('click', function () {
        $('#container').showLoading('正在连接 MCP server...');
        $.request.postForm('/ai/api/mcp/test', $.form.val('#form_mcp'), function (res) {
            $('#container').closeLoading();
            if (res.code === 200) {
                renderResult(res.data);
            }
        }, function () {
            $('#container').closeLoading();
        });
    });

    window.pageOnUnLoad = function () {
    };
};
