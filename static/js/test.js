window.pageLoadFiles = [
    'Request',
    'Toaster',
];

window.pageOnLoad = function () {
    const $out = $('#ai_output');
    const $input = $('#ai_input');
    const $send = $('#ai_send');
    let es = null;
    let $think = null; // 当前思考块的内容容器，非 thinking 内容到来即结束

    const CLASS = {
        content: 'ai-content',
        tool_call: 'ai-tool',
        tool_result: 'ai-tool',
        error: 'ai-error',
    };
    const PREFIX = {
        tool_call: '🔧 调用工具: ',
        tool_result: '↳ 结果: ',
        error: '⚠ ',
    };

    const busy = function () {
        return !!$send.prop('loading');
    };

    // mdui.$ 无 scrollTop，滚动需操作原生节点
    const scrollBottom = function () {
        const el = $out[0];
        el.scrollTop = el.scrollHeight;
    };

    // 结束当前思考块：停止 summary 里的“正在思考”动画
    const endThinking = function () {
        if ($think) {
            $think[0].parentElement.classList.add('ai-think-done');
            $think = null;
        }
    };

    const append = function (type, text) {
        text = text || '';

        if (type === 'thinking') {
            // 思考过程累加进同一个默认折叠的 details 块，summary 内显示思考中动画
            if (!$think) {
                const $d = $('<details>').addClass('ai-think').appendTo($out);
                $('<summary>')
                    .html('思考过程<span class="ai-think-dots"><i></i><i></i><i></i></span>')
                    .appendTo($d);
                $think = $('<div>').addClass('ai-thinking').appendTo($d);
            }
            $think[0].textContent += text;
            scrollBottom();
            return;
        }

        // 其它内容结束当前思考块；content 用内联 span 流式拼接
        endThinking();
        $(type === 'content' ? '<span>' : '<div>')
            .addClass(CLASS[type] || '')
            .text((PREFIX[type] || '') + text)
            .appendTo($out);
        scrollBottom();
    };

    const stop = function () {
        if (es) {
            es.close();
            es = null;
        }
        endThinking();
        $send.prop('loading', false).prop('disabled', false);
    };

    const start = function () {
        if (busy()) {
            return;
        }
        const q = ($input.val() || '').trim();
        if (!q) {
            $.toaster.warning('请输入指令');
            return;
        }

        endThinking();
        $('<div>').css({ marginTop: '12px', fontWeight: '600' }).text('你: ' + q).appendTo($out);
        scrollBottom();
        $send.prop('loading', true).prop('disabled', true);
        $input.val('');

        es = $.request.sse('/ai/api/test/run', {
            params: { q: q },
            autoReconnect: false,
            eventHandlers: {
                chunk: function (data) {
                    if (data && typeof data === 'object') {
                        append(data.type, data.text);
                    }
                },
                done: stop,
            },
            onError: function () {
                if (busy()) {
                    append('error', '连接中断');
                }
                stop();
            },
        });
    };

    $send.on('click', start);
    $('#ai_clear').on('click', function () {
        $think = null;
        $out.empty();
    });
    $input.on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            start();
        }
    });

    window.pageOnUnLoad = stop;
};
