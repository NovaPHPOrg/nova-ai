window.pageLoadFiles = [
    'Request',
    'Toaster',
];

window.pageOnLoad = function () {
    const out = document.getElementById('ai_output');
    const input = document.getElementById('ai_input');
    const sendBtn = document.getElementById('ai_send');
    const clearBtn = document.getElementById('ai_clear');

    let es = null;
    let busy = false;
    let lastEl = null;
    let lastType = null;

    const scrollBottom = function () {
        out.scrollTop = out.scrollHeight;
    };

    const appendChunk = function (type, text) {
        text = text || '';
        if (type === 'content' || type === 'thinking') {
            if (lastEl && lastType === type) {
                lastEl.textContent += text;
            } else {
                const span = document.createElement('span');
                span.className = type === 'content' ? 'ai-content' : 'ai-thinking';
                span.textContent = text;
                out.appendChild(span);
                lastEl = span;
                lastType = type;
            }
            scrollBottom();
            return;
        }

        const div = document.createElement('div');
        if (type === 'tool_call') {
            div.className = 'ai-tool';
            div.textContent = '🔧 调用工具: ' + text;
        } else if (type === 'tool_result') {
            div.className = 'ai-tool';
            div.textContent = '↳ 结果: ' + text;
        } else if (type === 'error') {
            div.className = 'ai-error';
            div.textContent = '⚠ ' + text;
        } else {
            div.textContent = text;
        }
        out.appendChild(div);
        lastEl = null;
        lastType = null;
        scrollBottom();
    };

    const setBusy = function (state) {
        busy = state;
        sendBtn.disabled = state;
        sendBtn.loading = state;
    };

    const stop = function () {
        if (es) {
            try { es.close(); } catch (e) {}
            es = null;
        }
        setBusy(false);
    };

    const start = function () {
        if (busy) {
            return;
        }
        const q = (input.value || '').trim();
        if (!q) {
            $.toaster.warning('请输入指令');
            return;
        }

        const sep = document.createElement('div');
        sep.style.cssText = 'margin-top:12px;font-weight:600;';
        sep.textContent = '你: ' + q;
        out.appendChild(sep);
        lastEl = null;
        lastType = null;
        scrollBottom();

        setBusy(true);

        es = $.request.sse('/ai/api/test/run', {
            params: { q: q },
            autoReconnect: false,
            eventHandlers: {
                chunk: function (data) {
                    if (data && typeof data === 'object') {
                        appendChunk(data.type, data.text);
                    }
                },
                done: function () {
                    stop();
                },
            },
            onError: function () {
                if (busy) {
                    appendChunk('error', '连接中断');
                }
                stop();
            },
        });

        input.value = '';
    };

    sendBtn.addEventListener('click', start);

    clearBtn.addEventListener('click', function () {
        out.innerHTML = '';
        lastEl = null;
        lastType = null;
    });

    input.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            start();
        }
    });

    window.pageOnUnLoad = function () {
        stop();
    };
};
