<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Lấy thông tin ngân hàng
$stmtBank = $pdo->query("SELECT bank_name, account_no, account_name, bank_bin, qr_image, transfer_note FROM bank_settings WHERE id = 1");
$bankInfo = $stmtBank->fetch(PDO::FETCH_ASSOC) ?: [
    'bank_name' => '', 'account_no' => '', 'account_name' => '', 
    'bank_bin' => '970436', 'qr_image' => '', 'transfer_note' => ''
];
?>

<style>
    :root {
        --c-primary: #a8461f;
        --c-bg: #faf8f4;
        --c-card: #ffffff;
        --c-border: #efeae0;
        --c-border-strong: #ddd5c7;
        --c-text: #24211d;
        --c-text-muted: #8a8377;
        --c-shadow-sm: 0 1px 2px rgba(36, 33, 29, 0.04);
        --c-shadow-md: 0 8px 24px rgba(36, 33, 29, 0.07);
        --r-sm: 8px;
        --r-md: 12px;
        --r-lg: 16px;
    }

    .pos-page { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .pos-grid { display: grid; grid-template-columns: 1fr 420px; gap: 24px; align-items: start; }
    .pos-card { background: var(--c-card); border: 1px solid var(--c-border); border-radius: var(--r-md); padding: 24px; margin-bottom: 24px; box-shadow: var(--c-shadow-sm); }
    .pos-title { font-family: 'Playfair Display', Georgia, serif; font-size: 1.25rem; font-weight: 600; margin: 0 0 20px; color: var(--c-text); border-bottom: 1px solid var(--c-border); padding-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .pos-title i { color: var(--c-primary); font-size: 1.3rem; }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #374151; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--c-border-strong); border-radius: var(--r-sm); font-size: 0.95rem; font-family: inherit; background: var(--c-bg); transition: all 0.2s; }
    .form-control:focus { outline: none; border-color: var(--c-primary); background: #fff; box-shadow: 0 0 0 3px rgba(168,70,31,0.1); }
    
    /* Search Product */
    .search-wrap { position: relative; }
    .search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--c-border-strong); border-radius: var(--r-sm); box-shadow: var(--c-shadow-md); z-index: 9999; max-height: 300px; overflow-y: auto; display: none; margin-top: 4px; }
    .search-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background 0.15s; }
    .search-item:hover { background: #fdf7f3; }
    .search-item img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }
    .search-item-info { flex: 1; }
    .search-item-name { font-weight: 600; font-size: 0.9rem; color: var(--c-text); }
    .search-item-price { font-size: 0.8rem; color: var(--c-primary); font-family: 'IBM Plex Mono', monospace; }

    /* Cart Table */
    .cart-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-top: 16px; }
    .cart-table th { text-align: left; padding: 10px; background: #fbf9f5; color: var(--c-text-muted); font-size: 0.75rem; text-transform: uppercase; border-bottom: 1px solid var(--c-border); }
    .cart-table td { padding: 12px 10px; border-bottom: 1px solid var(--c-border); vertical-align: middle; }
    .cart-table .qty-input { width: 60px; padding: 6px; border: 1px solid var(--c-border-strong); border-radius: 6px; text-align: center; }
    .cart-table .size-select { padding: 6px; border: 1px solid var(--c-border-strong); border-radius: 6px; background: #fff; outline: none; width: 90px; }
    .btn-remove { background: none; border: none; color: #dc2626; cursor: pointer; padding: 4px; font-size: 1.1rem; }
    
    /* Method Options */
    .method-group { display: grid; gap: 10px; }
    .method-option { display: flex; align-items: flex-start; gap: 10px; padding: 14px; border: 1px solid var(--c-border-strong); border-radius: var(--r-sm); cursor: pointer; transition: all 0.2s; background: #fff; }
    .method-option:hover { border-color: var(--c-primary); }
    .method-option input[type="radio"] { accent-color: var(--c-primary); transform: scale(1.2); margin-top: 2px; }
    .method-option .m-title { font-weight: 600; font-size: 0.95rem; color: #374151; display:flex; align-items:center; gap:6px; }
    .method-option .m-desc { font-size: 0.8rem; color: #6b7280; margin-top: 4px; }
    .method-option.active { border-color: var(--c-primary); background: #fdf7f3; box-shadow: 0 0 0 1px var(--c-primary); }

    /* Payment Status Toggle */
    .pay-status-toggle { display: flex; background: #e5e7eb; border-radius: 8px; padding: 4px; margin-bottom: 15px; }
    .pay-status-toggle label { flex: 1; text-align: center; padding: 8px; font-size: 0.85rem; font-weight: 600; color: #4b5563; cursor: pointer; border-radius: 6px; transition: all 0.2s; margin: 0; }
    .pay-status-toggle input[type="radio"] { display: none; }
    .pay-status-toggle input[type="radio"]:checked + label { background: #fff; color: var(--c-primary); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

    /* Summary */
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; color: #4b5563; }
    .summary-total { border-top: 2px solid var(--c-border); padding-top: 16px; margin-top: 16px; font-size: 1.3rem; font-weight: 700; color: var(--c-primary); }

    /* Bank & Card Info */
    .bank-info-box, .card-info-box { display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 15px; margin-top: 15px; }
    .bank-info-box.show, .card-info-box.show { display: block; }
    
    .card-info-box { background: #faf8f4; border-color: #efeae0; }
    .card-info-box .form-control { background: #fff; }

    /* Voucher */
    .voucher-box { background: #f8f9fa; border: 1px dashed #d1d5db; border-radius: 8px; padding: 12px; margin-bottom: 15px; }
    .voucher-input-wrap { display: flex; gap: 8px; }
    .voucher-input-wrap input { text-transform: uppercase; }
    .applied-voucher { display: none; justify-content: space-between; align-items: center; background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; margin-top: 8px; }
    .btn-remove-voucher { background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 0.8rem; text-decoration: underline; }
</style>

<div class="pos-page">
    <div class="admin-page-head" style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--c-border); display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h1 style="font-family:'Playfair Display', serif; font-size:2rem; margin:0; color:var(--c-text);">Tạo đơn hàng mới</h1>
            <div style="font-size:0.85rem; color:var(--c-text-muted); margin-top:4px;">Lên đơn thủ công cho khách hàng</div>
        </div>
        <a href="orders.php" style="text-decoration:none; color:var(--c-text-muted); font-size:14px; display:flex; align-items:center; gap:4px;">
            <i class="ph ph-arrow-left"></i> Quay lại danh sách
        </a>
    </div>

    <form id="createOrderForm">
        <div class="pos-grid">
            
            <!-- CỘT TRÁI: KHÁCH HÀNG & SẢN PHẨM -->
            <div>
                <!-- Khách hàng -->
                <div class="pos-card">
                    <h2 class="pos-title"><i class="ph ph-user"></i> Thông tin người nhận</h2>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label>Họ tên khách hàng *</label>
                            <input type="text" name="full_name" class="form-control" placeholder="VD: Nguyễn Văn A">
                        </div>
                        <div class="form-group">
                            <label>Số điện thoại *</label>
                            <input type="tel" name="phone" class="form-control" placeholder="VD: 0912345678">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Địa chỉ giao hàng *</label>
                        <input type="text" name="address" class="form-control" placeholder="Số nhà, đường, phường, quận, tỉnh...">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Ghi chú đơn hàng</label>
                        <input type="text" name="note" class="form-control" placeholder="VD: Giao giờ hành chính, gọi trước khi giao...">
                    </div>
                </div>

                <!-- Sản phẩm -->
                <div class="pos-card">
                    <h2 class="pos-title"><i class="ph ph-shopping-bag"></i> Sản phẩm</h2>
                    
                    <div class="search-wrap">
                        <label>Tìm kiếm sản phẩm để thêm vào đơn</label>
                        <input type="text" id="productSearch" class="form-control" placeholder="Nhập tên sản phẩm..." autocomplete="off">
                        <div class="search-results" id="searchResults"></div>
                    </div>

                    <table class="cart-table" id="cartTable" style="display:none;">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th style="width:110px;">Size</th>
                                <th style="width:90px;">SL</th>
                                <th style="width:120px; text-align:right;">Đơn giá</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="cartBody"></tbody>
                    </table>
                    
                    <div id="emptyCart" style="text-align:center; padding: 30px; color:#8a8377;">
                        <i class="ph ph-shopping-cart" style="font-size:2rem; opacity:0.5; margin-bottom:8px;"></i>
                        <div>Chưa có sản phẩm nào. Vui lòng tìm kiếm và thêm.</div>
                    </div>
                </div>
            </div>

            <!-- CỘT PHẢI: VẬN CHUYỂN & THANH TOÁN -->
            <div class="pos-sticky" style="position: sticky; top: 20px;">
                
                <!-- Vận chuyển -->
                <div class="pos-card">
                    <h2 class="pos-title" style="border:none; margin-bottom:15px;"><i class="ph ph-truck"></i> Vận chuyển</h2>
                    
                    <div class="method-group">
                        <label class="method-option active">
                            <input type="radio" name="shipping_type" value="pickup" checked onchange="toggleShipping()">
                            <div>
                                <div class="m-title"><i class="ph ph-storefront"></i> Khách lấy tại cửa hàng</div>
                                <div class="m-desc">Khách tự đến shop nhận hàng</div>
                            </div>
                        </label>

                        <label class="method-option">
                            <input type="radio" name="shipping_type" value="delivery" onchange="toggleShipping()">
                            <div>
                                <div class="m-title"><i class="ph ph-package"></i> Giao qua đơn vị vận chuyển</div>
                                <div class="m-desc">Shop gửi hàng qua bưu tá</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Thanh toán -->
                <div class="pos-card">
                    <h2 class="pos-title" style="border:none; margin-bottom:15px;"><i class="ph ph-credit-card"></i> Thanh toán</h2>
                    
                    <div id="paymentStatusGroup" style="display:none; margin-bottom: 15px;">
                        <label style="display:block; font-size:0.85rem; font-weight:600; color:#374151; margin-bottom:8px;">Trạng thái thanh toán</label>
                        <div class="pay-status-toggle">
                            <input type="radio" name="payment_status" value="paid" id="ps_paid">
                            <label for="ps_paid">Đã thanh toán</label>
                            
                            <input type="radio" name="payment_status" value="unpaid" id="ps_unpaid" checked>
                            <label for="ps_unpaid">Chưa thanh toán (COD)</label>
                        </div>
                    </div>

                    <div class="method-group">
                        <label class="method-option active">
                            <input type="radio" name="payment_method" value="cod" checked onchange="togglePaymentMethod()">
                            <div>
                                <div class="m-title"><i class="ph ph-money" style="color:#166534;"></i> <span id="cashLabel">Tiền mặt tại cửa hàng</span></div>
                            </div>
                        </label>
                        <label class="method-option">
                            <input type="radio" name="payment_method" value="bank" onchange="togglePaymentMethod()">
                            <div>
                                <div class="m-title"><i class="ph ph-bank" style="color:#1d4ed8;"></i> Chuyển khoản ngân hàng</div>
                            </div>
                        </label>
                        <label class="method-option">
                            <input type="radio" name="payment_method" value="card" onchange="togglePaymentMethod()">
                            <div>
                                <div class="m-title"><i class="ph ph-credit-card" style="color:#a8461f;"></i> Thanh toán bằng thẻ</div>
                            </div>
                        </label>
                    </div>

                    <!-- Thông tin chuyển khoản -->
                    <div class="bank-info-box" id="bankInfoBox">
                        <div style="font-size:0.8rem; font-weight:700; color:#1d4ed8; margin-bottom:10px; text-transform:uppercase; text-align:center;">Thông tin chuyển khoản</div>
                        <div style="font-family:'IBM Plex Mono',monospace; font-size:0.9rem; line-height:1.6; text-align:center;">
                            <div><b><?= htmlspecialchars($bankInfo['bank_name'] ?: 'Chưa cấu hình ngân hàng') ?></b></div>
                            <div>STK: <b><?= htmlspecialchars($bankInfo['account_no'] ?: '---') ?></b></div>
                            <div>Chủ TK: <b><?= htmlspecialchars($bankInfo['account_name'] ?: '---') ?></b></div>
                        </div>
                        <div style="text-align:center;">
                            <img id="vietQrImg" src="" style="width:200px; height:200px; margin-top:10px; border-radius:8px; border:1px solid #bfdbfe; object-fit: contain;" alt="QR Chuyển khoản">
                        </div>
                    </div>

                    <!-- Thông tin thẻ -->
                    <div class="card-info-box" id="cardInfoBox">
                        <div style="font-size:0.8rem; font-weight:700; color:#a8461f; margin-bottom:10px; text-transform:uppercase;">Thông tin thẻ khách hàng</div>
                        <div class="form-group">
                            <label>Số thẻ *</label>
                            <input type="text" name="card_number" id="card_number" class="form-control" placeholder="0000 0000 0000 0000" maxlength="19">
                        </div>
                        <div class="form-group">
                            <label>Tên in trên thẻ *</label>
                            <input type="text" name="card_name" id="card_name" class="form-control" placeholder="NGUYEN VAN A" style="text-transform:uppercase;">
                        </div>
                        <div style="display:flex; gap:10px;">
                            <div class="form-group" style="flex:1;">
                                <label>Ngày hết hạn *</label>
                                <input type="text" name="card_expiry" id="card_expiry" class="form-control" placeholder="MM/YY" maxlength="5">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>CVV *</label>
                                <input type="password" name="card_cvv" id="card_cvv" class="form-control" placeholder="123" maxlength="3">
                            </div>
                        </div>
                    </div>

                    <hr style="border:0; border-top:1px dashed var(--c-border); margin:20px 0;">

                    <!-- MÃ GIẢM GIÁ -->
                    <div class="voucher-box">
                        <label style="display:block; font-size:0.85rem; font-weight:600; color:#374151; margin-bottom:8px;">Mã giảm giá</label>
                        <div class="voucher-input-wrap" id="voucherInputWrap">
                            <input type="text" id="voucherCode" class="form-control" placeholder="Nhập mã (VD: SALE20)" oninput="this.value = this.value.toUpperCase()">
                            <button type="button" onclick="applyVoucher()" class="btn-adm btn-adm--ghost" style="padding: 0 15px;">Áp dụng</button>
                        </div>
                        <div id="voucherMsg" style="font-size:0.75rem; margin-top:6px; min-height:1em;"></div>
                        
                        <!-- Hiển thị mã đã áp dụng -->
                        <div class="applied-voucher" id="appliedVoucher">
                            <span><i class="ph ph-ticket"></i> <span id="appliedVoucherCode"></span></span>
                            <span>-<span id="discountText">0đ</span> <button type="button" class="btn-remove-voucher" onclick="removeVoucher()">[Hủy]</button></span>
                        </div>
                    </div>

                    <div class="summary-row">
                        <span>Tạm tính (<span id="totalQty">0</span> SP)</span>
                        <span id="subTotal" style="font-family:'IBM Plex Mono',monospace; font-weight:600;">0đ</span>
                    </div>
                    <div class="summary-row" id="discountRow" style="display:none; color:#166534;">
                        <span>Giảm giá</span>
                        <span id="discountAmount" style="font-family:'IBM Plex Mono',monospace; font-weight:600;">-0đ</span>
                    </div>
                    
                    <div class="summary-row summary-total">
                        <span>TỔNG CỘNG</span>
                        <span id="grandTotal" style="font-family:'IBM Plex Mono',monospace;">0đ</span>
                    </div>

                    <!-- Nút Submit (sẽ thay đổi text dựa vào hình thức giao hàng) -->
                    <button type="submit" id="btnSubmitOrder" class="btn-adm btn-adm--primary" style="width:100%; padding:16px; font-size:1rem; margin-top:15px; justify-content:center;">
                        <i class="ph ph-currency-circle-dollar"></i> Thanh toán & Hoàn tất
                    </button>
                </div>

            </div>
        </div>
    </form>
</div>

<script>
    const CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
    
    const BANK_BIN = '<?= htmlspecialchars($bankInfo['bank_bin']) ?>';
    const BANK_NO = '<?= htmlspecialchars($bankInfo['account_no']) ?>';
    const BANK_QR_IMAGE = '<?= htmlspecialchars($bankInfo['qr_image'] ?? '') ?>';
    const TRANSFER_NOTE = '<?= htmlspecialchars($bankInfo['transfer_note'] ?? 'Thanh toan don hang') ?>';
    
    let cart = [];
    let searchResultsList = [];
    
    let subTotalAmount = 0;
    let discountAmount = 0;
    let finalTotalAmount = 0;
    let appliedVoucher = null;
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // ==============================
    // 1. XỬ LÝ VẬN CHUYỂN
    // ==============================
    function toggleShipping() {
        const type = document.querySelector('input[name="shipping_type"]:checked').value;
        const payStatusGroup = document.getElementById('paymentStatusGroup');
        const cashLabel = document.getElementById('cashLabel');
        const btnSubmit = document.getElementById('btnSubmitOrder');

        document.querySelectorAll('input[name="shipping_type"]').forEach(radio => {
            radio.closest('.method-option').classList.toggle('active', radio.checked);
        });

        if (type === 'delivery') {
            payStatusGroup.style.display = 'block';
            cashLabel.textContent = 'Thanh toán khi nhận hàng (COD)';
            btnSubmit.innerHTML = '<i class="ph ph-check-circle"></i> Tạo đơn hàng';
        } else {
            payStatusGroup.style.display = 'none';
            cashLabel.textContent = 'Tiền mặt tại cửa hàng';
            document.getElementById('ps_paid').checked = true;
            btnSubmit.innerHTML = '<i class="ph ph-currency-circle-dollar"></i> Thanh toán & Hoàn tất';
        }
    }

    // ==============================
    // 2. XỬ LÝ THANH TOÁN
    // ==============================
    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelectorAll('input[name="payment_method"]').forEach(r => {
                r.closest('.method-option').classList.toggle('active', r.checked);
            });
            togglePaymentMethod();
        });
    });

    function togglePaymentMethod() {
        const method = document.querySelector('input[name="payment_method"]:checked').value;
        const bankBox = document.getElementById('bankInfoBox');
        const cardBox = document.getElementById('cardInfoBox');
        
        bankBox.classList.remove('show');
        cardBox.classList.remove('show');

        if (method === 'bank') {
            bankBox.classList.add('show');
            updateBankQr();
        } else if (method === 'card') {
            cardBox.classList.add('show');
        }
    }

    function updateBankQr() {
        const imgEl = document.getElementById('vietQrImg');
        
        if (BANK_QR_IMAGE && BANK_QR_IMAGE !== '') {
            imgEl.src = '../' + BANK_QR_IMAGE;
        } 
        else if (finalTotalAmount > 0) {
            const addInfo = encodeURIComponent(TRANSFER_NOTE || 'Thanh toan don hang');
            const qrUrl = `https://img.vietqr.io/image/${BANK_BIN}-${BANK_NO}-compact2.png?amount=${finalTotalAmount}&addInfo=${addInfo}`;
            imgEl.src = qrUrl;
        } else {
            imgEl.src = '';
        }
    }

    // ==============================
    // 3. XỬ LÝ VOUCHER
    // ==============================
    function applyVoucher() {
        const code = document.getElementById('voucherCode').value.trim();
        const msg = document.getElementById('voucherMsg');
        
        if (!code) {
            msg.style.color = '#b91c1c';
            msg.textContent = 'Vui lòng nhập mã giảm giá.';
            return;
        }
        if (subTotalAmount <= 0) {
            msg.style.color = '#b91c1c';
            msg.textContent = 'Vui lòng thêm sản phẩm trước khi áp dụng mã.';
            return;
        }

        msg.style.color = '#1d4ed8';
        msg.textContent = 'Đang kiểm tra...';

        // Gọi AJAX kiểm tra voucher
        fetch(`order_create_ajax.php?action=check_voucher&code=${encodeURIComponent(code)}&subtotal=${subTotalAmount}`)
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    appliedVoucher = data.voucher;
                    discountAmount = data.discount;
                    
                    msg.textContent = '';
                    document.getElementById('voucherInputWrap').style.display = 'none';
                    document.getElementById('appliedVoucher').style.display = 'flex';
                    document.getElementById('appliedVoucherCode').textContent = appliedVoucher.code;
                    document.getElementById('discountText').textContent = formatVnd(discountAmount);
                    
                    document.getElementById('discountRow').style.display = 'flex';
                    document.getElementById('discountAmount').textContent = '-' + formatVnd(discountAmount);
                    
                    recalcTotal();
                } else {
                    msg.style.color = '#b91c1c';
                    msg.textContent = data.msg || 'Mã không hợp lệ.';
                }
            })
            .catch(() => {
                msg.style.color = '#b91c1c';
                msg.textContent = 'Lỗi kết nối máy chủ.';
            });
    }

    function removeVoucher() {
        appliedVoucher = null;
        discountAmount = 0;
        
        document.getElementById('voucherCode').value = '';
        document.getElementById('voucherInputWrap').style.display = 'flex';
        document.getElementById('appliedVoucher').style.display = 'none';
        document.getElementById('voucherMsg').textContent = '';
        document.getElementById('discountRow').style.display = 'none';
        
        recalcTotal();
    }

    // Format thẻ
    document.getElementById('card_number')?.addEventListener('input', function(e) {
        let val = e.target.value.replace(/\D/g, '');
        val = val.replace(/(\d{4})(?=\d)/g, '$1 ');
        e.target.value = val;
    });
    document.getElementById('card_expiry')?.addEventListener('input', function(e) {
        let val = e.target.value.replace(/\D/g, '');
        if (val.length >= 2) val = val.slice(0, 2) + '/' + val.slice(2, 4);
        e.target.value = val;
    });

       // ==============================
    // 4. TÌM KIẾM VÀ CHỌN SẢN PHẨM
    // ==============================
    let searchTimeout;
    const searchInput = document.getElementById('productSearch');
    const resultsBox = document.getElementById('searchResults');

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        
        if (q.length < 2) { resultsBox.style.display = 'none'; return; }

        searchTimeout = setTimeout(() => {
            fetch(`order_create_ajax.php?action=search&q=${encodeURIComponent(q)}`)
                .then(r => {
                    if (!r.ok) throw new Error('Lỗi HTTP ' + r.status);
                    return r.json();
                })
                .then(data => {
                    if (data.ok && data.products.length > 0) {
                        searchResultsList = data.products;
                        let html = '';
                        data.products.forEach((p, index) => {
                            html += `<div class="search-item" data-index="${index}">
                                <img src="../${p.image_url}" onerror="this.src='https://via.placeholder.com/40'">
                                <div class="search-item-info">
                                    <div class="search-item-name">${escapeHtml(p.name)}</div>
                                    <div class="search-item-price">${formatVnd(p.final_price)}</div>
                                </div>
                            </div>`;
                        });
                        resultsBox.innerHTML = html;
                        resultsBox.style.display = 'block';

                        resultsBox.querySelectorAll('.search-item').forEach(item => {
                            item.addEventListener('click', function() {
                                const idx = parseInt(this.getAttribute('data-index'));
                                addProductToCart(idx);
                            });
                        });
                    } else {
                        // Hiển thị lỗi chi tiết từ server (nếu có)
                        resultsBox.innerHTML = '<div style="padding:15px; text-align:center; color:#b91c1c;">' + (data.msg || 'Không tìm thấy sản phẩm') + '</div>';
                        resultsBox.style.display = 'block';
                    }
                })
                .catch(err => {
                    console.error("Lỗi Fetch:", err);
                    resultsBox.innerHTML = '<div style="padding:15px; text-align:center; color:#b91c1c;">Lỗi kết nối: ' + err.message + '</div>';
                    resultsBox.style.display = 'block';
                });
        }, 300);
    });

    // ==============================
    // 5. XỬ LÝ GIỎ HÀNG
    // ==============================
    function addProductToCart(index) {
        const product = searchResultsList[index];
        if (!product) return;

        resultsBox.style.display = 'none';
        searchInput.value = '';

        fetch(`order_create_ajax.php?action=sizes&product_id=${product.id}`)
            .then(r => r.json())
            .then(data => {
                if (data.ok && data.sizes.length > 0) {
                    cart.push({
                        product_id: product.id,
                        name: product.name,
                        image_url: product.image_url,
                        price: product.final_price,
                        size: '',
                        quantity: 1,
                        availableSizes: data.sizes
                    });
                    renderCart();
                } else {
                    alert('Sản phẩm này đã hết hàng!');
                }
            });
    }

    function renderCart() {
        const tbody = document.getElementById('cartBody');
        const emptyMsg = document.getElementById('emptyCart');
        const table = document.getElementById('cartTable');
        
        if (cart.length === 0) {
            table.style.display = 'none';
            emptyMsg.style.display = 'block';
        } else {
            table.style.display = 'table';
            emptyMsg.style.display = 'none';
        }

        let html = '';
        subTotalAmount = 0;
        let totalQty = 0;

        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            subTotalAmount += itemTotal;
            totalQty += item.quantity;

            let sizeOptionsHtml = `<option value="">-- Chọn --</option>`;
            item.availableSizes.forEach(s => {
                const selected = item.size === s.size ? 'selected' : '';
                sizeOptionsHtml += `<option value="${s.size}" ${selected}>${s.size} (còn ${s.stock})</option>`;
            });

            html += `<tr>
                <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <img src="../${item.image_url}" style="width:40px; height:40px; border-radius:6px; object-fit:cover;">
                        <span style="font-weight:600;">${escapeHtml(item.name)}</span>
                    </div>
                </td>
                <td>
                    <select class="size-select" onchange="updateSize(${index}, this.value)">
                        ${sizeOptionsHtml}
                    </select>
                </td>
                <td>
                    <input type="number" class="qty-input" value="${item.quantity}" min="1" max="${getMaxStock(item)}" 
                           onchange="updateQty(${index}, this.value)">
                </td>
                <td style="text-align:right; font-family:'IBM Plex Mono',monospace;">${formatVnd(itemTotal)}</td>
                <td style="text-align:center;">
                    <button type="button" class="btn-remove" onclick="removeCart(${index})"><i class="ph ph-trash"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;
        document.getElementById('subTotal').textContent = formatVnd(subTotalAmount);
        document.getElementById('totalQty').textContent = totalQty;
        
        // Nếu đang áp dụng voucher mà xóa hết giỏ hàng -> Hủy voucher
        if (cart.length === 0 && appliedVoucher) {
            removeVoucher();
        } else {
            // Kiểm tra lại điều kiện min_order của voucher nếu có thay đổi
            if (appliedVoucher && subTotalAmount < appliedVoucher.min_order_amount) {
                alert('Giỏ hàng đã giảm xuống dưới mức tối thiểu. Mã giảm giá đã bị hủy.');
                removeVoucher();
            } else {
                recalcTotal();
            }
        }
    }

    function recalcTotal() {
        finalTotalAmount = subTotalAmount - discountAmount;
        if (finalTotalAmount < 0) finalTotalAmount = 0;
        
        document.getElementById('grandTotal').textContent = formatVnd(finalTotalAmount);
        updateBankQr();
    }

    function getMaxStock(item) {
        if (!item.size) return 999;
        const s = item.availableSizes.find(x => x.size === item.size);
        return s ? s.stock : 999;
    }

    function updateSize(index, newSize) {
        cart[index].size = newSize;
        cart[index].quantity = 1; 
        renderCart();
    }

    function updateQty(index, val) {
        let qty = parseInt(val) || 1;
        if (qty < 1) qty = 1;
        
        const max = getMaxStock(cart[index]);
        if (qty > max) {
            alert('Số lượng vượt quá tồn kho của size này!');
            qty = max;
        }
        cart[index].quantity = qty;
        renderCart();
    }

    function removeCart(index) {
        cart.splice(index, 1);
        renderCart();
    }

    // ==============================
    // 6. SUBMIT & VALIDATION
    // ==============================
    document.getElementById('createOrderForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const fullName = document.querySelector('input[name="full_name"]').value.trim();
        const phone = document.querySelector('input[name="phone"]').value.trim();
        const address = document.querySelector('input[name="address"]').value.trim();

        if (!fullName) {
            alert('Vui lòng nhập Họ tên khách hàng.');
            document.querySelector('input[name="full_name"]').focus();
            return;
        }
        if (!phone) {
            alert('Vui lòng nhập Số điện thoại.');
            document.querySelector('input[name="phone"]').focus();
            return;
        }
        if (!address) {
            alert('Vui lòng nhập Địa chỉ giao hàng.');
            document.querySelector('input[name="address"]').focus();
            return;
        }

        if (cart.length === 0) {
            alert('Vui lòng chọn sản phẩm trước khi tạo đơn hàng.');
            return;
        }

        const missingSize = cart.find(item => !item.size);
        if (missingSize) {
            alert('Vui lòng chọn Size cho tất cả sản phẩm.');
            return;
        }

        const payMethod = document.querySelector('input[name="payment_method"]:checked').value;
        if (payMethod === 'card') {
            const cardNum = document.getElementById('card_number').value;
            const cardName = document.getElementById('card_name').value;
            if (!cardNum || !cardName) {
                alert('Vui lòng nhập đầy đủ Số thẻ và Tên in trên thẻ.');
                return;
            }
        }

        const btn = document.getElementById('btnSubmitOrder');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="ph ph-spinner-gap" style="animation:spin 1s linear infinite;"></i> Đang xử lý...';

        const formData = new FormData(this);
        formData.append('action', 'create');
        
        const cartDataToSend = cart.map(item => ({
            product_id: item.product_id,
            name: item.name,
            price: item.price,
            size: item.size,
            quantity: item.quantity
        }));

        formData.append('cart', JSON.stringify(cartDataToSend));
        formData.append('csrf_token', CSRF_TOKEN);
        
        // Gửi thông tin Voucher
        if (appliedVoucher) {
            formData.append('voucher_code', appliedVoucher.code);
            formData.append('discount_amount', discountAmount);
        }

        // Trạng thái thanh toán
        const shippingType = document.querySelector('input[name="shipping_type"]:checked').value;
        let finalPayStatus = 'unpaid';
        if (shippingType === 'pickup') {
            finalPayStatus = 'paid';
        } else {
            finalPayStatus = document.querySelector('input[name="payment_status"]:checked').value;
        }
        formData.append('payment_status', finalPayStatus);

        // Trạng thái đơn hàng
        if (shippingType === 'pickup') {
            formData.append('order_status', 'delivered'); // Hoàn thành luôn
            formData.append('shipping_info', JSON.stringify({ type: 'pickup' }));
        } else {
            formData.append('order_status', 'pending');
            formData.append('shipping_info', JSON.stringify({ type: 'delivery' }));
        }

        fetch('order_create_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert(data.msg || 'Tạo đơn hàng thành công!');
                window.location.href = 'order-detail.php?id=' + data.order_id;
            } else {
                alert(data.msg || 'Có lỗi xảy ra.');
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        })
        .catch(() => {
            alert('Lỗi kết nối máy chủ.');
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        });
    });

    function formatVnd(n) { return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ'; }
    
    renderCart();
    toggleShipping();
</script>

<style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>

<?php include 'includes/admin_footer.php'; ?>