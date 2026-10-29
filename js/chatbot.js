// ==========================================================================
// Chatbot tư vấn — Thiết kế theo Atelier System (Ink / Ivory / Rust)
// Backend: chatbot.php → includes/chatbot_helper.php (Hybrid: Rule + Groq/OpenAI)
// ==========================================================================

document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    // 1. Tạo cấu trúc DOM cho Chatbox
    const chatbox = document.createElement('div');
    chatbox.id = 'chatbox';
    chatbox.setAttribute('role', 'dialog');
    chatbox.setAttribute('aria-label', 'Tư vấn tự động');
    chatbox.innerHTML = `
        <div class="chat-header">
            <span class="chat-header__eyebrow">Tư vấn tự động</span>
            <button type="button" class="chat-close" id="chat-close" title="Đóng chat" aria-label="Đóng chat">&times;</button>
        </div>
        <div class="chat-messages" id="chat-messages" role="log" aria-live="polite"></div>
        <form class="chat-input" id="chat-form">
            <input type="text" id="chat-input" placeholder="Nhập tin nhắn..." autocomplete="off" aria-label="Nhập tin nhắn">
            <button type="submit" id="chat-send">Gửi</button>
        </form>
    `;
    document.body.appendChild(chatbox);

    // 2. Tạo nút Floating Toggle (Bong bóng chat)
    const chatToggle = document.createElement('button');
    chatToggle.id = 'chat-toggle';
    chatToggle.type = 'button';
    chatToggle.title = 'Mở chat tư vấn';
    chatToggle.setAttribute('aria-label', 'Mở chat tư vấn');
    chatToggle.innerHTML = `
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
    `;
    document.body.appendChild(chatToggle);

    // 3. Khai báo các phần tử DOM
    const messagesDiv = document.getElementById('chat-messages');
    const form        = document.getElementById('chat-form');
    const input       = document.getElementById('chat-input');
    const sendBtn     = document.getElementById('chat-send');
    const closeBtn    = document.getElementById('chat-close');

    let isSending = false;
    let hasWelcomeMessage = false;
    let typingTimer = null;

    // ==========================================================
    // 4. Helpers — Markdown, message, scroll
    // ==========================================================
    function parseMarkdown(text) {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/^\s*[\-\*]\s+(.*)$/gim, '• $1')
            .replace(/\n/g, '<br>');
    }

    function addMessage(sender, text) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${sender}`;
        if (sender === 'bot') {
            msgDiv.innerHTML = parseMarkdown(text);
        } else {
            msgDiv.textContent = text;
        }
        messagesDiv.appendChild(msgDiv);
        scrollToBottom();
        return msgDiv;
    }

    function scrollToBottom() {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    // ==========================================================
    // 5. ESTIMATE COMPLEXITY — mirror heuristic của backend
    //    (dùng để chọn thinking indicator phù hợp từ đầu)
    // ==========================================================
    function estimateComplexity(text) {
        const len = text.trim().length;
        const questionCount = (text.match(/\?/g) || []).length;
        const connectorCount = (text.match(/(và|hoặc|nhưng|thì|với|cùng|so sánh|khác nhau|ngoài ra|bên cạnh)/gi) || []).length;
        const needsAnalysis = /(so sánh|khác nhau|tại sao|vì sao|nên chọn|cách chọn|phân tích|ưu nhược|phù hợp với|tư vấn chi tiết|kết hợp|gợi ý outfit|set đồ|mix match)/i.test(text);

        let score = 0;
        if (len > 80)              score += 1;
        if (len > 200)             score += 2;
        if (questionCount >= 2)    score += 1;
        if (questionCount >= 3)    score += 1;
        if (connectorCount >= 2)   score += 1;
        if (needsAnalysis)         score += 2;

        if (score >= 5) return 'complex';
        if (score >= 2) return 'medium';
        return 'simple';
    }

    function getThinkingConfig(level) {
        if (level === 'complex') {
            return {
                messages: [
                    'Đang phân tích câu hỏi...',
                    'Đang đối chiếu thông tin sản phẩm...',
                    'Đang tổng hợp câu trả lời chi tiết...'
                ],
                intervalMs: 3500,
                minDelayMs: 6000
            };
        }
        if (level === 'medium') {
            return {
                messages: [
                    'Đang xem xét...',
                    'Đang chuẩn bị câu trả lời...'
                ],
                intervalMs: 2500,
                minDelayMs: 2000
            };
        }
        return {
            messages: ['Đang trả lời...'],
            intervalMs: 0,
            minDelayMs: 500
        };
    }

    // ==========================================================
    // 6. THINKING INDICATOR — 3 chấm + text xoay vòng
    // ==========================================================
    function showTyping(config) {
        hideTyping();

        const typing = document.createElement('div');
        typing.id = 'chat-typing';
        typing.className = 'message bot cb-thinking';
        typing.innerHTML = `
            <span class="cb-thinking__dots" aria-hidden="true">
                <span class="cb-dot"></span>
                <span class="cb-dot"></span>
                <span class="cb-dot"></span>
            </span>
            <span class="cb-thinking__text">${config.messages[0]}</span>
        `;
        messagesDiv.appendChild(typing);
        scrollToBottom();

        if (config.messages.length > 1 && config.intervalMs > 0) {
            let idx = 0;
            typingTimer = setInterval(() => {
                idx = (idx + 1) % config.messages.length;
                const txt = typing.querySelector('.cb-thinking__text');
                if (txt) {
                    txt.style.opacity = '0';
                    setTimeout(() => {
                        txt.textContent = config.messages[idx];
                        txt.style.opacity = '1';
                    }, 200);
                }
            }, config.intervalMs);
        }
    }

    function hideTyping() {
        if (typingTimer) {
            clearInterval(typingTimer);
            typingTimer = null;
        }
        const typing = document.getElementById('chat-typing');
        if (typing) typing.remove();
    }

    // ==========================================================
    // 7. HIỂN THỊ SẢN PHẨM — shape từ chatbot_make_card():
    //    { id, name, slug, url, price, old_price, image_url }
    // ==========================================================
    function displayProducts(products) {
        if (!Array.isArray(products) || products.length === 0) return;

        const container = document.createElement('div');
        container.className = 'product-suggestions';

        products.forEach(product => {
            if (!product || !product.id) return;

            const href     = product.url || `product-details.php?id=${encodeURIComponent(product.id)}`;
            const imageUrl = product.image_url || 'images/placeholder.jpg';

            const price    = Number(product.price || 0);
            const oldPrice = Number(product.old_price || 0);

            const priceFormatted = price > 0
                ? price.toLocaleString('vi-VN') + '₫'
                : 'Liên hệ';

            let priceHtml = `<span class="product-card-mini__price">${priceFormatted}</span>`;

            if (oldPrice > price && price > 0) {
                priceHtml += ` <span class="product-card-mini__price-old" style="text-decoration: line-through; opacity: 0.6; font-size: 0.85em;">${oldPrice.toLocaleString('vi-VN')}₫</span>`;
            }

            const card = document.createElement('a');
            card.className = 'product-card-mini';
            card.href = href;
            card.target = '_blank';
            card.rel = 'noopener noreferrer';

            card.innerHTML = `
                <img src="${imageUrl}" alt="${product.name || ''}" loading="lazy">
                <div class="product-card-mini__info">
                    <span class="product-card-mini__name">${product.name || ''}</span>
                    <div class="product-card-mini__price-box">${priceHtml}</div>
                </div>
            `;
            container.appendChild(card);
        });

        if (container.children.length > 0) {
            messagesDiv.appendChild(container);
            scrollToBottom();
        }
    }

    // ==========================================================
    // 8. GỬI TIN NHẮN
    // ==========================================================
    async function sendMessage() {
        const text = input.value.trim();
        if (!text || isSending) return;

        // (a) Ước lượng complexity — chọn indicator + minDelay
        const level  = estimateComplexity(text);
        const config = getThinkingConfig(level);

        addMessage('user', text);
        input.value = '';
        isSending = true;
        sendBtn.disabled = true;
        showTyping(config);

        const startTime = Date.now();

        try {
            const response = await fetch('chatbot.php?debug=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            // (b) Đảm bảo hiển thị thinking đủ minDelay
            const elapsed   = Date.now() - startTime;
            const remaining = config.minDelayMs - elapsed;
            if (remaining > 0) {
                await new Promise(resolve => setTimeout(resolve, remaining));
            }

            hideTyping();

            // (c) Log complexity (client đoán vs backend xác nhận)
            if (data._debug && data._debug.complexity) {
                const bc = data._debug.complexity.level;
                if (bc !== level) {
                    console.info(`[chatbot] complexity: client=${level} → server=${bc} (source: ${data._debug.source})`);
                }
            }

            if (data.message) {
                addMessage('bot', data.message);
            }

            if (Array.isArray(data.products) && data.products.length > 0) {
                displayProducts(data.products);
            }
        } catch (error) {
            console.error('Chatbot Error:', error);

            const elapsed   = Date.now() - startTime;
            const remaining = config.minDelayMs - elapsed;
            if (remaining > 0) {
                await new Promise(resolve => setTimeout(resolve, remaining));
            }

            hideTyping();
            addMessage('bot', 'Rất tiếc, đã có lỗi kết nối xảy ra. Vui lòng thử lại sau hoặc gọi hotline 1900 123 456.');
        } finally {
            isSending = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    // ==========================================================
    // 9. Gán sự kiện
    // ==========================================================
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        sendMessage();
    });

    closeBtn.addEventListener('click', () => {
        chatbox.classList.remove('is-open');
        chatToggle.classList.add('is-visible');
    });

    chatToggle.addEventListener('click', () => {
        chatbox.classList.add('is-open');
        chatToggle.classList.remove('is-visible');

        if (!hasWelcomeMessage) {
            addMessage('bot', 'Xin chào! Mình có thể hỗ trợ bạn tư vấn size, phí ship, chính sách đổi trả, mã giảm giá hoặc tìm sản phẩm. Bạn cần hỗ trợ gì ạ?');
            hasWelcomeMessage = true;
        }

        setTimeout(() => input.focus(), 100);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && chatbox.classList.contains('is-open')) {
            chatbox.classList.remove('is-open');
            chatToggle.classList.add('is-visible');
        }
    });

    // 10. Khởi tạo
    chatbox.classList.remove('is-open');
    chatToggle.classList.add('is-visible');
});