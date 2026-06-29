(() => {
    const shell = document.querySelector('.page-shell');
    const form = document.getElementById('chatForm');
    const input = document.getElementById('messageInput');
    const button = document.getElementById('sendButton');
    const messages = document.getElementById('messages');
    const typingIndicator = document.getElementById('typingIndicator');
    const countdown = document.getElementById('countdown');
    const systemMessage = document.getElementById('systemMessage');
    const finishedPanel = document.getElementById('finishedPanel');
    const ctaLinks = Array.from(document.querySelectorAll('[data-cta-value]'));
    const fixedCtaBar = document.getElementById('fixedCtaBar');
    const fixedCtaLinks = Array.from(document.querySelectorAll('[data-fixed-cta]'));
    const trialSeconds = Number(shell?.dataset.trialSeconds || 300);
    const storeKey = String(shell?.dataset.storeKey || 'seika').replace(/[^a-z0-9_-]/gi, '') || 'seika';
    const logLabel = `TWIN ${storeKey.toUpperCase()}`;
    const apiUrl = '/crew-onboarding/chat_api.php';
    const gaEventMap = {
        chat_start: 'twin_chat_start',
        message_sent: 'twin_chat_message_send',
        line: 'twin_line_click',
        fixed_line: 'twin_line_click',
        price: 'twin_price_click',
        fixed_price: 'twin_price_click',
        instagram: 'twin_instagram_click',
        fixed_instagram: 'twin_instagram_click',
    };

    let remaining = trialSeconds;
    let finishedLogged = false;
    let sending = false;
    let ctaViewed = false;

    const sessionToken = getSessionToken();
    console.log(`[${logLabel}]`, 'chat_start', { sessionToken });
    trackGaEvent('chat_start');

    function getSessionToken() {
        const key = `twin_${storeKey}_session_token`;
        const current = window.sessionStorage.getItem(key);

        if (current) {
            return current;
        }

        const bytes = new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
        const token = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        window.sessionStorage.setItem(key, token);
        return token;
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function addMessage(sender, text) {
        const row = document.createElement('div');
        row.className = `message-row ${sender}`;

        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.innerHTML = escapeHtml(text);

        row.appendChild(bubble);
        messages.appendChild(row);
        scrollToBottom();
    }

    function scrollToBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function addLineCta(cta) {
        if (!cta || !cta.show) {
            return;
        }

        const url = cta.url || shell?.dataset.lineUrl || '';
        if (!url) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'message-row twin line-cta-row';

        const wrap = document.createElement('div');
        wrap.className = 'line-cta';

        if (cta.message) {
            const note = document.createElement('p');
            note.className = 'line-cta-note';
            note.textContent = cta.message;
            wrap.appendChild(note);
        }

        const link = document.createElement('a');
        link.className = 'line-cta-button';
        link.href = url;
        link.textContent = cta.label || 'LINEで相談・予約';
        link.setAttribute('data-cta-value', 'line');
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            link.setAttribute('aria-disabled', 'true');
            trackCtaClick('line');
            await sendEvent('cta_click', 'line');
            window.location.href = link.href;
        });
        wrap.appendChild(link);

        row.appendChild(wrap);
        messages.appendChild(row);
        scrollToBottom();

        // 表示時に cta_view を記録
        sendEvent('cta_view', 'line');
    }

    function setTyping(visible) {
        if (!typingIndicator) {
            return;
        }

        typingIndicator.hidden = !visible;
        typingIndicator.style.display = visible ? 'flex' : 'none';
        if (visible) {
            scrollToBottom();
        }
    }

    function setNotice(text) {
        systemMessage.textContent = text || '';
    }

    function formatTime(seconds) {
        const minutes = Math.floor(seconds / 60).toString().padStart(2, '0');
        const secs = Math.max(0, seconds % 60).toString().padStart(2, '0');
        return `${minutes}:${secs}`;
    }

    async function postJson(payload) {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                session_token: sessionToken,
                ...payload,
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.ok) {
            throw new Error(data.error || '送信できませんでした。少し時間をおいてお試しください。');
        }

        return data;
    }

    async function sendEvent(eventName, eventValue = null) {
        console.log(`[${logLabel}]`, eventName, { eventValue });

        try {
            await postJson({
                action: 'event',
                event_name: eventName,
                event_value: eventValue,
            });
        } catch (error) {
            console.log(`[${logLabel}]`, 'event_error', error.message);
        }
    }

    function trackGaEvent(key, params = {}) {
        const eventName = gaEventMap[key] || key;

        if (typeof window.gtag !== 'function') {
            return;
        }

        window.gtag('event', eventName, {
            store_key: storeKey,
            ...params,
        });
    }

    function trackCtaClick(value) {
        const normalizedValue = String(value || '').replace(/^fixed_/, '');
        trackGaEvent(value || normalizedValue, {
            cta_value: normalizedValue,
        });
    }

    async function sendCtaViews() {
        if (ctaViewed) {
            return;
        }

        ctaViewed = true;
        await Promise.all(ctaLinks.map((link) => sendEvent('cta_view', link.dataset.ctaValue || null)));
    }

    // ─── 選択肢ボタン ────────────────────────────────────────
    function extractOptions(text) {
        const circled = ['①','②','③','④','⑤','⑥','⑦','⑧','⑨','⑩'];
        return text.split('\n')
            .map(l => l.trim())
            .filter(l => l && circled.some(c => l.startsWith(c)));
    }

    function clearOptions() {
        messages.querySelectorAll('.recruit-options').forEach(el => el.remove());
    }

    function addOptions(options) {
        if (!options.length) return;
        clearOptions();
        const wrap = document.createElement('div');
        wrap.className = 'recruit-options';
        for (const opt of options) {
            const btn = document.createElement('button');
            btn.className = 'recruit-option-btn';
            btn.type = 'button';
            btn.textContent = opt;
            btn.addEventListener('click', () => {
                clearOptions();
                submitText(opt);
            });
            wrap.appendChild(btn);
        }
        messages.appendChild(wrap);
        scrollToBottom();
    }
    // ─────────────────────────────────────────────────────────

    async function submitText(text) {
        if (sending || remaining <= 0) return;

        clearOptions();
        addMessage('user', text);
        sending = true;
        button.disabled = true;
        input.disabled = true;
        setNotice('');
        console.log(`[${logLabel}]`, 'message_sent', { length: text.length });
        trackGaEvent('message_sent', { message_length: text.length });

        try {
            setTyping(true);
            const typingStartedAt = Date.now();
            const data = await postJson({ action: 'message', message: text });
            const elapsed = Date.now() - typingStartedAt;
            if (elapsed < 300) {
                await new Promise((resolve) => window.setTimeout(resolve, 300 - elapsed));
            }
            setTyping(false);
            addMessage('twin', data.reply || 'ありがとうございます。少しだけ詳しく聞かせてください。');
            addLineCta(data.line_cta);
            addOptions(extractOptions(data.reply || ''));
            if (data.intent === 'recruit_finished' && data.reply && data.reply.includes('保証時給')) {
                await finishTrial();
            }
        } catch (error) {
            setTyping(false);
            setNotice(error.message || '送信できませんでした。少し時間をおいてお試しください。');
        } finally {
            sending = false;
            button.disabled = remaining <= 0;
            input.disabled = remaining <= 0;
            input.focus();
            scrollToBottom();
        }
    }

    async function finishTrial() {
        if (finishedLogged) {
            return;
        }

        finishedLogged = true;
        clearOptions();
        input.disabled = true;
        button.disabled = true;
        finishedPanel.hidden = false;
        setNotice('');
        // v0.8.8: 固定CTAバーのLINEボタン強調
        if (fixedCtaBar) {
            fixedCtaBar.classList.add('is-finished');
        }
        const lineBtn = fixedCtaBar?.querySelector('.fixed-cta-line');
        if (lineBtn) {
            lineBtn.textContent = 'LINE応募';
        }
        await sendCtaViews();
        await sendEvent('trial_finished');
    }

    function tick() {
        // タイマー制限なし: カウントダウン非表示のみ
        if (countdown) countdown.hidden = true;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const text = input.value.trim();
        if (!text) {
            setNotice('メッセージを入力してください。');
            return;
        }
        if (text.length > 500) {
            setNotice('500文字以内で入力してください。');
            return;
        }

        input.value = '';
        input.style.height = '';
        await submitText(text);
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 120)}px`;
    });

    ctaLinks.forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            link.setAttribute('aria-disabled', 'true');
            trackCtaClick(link.dataset.ctaValue || null);
            await sendEvent('cta_click', link.dataset.ctaValue || null);
            window.location.href = link.href;
        });
    });

    // v0.8.8: 固定CTAバーのクリックイベント（fixed_line / fixed_price / fixed_instagram）
    fixedCtaLinks.forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            const href = link.href;
            trackCtaClick(link.dataset.fixedCta || null);
            await sendEvent('cta_click', link.dataset.fixedCta || null);
            window.location.href = href;
        });
    });

    // v0.8.9: 固定CTAバーの表示ログ（1セッション1回だけ保存）
    const fixedCtaViewedKey = 'twin_fixed_cta_viewed';
    if (fixedCtaLinks.length > 0 && !window.sessionStorage.getItem(fixedCtaViewedKey)) {
        window.sessionStorage.setItem(fixedCtaViewedKey, '1');
        fixedCtaLinks.forEach((link) => {
            sendEvent('cta_view', link.dataset.fixedCta || null);
        });
    }

    scrollToBottom();

    // 初期メッセージ（index.php に静的描画された最初のボット発言）の選択肢をボタン化
    const initialBubble = messages.querySelector('.message-row.twin .bubble');
    if (initialBubble) {
        const opts = extractOptions(initialBubble.textContent || '');
        if (opts.length) addOptions(opts);
    }

    tick();
})();
