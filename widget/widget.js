/**
 * Kira AI & Zilliz Vector DB - 1-Line Universal Embeddable Chat Widget
 * Zero-dependency, pure vanilla JavaScript widget for any website.
 *
 * Usage:
 * <script src="https://YOUR-DOMAIN/public/widget.js" data-api="https://YOUR-DOMAIN/api.php"></script>
 */
(function() {
    'use strict';

    if (window.__KiraChatWidgetLoaded) return;
    window.__KiraChatWidgetLoaded = true;

    // 1. Configuration & Auto-Detection
    const currentScript = document.currentScript || (function() {
        const scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    const scriptSrc = currentScript ? currentScript.src : '';
    let defaultApi = '';
    if (scriptSrc) {
        try {
            const parsed = new URL(scriptSrc);
            defaultApi = parsed.origin + '/api.php';
        } catch(e) {
            defaultApi = '/api.php';
        }
    } else {
        defaultApi = '/api.php';
    }

    const CONFIG = {
        apiEndpoint: (currentScript && currentScript.getAttribute('data-api')) || defaultApi,
        title: (currentScript && currentScript.getAttribute('data-title')) || 'AI Assistant',
        subtitle: (currentScript && currentScript.getAttribute('data-subtitle')) || 'Powered by Kira AI & Zilliz DB',
        primaryColor: (currentScript && currentScript.getAttribute('data-color')) || '#6366f1',
        greeting: (currentScript && currentScript.getAttribute('data-greeting')) || 'Hello! How can I help you with this website today? / नमस्ते! मैं आपकी क्या सहायता कर सकता हूँ?',
        storageKey: 'kira_widget_session_v1',
        historyKey: 'kira_widget_messages_v1'
    };

    // 2. State
    let isOpen = false;
    let isGenerating = false;
    let sessionId = localStorage.getItem(CONFIG.storageKey) || ('sess_' + Math.random().toString(36).substring(2, 11) + Date.now().toString(36));
    localStorage.setItem(CONFIG.storageKey, sessionId);

    // 3. Inject CSS Styles
    const styleEl = document.createElement('style');
    styleEl.textContent = `
        .kira-widget-container {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999999;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            pointer-events: none;
        }
        .kira-widget-container * {
            box-sizing: border-box;
            pointer-events: auto;
        }
        /* Floating Button */
        .kira-launcher-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, ${CONFIG.primaryColor}, #8b5cf6);
            color: #ffffff;
            border: none;
            outline: none;
            cursor: pointer;
            box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.5), 0 8px 10px -6px rgba(99, 102, 241, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .kira-launcher-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 14px 28px -5px rgba(99, 102, 241, 0.6);
        }
        .kira-launcher-btn svg {
            width: 28px;
            height: 28px;
            fill: currentColor;
            transition: transform 0.25s ease;
        }
        /* Chat Box */
        .kira-chat-window {
            width: 380px;
            height: 580px;
            max-width: calc(100vw - 32px);
            max-height: calc(100vh - 110px);
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            display: none;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
            margin-bottom: 16px;
            animation: kira-pop-in 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes kira-pop-in {
            from { opacity: 0; transform: translateY(16px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        /* Header */
        .kira-chat-header {
            padding: 16px 20px;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.9));
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kira-header-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .kira-bot-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, ${CONFIG.primaryColor}, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: bold;
            font-size: 16px;
        }
        .kira-bot-details h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: #f8fafc;
        }
        .kira-bot-details p {
            margin: 2px 0 0 0;
            font-size: 11px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .kira-online-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
            display: inline-block;
        }
        .kira-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kira-icon-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .kira-icon-btn:hover {
            color: #f8fafc;
            background: rgba(255, 255, 255, 0.08);
        }
        /* Messages Body */
        .kira-messages-body {
            flex: 1;
            padding: 16px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
            scroll-behavior: smooth;
        }
        .kira-messages-body::-webkit-scrollbar {
            width: 5px;
        }
        .kira-messages-body::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        .kira-msg {
            max-width: 85%;
            font-size: 13.5px;
            line-height: 1.5;
            word-break: break-word;
            padding: 10px 14px;
            border-radius: 14px;
        }
        .kira-msg-bot {
            align-self: flex-start;
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
            border-bottom-left-radius: 4px;
        }
        .kira-msg-user {
            align-self: flex-end;
            background: linear-gradient(135deg, ${CONFIG.primaryColor}, #8b5cf6);
            color: #ffffff;
            border-bottom-right-radius: 4px;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
        }
        .kira-msg table {
            border-collapse: collapse;
            width: 100%;
            margin: 8px 0;
            font-size: 12px;
        }
        .kira-msg th, .kira-msg td {
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 5px 8px;
            text-align: left;
        }
        .kira-msg th {
            background: rgba(255, 255, 255, 0.08);
        }
        .kira-cursor {
            display: inline-block;
            width: 7px;
            height: 14px;
            background-color: ${CONFIG.primaryColor};
            vertical-align: middle;
            margin-left: 2px;
            animation: kira-blink 0.8s infinite;
        }
        @keyframes kira-blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }
        /* Footer Input */
        .kira-chat-footer {
            padding: 12px 16px;
            background: rgba(15, 23, 42, 0.95);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .kira-input-form {
            display: flex;
            gap: 8px;
            align-items: center;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 4px 6px 4px 14px;
            transition: border-color 0.15s ease;
        }
        .kira-input-form:focus-within {
            border-color: ${CONFIG.primaryColor};
        }
        .kira-input-field {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: #f8fafc;
            font-size: 13.5px;
            resize: none;
            max-height: 80px;
            font-family: inherit;
        }
        .kira-input-field::placeholder {
            color: #64748b;
        }
        .kira-send-btn {
            background: ${CONFIG.primaryColor};
            border: none;
            border-radius: 8px;
            width: 34px;
            height: 34px;
            color: #ffffff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.15s ease;
        }
        .kira-send-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .kira-brand-footer {
            font-size: 10px;
            color: #64748b;
            text-align: center;
            margin-top: 6px;
        }
        .kira-brand-footer a {
            color: #94a3b8;
            text-decoration: none;
        }
        /* Context Suggestion Chips */
        .kira-chips-container {
            display: flex;
            gap: 6px;
            padding: 8px 12px;
            overflow-x: auto;
            background: rgba(15, 23, 42, 0.9);
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            scrollbar-width: none;
        }
        .kira-chips-container::-webkit-scrollbar {
            display: none;
        }
        .kira-chip {
            background: rgba(99, 102, 241, 0.15);
            color: #c7d2fe;
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 9999px;
            padding: 4px 10px;
            font-size: 11.5px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.18s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .kira-chip:hover {
            background: rgba(99, 102, 241, 0.35);
            color: #ffffff;
            border-color: ${CONFIG.primaryColor};
            transform: translateY(-1px);
        }
        /* Mobile Responsive */
        @media (max-width: 480px) {
            .kira-widget-container {
                bottom: 16px;
                right: 16px;
            }
            .kira-chat-window {
                width: calc(100vw - 32px);
                height: calc(100vh - 90px);
                bottom: 80px;
            }
        }
    `;
    document.head.appendChild(styleEl);

    // 4. Inject DOM Elements
    const container = document.createElement('div');
    container.className = 'kira-widget-container';
    container.innerHTML = `
        <div class="kira-chat-window" id="kiraChatWindow">
            <div class="kira-chat-header">
                <div class="kira-header-info">
                    <div class="kira-bot-avatar">🤖</div>
                    <div class="kira-bot-details">
                        <h3>${escapeHtml(CONFIG.title)}</h3>
                        <p><span class="kira-online-dot"></span> ${escapeHtml(CONFIG.subtitle)}</p>
                    </div>
                </div>
                <div class="kira-header-actions">
                    <button class="kira-icon-btn" id="kiraClearBtn" title="Clear Conversation">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                    <button class="kira-icon-btn" id="kiraCloseBtn" title="Close">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
            </div>
            <div class="kira-messages-body" id="kiraMessagesBody"></div>
            <div class="kira-chips-container" id="kiraChips"></div>
            <div class="kira-chat-footer">
                <form class="kira-input-form" id="kiraForm">
                    <input type="text" class="kira-input-field" id="kiraInput" placeholder="Ask anything about this site..." autocomplete="off" />
                    <button type="submit" class="kira-send-btn" id="kiraSendBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>
                </form>
                <div class="kira-brand-footer">
                    ⚡ Kira AI Mini & Zilliz Vector Cloud
                </div>
            </div>
        </div>
        <button class="kira-launcher-btn" id="kiraLauncherBtn" aria-label="Open AI Chat">
            <svg id="kiraLauncherIconOpen" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
            <svg id="kiraLauncherIconClose" style="display:none;" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
    `;
    document.body.appendChild(container);

    // 5. Selectors
    const chatWindow = document.getElementById('kiraChatWindow');
    const launcherBtn = document.getElementById('kiraLauncherBtn');
    const iconOpen = document.getElementById('kiraLauncherIconOpen');
    const iconClose = document.getElementById('kiraLauncherIconClose');
    const closeBtn = document.getElementById('kiraCloseBtn');
    const clearBtn = document.getElementById('kiraClearBtn');
    const messagesBody = document.getElementById('kiraMessagesBody');
    const form = document.getElementById('kiraForm');
    const input = document.getElementById('kiraInput');
    const sendBtn = document.getElementById('kiraSendBtn');
    const chipsContainer = document.getElementById('kiraChips');

    // Load persisted messages and initial contextual suggestions
    renderInitialMessages();
    loadContextualSuggestions();

    // 6. Event Handlers
    launcherBtn.addEventListener('click', toggleChat);
    closeBtn.addEventListener('click', toggleChat);

    function loadContextualSuggestions(recentQuery = null) {
        if (!chipsContainer) return;
        const payload = {
            action: 'get_suggestions',
            url: window.location.href,
            page_title: document.title || '',
            recent_query: recentQuery || undefined
        };
        fetch(CONFIG.apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && Array.isArray(data.suggestions)) {
                renderChips(data.suggestions);
            }
        })
        .catch(() => {});
    }

    function renderChips(suggestions) {
        if (!chipsContainer) return;
        chipsContainer.innerHTML = '';
        if (!suggestions || suggestions.length === 0) {
            chipsContainer.style.display = 'none';
            return;
        }
        chipsContainer.style.display = 'flex';
        suggestions.forEach(item => {
            const btn = document.createElement('button');
            btn.className = 'kira-chip';
            btn.type = 'button';
            btn.innerHTML = escapeHtml(item.label || item.query);
            btn.onclick = function() {
                if (!isGenerating) {
                    sendMessage(item.query || item.label);
                }
            };
            chipsContainer.appendChild(btn);
        });
    }

    clearBtn.addEventListener('click', function() {
        if (confirm('Clear chat history?')) {
            sessionId = 'sess_' + Math.random().toString(36).substring(2, 11) + Date.now().toString(36);
            localStorage.setItem(CONFIG.storageKey, sessionId);
            localStorage.removeItem(CONFIG.historyKey);
            messagesBody.innerHTML = '';
            appendBotMessage(CONFIG.greeting);
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || isGenerating) return;
        input.value = '';
        sendMessage(text);
    });

    function toggleChat() {
        isOpen = !isOpen;
        if (isOpen) {
            chatWindow.style.display = 'flex';
            iconOpen.style.display = 'none';
            iconClose.style.display = 'block';
            input.focus();
            scrollToBottom();
        } else {
            chatWindow.style.display = 'none';
            iconOpen.style.display = 'block';
            iconClose.style.display = 'none';
        }
    }

    function renderInitialMessages() {
        const saved = localStorage.getItem(CONFIG.historyKey);
        if (saved) {
            try {
                const list = JSON.parse(saved);
                if (Array.isArray(list) && list.length > 0) {
                    list.forEach(m => {
                        if (m.role === 'user') appendUserMessage(m.text, false);
                        else appendBotMessage(m.text, false);
                    });
                    return;
                }
            } catch(e) {}
        }
        appendBotMessage(CONFIG.greeting, false);
    }

    function saveMessage(role, text) {
        let list = [];
        try {
            list = JSON.parse(localStorage.getItem(CONFIG.historyKey) || '[]');
        } catch(e) {}
        list.push({ role, text, time: Date.now() });
        if (list.length > 30) list = list.slice(-30);
        localStorage.setItem(CONFIG.historyKey, JSON.stringify(list));
    }

    function appendUserMessage(text, shouldSave = true) {
        const div = document.createElement('div');
        div.className = 'kira-msg kira-msg-user';
        div.textContent = text;
        messagesBody.appendChild(div);
        scrollToBottom();
        if (shouldSave) saveMessage('user', text);
    }

    function appendBotMessage(rawText, shouldSave = true) {
        const div = document.createElement('div');
        div.className = 'kira-msg kira-msg-bot';
        div.innerHTML = formatMarkdown(rawText);
        messagesBody.appendChild(div);
        scrollToBottom();
        if (shouldSave) saveMessage('assistant', rawText);
    }

    function scrollToBottom() {
        messagesBody.scrollTop = messagesBody.scrollHeight;
    }

    // 7. Send message with Real-time SSE streaming & Webpage context
    async function sendMessage(userText) {
        appendUserMessage(userText, true);
        isGenerating = true;
        sendBtn.disabled = true;

        // Create streaming bot bubble
        const botBubble = document.createElement('div');
        botBubble.className = 'kira-msg kira-msg-bot';
        const contentSpan = document.createElement('span');
        const cursorSpan = document.createElement('span');
        cursorSpan.className = 'kira-cursor';
        botBubble.appendChild(contentSpan);
        botBubble.appendChild(cursorSpan);
        messagesBody.appendChild(botBubble);
        scrollToBottom();

        let fullReply = '';

        try {
            const payload = {
                message: userText,
                session_id: sessionId,
                stream: true,
                current_url: window.location.href,
                page_title: document.title || 'Webpage'
            };

            const response = await fetch(CONFIG.apiEndpoint + '?stream=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ' error');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // keep partial line

                for (let line of lines) {
                    line = line.trim();
                    if (!line.startsWith('data:')) continue;
                    const jsonStr = line.replace(/^data:\s*/, '').trim();
                    if (jsonStr === '[DONE]') break;

                    try {
                        const parsed = JSON.parse(jsonStr);
                        const delta = parsed.choices?.[0]?.delta?.content || '';
                        if (delta) {
                            fullReply += delta;
                            contentSpan.innerHTML = formatMarkdown(fullReply);
                            scrollToBottom();
                        }
                    } catch(e) {}
                }
            }

            if (!fullReply.trim()) {
                fullReply = 'I have received your request. (No text generated)';
                contentSpan.innerHTML = formatMarkdown(fullReply);
            }

            saveMessage('assistant', fullReply);

        } catch (err) {
            contentSpan.innerHTML = '<span style="color:#f87171;">⚠️ Error connecting to AI: ' + escapeHtml(err.message) + '</span>';
        } finally {
            cursorSpan.remove();
            isGenerating = false;
            sendBtn.disabled = false;
            input.focus();
            scrollToBottom();
            // Refresh contextual suggestions for follow-up questions
            loadContextualSuggestions(userText);
        }
    }

    // Simple markdown formatter for bold, code, tables, and line breaks
    function formatMarkdown(str) {
        if (!str) return '';
        let escaped = escapeHtml(str);

        // Bold
        escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Italic
        escaped = escaped.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Inline code
        escaped = escaped.replace(/`([^`]+)`/g, '<code style="background:rgba(255,255,255,0.1);padding:2px 4px;border-radius:4px;font-size:12px;">$1</code>');
        // Simple tables
        if (escaped.includes('|')) {
            escaped = escaped.replace(/(?:\|[^\n]+\|\r?\n?)+/g, function(tableBlock) {
                const rows = tableBlock.trim().split('\n').filter(r => r.includes('|') && !r.includes('---'));
                if (rows.length === 0) return tableBlock;
                let html = '<table>';
                rows.forEach((row, rIdx) => {
                    const cols = row.split('|').map(c => c.trim()).filter((c, i, a) => i > 0 && i < a.length - 1);
                    if (cols.length > 0) {
                        html += '<tr>' + cols.map(c => (rIdx === 0 ? `<th>${c}</th>` : `<td>${c}</td>`)).join('') + '</tr>';
                    }
                });
                html += '</table>';
                return html;
            });
        }
        // Newlines
        escaped = escaped.replace(/\n/g, '<br>');
        return escaped;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

})();

