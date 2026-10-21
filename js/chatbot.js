// ==========================================================================
// Chatbot tư vấn — Thiết kế theo Atelier System (Ink / Ivory / Rust)
// Backend: chatbot.php → includes/chatbot_helper.php (Hybrid: Rule + Groq)
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

    // 4. Các hàm tiện ích (Helpers)

    // Parse Markdown đơn giản sang HTML an toàn (khớp cú pháp helper)
    function parseMarkdown(text) {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')   // **bold**
            .replace(/\*(.*?)\*/g, '<em>$1</em>')               // *italic*
            .replace(/`([^`]+)`/g, '<code>$1</code>')           // `code`
            .replace(/^\s*[\-\*]\s+(.*)$/gim, '• $1')           // bullet list
            .replace(/\n/g, '<br>');
    }

    // Thêm tin nhắn
    function addMessage(sender, text) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${sender}`;

        if (sender === 'bot') {
            msgDiv.innerHTML = parseMarkdown(text);
        } else {
            msgDiv.textContent = text; // User text giữ nguyên — an toàn XSS
        }

        messagesDiv.appendChild(msgDiv);
        scrollToBottom();
        return msgDiv;
    }

    // Hiệu ứng "Đang nhập..."
    function showTyping() {
        const typing = document.createElement('div');
        typing.className = 'message bot chat-typing';
        typing.id = 'chat-typing';
        typing.innerHTML = '<span></span><span></span><span></span>';
        messagesDiv.appendChild(typing);
        scrollToBottom();
    }

    function hideTyping() {
        const typing = document.getElementById('chat-typing');
        if (typing) typing.remove();
    }

    // Tự động cuộn xuống tin nhắn mới nhất
    function scrollToBottom() {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    // Hiển thị danh sách sản phẩm gợi ý — KHỚP shape của chatbot_make_card()
    // {
    //   id, name, slug, url,
    //   price (number - giá bán cuối), old_price (number|null), image_url
    // }
    function displayProducts(products) {
        if (!Array.isArray(products) || products.length === 0) return;

        const container = document.createElement('div');
        container.className = 'product-suggestions';

        products.forEach(product => {
            if (!product || !product.id) return;

            // Ưu tiên url do backend trả (ổn định khi đổi routing), fallback ?id=
            const href     = product.url || `product-details.php?id=${encodeURIComponent(product.id)}`;
            const imageUrl = product.image_url || 'images/placeholder.jpg';

            const price    = Number(product.price || 0);
            const oldPrice = Number(product.old_price || 0);

            const priceFormatted = price > 0
                ? price.toLocaleString('vi-VN') + '₫'
                : 'Liên hệ';

            let priceHtml = `<span class="product-card-mini__price">${priceFormatted}</span>`;

            // Có giá gốc (đang sale) → gạch ngang
            if (oldPrice > price && price > 0) {
                priceHtml += ` <span class="product-card-mini__price-old"
                    style="text-decoration: line-through; opacity: 0.6; font-size: 0.85em;">
                    ${oldPrice.toLocaleString('vi-VN')}₫
                </span>`;
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

    // 5. GỬI TIN NHẮN — Typing delay ngắn (Groq phản hồi < 1s)
    const TYPING_MIN = 500;   // 0.5s — vẫn giữ cảm giác "đang gõ"
    const TYPING_MAX = 1000;  // 1.0s — đủ cho Groq trả lời xong

    async function sendMessage() {
        const text = input.value.trim();
        if (!text || isSending) return;

        // Delay mục tiêu ngẫu nhiên cho lần này
        const targetDelay = TYPING_MIN + Math.random() * (TYPING_MAX - TYPING_MIN);

        addMessage('user', text);
        input.value = '';
        isSending = true;
        sendBtn.disabled = true;
        showTyping();

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

            // Đợi đủ delay mục tiêu (nếu backend đã trả về sớm hơn)
            const elapsed   = Date.now() - startTime;
            const remaining = targetDelay - elapsed;
            if (remaining > 0) {
                await new Promise(resolve => setTimeout(resolve, remaining));
            }

            hideTyping();

            if (data.message) {
                addMessage('bot', data.message);
            }

            if (Array.isArray(data.products) && data.products.length > 0) {
                displayProducts(data.products);
            }
        } catch (error) {
            console.error('Chatbot Error:', error);

            const elapsed   = Date.now() - startTime;
            const remaining = targetDelay - elapsed;
            if (remaining > 0) {
                await new Promise(resolve => setTimeout(resolve, remaining));
            }

            hideTyping();
            addMessage('bot', 'Rất tiếc, đã có lỗi kết nối xảy ra. Vui lòng thử lại sau.');
        } finally {
            isSending = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    // 6. Gán các sự kiện
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

        // Tin nhắn chào mừng lần đầu mở
        if (!hasWelcomeMessage) {
            addMessage('bot', 'Xin chào! Mình có thể hỗ trợ bạn tư vấn size, phí ship, chính sách đổi trả, mã giảm giá hoặc tìm sản phẩm. Bạn cần hỗ trợ gì ạ?');
            hasWelcomeMessage = true;
        }

        setTimeout(() => input.focus(), 100);
    });

    // Đóng chatbox khi nhấn ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && chatbox.classList.contains('is-open')) {
            chatbox.classList.remove('is-open');
            chatToggle.classList.add('is-visible');
        }
    });

    // 7. Khởi tạo ban đầu
    chatbox.classList.remove('is-open');
    chatToggle.classList.add('is-visible');
});