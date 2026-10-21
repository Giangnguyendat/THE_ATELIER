<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Lấy tất cả đơn hàng của user
$stmt = $pdo->prepare("
    SELECT o.*, 
           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
    FROM orders o
    WHERE o.user_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include 'includes/header.php';
?>

<main class="myorders-wrap">
    <div class="myorders-container">

        <header class="myorders-header">
            <span class="tag-eyebrow">Lịch sử mua hàng</span>
            <h1 class="myorders-title">Đơn hàng của tôi</h1>
            <p class="myorders-subtitle">Quản lý đơn hàng, sửa thông tin giao hàng hoặc hủy đơn</p>
        </header>

        <?php if (empty($orders)): ?>
            <div class="no-product-card">
                <div class="no-product-icon">📦</div>
                <h3>Bạn chưa có đơn hàng nào</h3>
                <p>Khám phá sản phẩm và đặt hàng ngay!</p>
                <a href="products.php" class="btn-primary">Mua sắm ngay</a>
            </div>
        <?php else: ?>
            <div class="myorders-list">
                <?php foreach ($orders as $o): 
                    $statusLabels = [
                        'pending'   => ['⏳', 'Chờ xác nhận', '#a8461f'],
                        'confirmed' => ['✅', 'Đã xác nhận', '#37423a'],
                        'shipped'   => ['🚚', 'Đang giao', '#1d4ed8'],
                        'delivered' => ['🎉', 'Đã giao', '#166534'],
                        'cancelled' => ['❌', 'Đã hủy', '#b91c1c'],
                    ];
                    $label = $statusLabels[$o['status']] ?? ['•', $o['status'], '#8a8377'];
                    $canModify = in_array($o['status'], ['pending', 'confirmed'], true);
                    
                    $payLabels = [
                        'cod'  => '💵 COD',
                        'bank' => '🏦 Chuyển khoản',
                        'card' => '💳 Thẻ',
                    ];
                    $payLabel = $payLabels[$o['payment_method']] ?? $o['payment_method'];
                ?>
                    <article class="myorder-card" data-order-id="<?php echo (int)$o['id']; ?>">
                        
                        <header class="myorder-card__head">
                            <div>
                                <strong class="myorder-id">#<?php echo (int)$o['id']; ?></strong>
                                <span class="myorder-date">
                                    <?php echo date('d/m/Y H:i', strtotime($o['created_at'])); ?>
                                </span>
                            </div>
                            <span class="myorder-status" style="color:<?php echo $label[2]; ?>">
                                <?php echo $label[0] . ' ' . htmlspecialchars($label[1]); ?>
                            </span>
                        </header>

                        <div class="myorder-card__body">
                            <div class="myorder-info">
                                <p><b>Người nhận:</b> <?php echo sanitize($o['full_name']); ?></p>
                                <p><b>SĐT:</b> <?php echo sanitize($o['phone']); ?></p>
                                <p><b>Địa chỉ:</b> <?php echo sanitize($o['address']); ?></p>
                                <?php if (!empty($o['note'])): ?>
                                    <p><b>Ghi chú:</b> <?php echo sanitize($o['note']); ?></p>
                                <?php endif; ?>
                                <p><b>Sản phẩm:</b> <?php echo (int)$o['item_count']; ?> món</p>
                                <p><b>Thanh toán:</b> <?php echo $payLabel; ?></p>
                            </div>

                            <div class="myorder-total">
                                <span class="label">Tổng tiền</span>
                                <strong class="amount"><?php echo number_format($o['total_amount'], 0, ',', '.'); ?>đ</strong>
                            </div>
                        </div>

                        <?php if ($o['status'] === 'cancelled' && !empty($o['cancel_reason'])): ?>
                            <div class="myorder-cancel-info">
                                <b>Lý do hủy:</b> <?php echo sanitize($o['cancel_reason']); ?>
                            </div>
                        <?php endif; ?>

                        <footer class="myorder-card__foot">
                            <?php if ($canModify): ?>
                                <button type="button" 
                                        class="btn-order btn-order--edit"
                                        data-order='<?php echo htmlspecialchars(json_encode([
                                            'id'      => (int)$o['id'],
                                            'name'    => $o['full_name'],
                                            'phone'   => $o['phone'],
                                            'address' => $o['address'],
                                            'note'    => $o['note'] ?? '',
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>'>
                                    ✏️ Sửa đơn hàng
                                </button>

                                <button type="button" 
                                        class="btn-order btn-order--cancel"
                                        data-order-id="<?php echo (int)$o['id']; ?>"
                                        data-payment-method="<?php echo htmlspecialchars($o['payment_method']); ?>">
                                    ❌ Hủy đơn hàng
                                </button>
                            <?php else: ?>
                                <span class="myorder-locked">
                                    <?php if ($o['status'] === 'cancelled'): ?>
                                        Đơn hàng đã hủy — không thể thay đổi
                                    <?php else: ?>
                                        Đơn hàng đang giao — không thể thay đổi
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ============ MODAL SỬA ĐƠN HÀNG ============ -->
<div class="modal-overlay" id="editOrderModal" aria-hidden="true">
    <div class="modal-content" style="max-width:520px">
        <button type="button" class="modal-close" id="closeEditModal" aria-label="Đóng">&times;</button>

        <div style="padding:2rem 2rem 1.75rem">
            <h2 class="modal-title" style="margin-bottom:0.5rem">Sửa thông tin giao hàng</h2>
            <p class="otp-modal__subtitle" style="text-align:left;margin-bottom:1.5rem">
                Chỉ được sửa <b>người nhận, SĐT, địa chỉ, ghi chú</b>. Sản phẩm không thể thay đổi.
            </p>

            <form id="editOrderForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" name="order_id" id="edit_order_id" value="">

                <div class="form-group">
                    <label class="form-label">Người nhận *</label>
                    <input type="text" name="full_name" id="edit_full_name" required class="form-input" maxlength="100">
                </div>

                <div class="form-group">
                    <label class="form-label">Số điện thoại *</label>
                    <input type="tel" name="phone" id="edit_phone" required class="form-input" maxlength="20">
                </div>

                <div class="form-group">
                    <label class="form-label">Địa chỉ giao hàng *</label>
                    <textarea name="address" id="edit_address" required rows="3" class="form-input"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Ghi chú</label>
                    <textarea name="note" id="edit_note" rows="2" class="form-input" 
                              placeholder="Ví dụ: giao giờ hành chính..."></textarea>
                </div>

                <p class="otp-error-msg" id="editErrorMsg" style="text-align:left"></p>

                <div style="display:flex;gap:0.75rem;margin-top:1rem">
                    <button type="button" id="editCancelBtn" 
                            class="pd-btn pd-btn--ghost" style="flex:1">
                        Đóng
                    </button>
                    <button type="submit" class="pd-btn pd-btn--cart" style="flex:2">
                        💾 Lưu thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ MODAL HỦY ĐƠN HÀNG ============ -->
<div class="modal-overlay" id="cancelOrderModal" aria-hidden="true">
    <div class="modal-content" style="max-width:480px">
        <button type="button" class="modal-close" id="closeCancelModal" aria-label="Đóng">&times;</button>

        <div style="padding:2rem 2rem 1.75rem">
            <h2 class="modal-title" style="margin-bottom:0.5rem;color:#b91c1c">
                ❌ Hủy đơn hàng
            </h2>
            <p class="otp-modal__subtitle" style="text-align:left;margin-bottom:1.5rem">
                Bạn chắc chắn muốn hủy đơn hàng này? Hành động này không thể hoàn tác.
            </p>

            <form id="cancelOrderForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" id="cancel_order_id" value="">

                <div class="form-group">
                    <label class="form-label">Lý do hủy đơn *</label>
                    <select name="cancel_reason" id="cancel_reason" required class="form-input">
                        <option value="">-- Chọn lý do --</option>
                        <option value="Đổi ý, không muốn mua nữa">Đổi ý, không muốn mua nữa</option>
                        <option value="Đặt nhầm sản phẩm / số lượng">Đặt nhầm sản phẩm / số lượng</option>
                        <option value="Muốn thay đổi địa chỉ giao hàng">Muốn thay đổi địa chỉ giao hàng</option>
                        <option value="Tìm được giá tốt hơn nơi khác">Tìm được giá tốt hơn nơi khác</option>
                        <option value="Thời gian giao hàng quá lâu">Thời gian giao hàng quá lâu</option>
                        <option value="Khác">Khác</option>
                    </select>
                </div>

                <div class="form-group" id="otherReasonGroup" hidden>
                    <label class="form-label">Lý do cụ thể</label>
                    <textarea name="cancel_reason_other" id="cancel_reason_other" rows="2" 
                              class="form-input" maxlength="200" 
                              placeholder="Vui lòng ghi rõ lý do..."></textarea>
                </div>

                <p class="otp-error-msg" id="cancelErrorMsg" style="text-align:left"></p>

                <div style="display:flex;gap:0.75rem;margin-top:1rem">
                    <button type="button" id="cancelKeepBtn" 
                            class="pd-btn pd-btn--ghost" style="flex:1">
                        Giữ đơn
                    </button>
                    <button type="submit" class="pd-btn" 
                            style="flex:2;background:#b91c1c;color:#fff;border-color:#b91c1c">
                        Xác nhận hủy
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ TOAST THÔNG BÁO ============ -->
<div id="orderToast" class="order-toast" hidden>
    <span class="order-toast__icon" id="orderToastIcon">✓</span>
    <span class="order-toast__msg" id="orderToastMsg">Thành công!</span>
</div>

<script>
(function () {
    var csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;

    /* ============ HELPER TOAST ============ */
    function showToast(msg, type) {
        var toast = document.getElementById('orderToast');
        var icon  = document.getElementById('orderToastIcon');
        var text  = document.getElementById('orderToastMsg');

        icon.textContent = type === 'success' ? '✓' : '⚠';
        text.textContent = msg;
        toast.className = 'order-toast show ' + (type === 'success' ? 'success' : 'error');

        setTimeout(function () {
            toast.className = 'order-toast';
        }, 3500);
    }

    /* ============ MODAL SỬA ĐƠN ============ */
    var editModal = document.getElementById('editOrderModal');
    var editForm  = document.getElementById('editOrderForm');
    var editErr   = document.getElementById('editErrorMsg');

    document.querySelectorAll('.btn-order--edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                var data = JSON.parse(btn.dataset.order);
                document.getElementById('edit_order_id').value = data.id;
                document.getElementById('edit_full_name').value = data.name || '';
                document.getElementById('edit_phone').value     = data.phone || '';
                document.getElementById('edit_address').value   = data.address || '';
                document.getElementById('edit_note').value      = data.note || '';
                editErr.textContent = '';
                editModal.classList.add('show');
                document.body.style.overflow = 'hidden';
            } catch (e) { console.error(e); }
        });
    });

    function closeEditModal() {
        editModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    document.getElementById('closeEditModal')?.addEventListener('click', closeEditModal);
    document.getElementById('editCancelBtn')?.addEventListener('click', closeEditModal);
    editModal?.addEventListener('click', function (e) {
        if (e.target === editModal) closeEditModal();
    });

    editForm?.addEventListener('submit', function (e) {
        e.preventDefault();
        var submitBtn = editForm.querySelector('button[type="submit"]');
        var oldText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Đang lưu...';

        fetch('ajax/order_actions.php', {
            method: 'POST',
            body: new FormData(editForm)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeEditModal();
                showToast(data.msg || '✅ Cập nhật đơn hàng thành công!', 'success');
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                editErr.textContent = data.msg || 'Có lỗi xảy ra.';
                submitBtn.disabled = false;
                submitBtn.textContent = oldText;
            }
        })
        .catch(function () {
            editErr.textContent = 'Không thể kết nối tới máy chủ.';
            submitBtn.disabled = false;
            submitBtn.textContent = oldText;
        });
    });

    /* ============ MODAL HỦY ĐƠN ============ */
    var cancelModal = document.getElementById('cancelOrderModal');
    var cancelForm  = document.getElementById('cancelOrderForm');
    var cancelErr   = document.getElementById('cancelErrorMsg');
    var reasonSelect = document.getElementById('cancel_reason');
    var otherGroup  = document.getElementById('otherReasonGroup');

    document.querySelectorAll('.btn-order--cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('cancel_order_id').value = btn.dataset.orderId;
            cancelForm.reset();
            document.getElementById('cancel_order_id').value = btn.dataset.orderId;
            otherGroup.hidden = true;
            cancelErr.textContent = '';
            cancelModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    });

    reasonSelect?.addEventListener('change', function () {
        otherGroup.hidden = (this.value !== 'Khác');
    });

    function closeCancelModal() {
        cancelModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    document.getElementById('closeCancelModal')?.addEventListener('click', closeCancelModal);
    document.getElementById('cancelKeepBtn')?.addEventListener('click', closeCancelModal);
    cancelModal?.addEventListener('click', function (e) {
        if (e.target === cancelModal) closeCancelModal();
    });

    cancelForm?.addEventListener('submit', function (e) {
        e.preventDefault();

        // Nếu chọn "Khác" thì bắt buộc nhập lý do cụ thể
        if (reasonSelect.value === 'Khác') {
            var otherVal = document.getElementById('cancel_reason_other').value.trim();
            if (otherVal === '') {
                cancelErr.textContent = 'Vui lòng nhập lý do cụ thể.';
                return;
            }
            reasonSelect.value = otherVal;
        }

        if (!confirm('Bạn chắc chắn muốn hủy đơn hàng này?\nHành động này không thể hoàn tác.')) {
            return;
        }

        var submitBtn = cancelForm.querySelector('button[type="submit"]');
        var oldText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Đang xử lý...';

        fetch('ajax/order_actions.php', {
            method: 'POST',
            body: new FormData(cancelForm)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeCancelModal();
                showToast(data.msg || '✅ Hủy đơn hàng thành công!', 'success');
                setTimeout(function () { location.reload(); }, 1800);
            } else {
                cancelErr.textContent = data.msg || 'Có lỗi xảy ra.';
                submitBtn.disabled = false;
                submitBtn.textContent = oldText;
            }
        })
        .catch(function () {
            cancelErr.textContent = 'Không thể kết nối tới máy chủ.';
            submitBtn.disabled = false;
            submitBtn.textContent = oldText;
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>