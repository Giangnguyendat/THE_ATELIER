<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId  = $_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: index.php');
    exit;
}

// Lấy đơn hàng (Chỉ lấy đơn của user đang đăng nhập để bảo mật)
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: index.php');
    exit;
}

/* ============================================================
   LOAD THÔNG TIN NGÂN HÀNG TỪ ADMIN SETTINGS
   ============================================================ */
$bank = [];
try {
    $bank = $pdo->query("SELECT * FROM bank_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $bank = [];
}

$bankBin          = $bank['bank_bin']         ?? '970436';
$bankName         = $bank['bank_name']        ?? 'Vietcombank';
$bankAccountNo    = $bank['account_no']       ?? '';
$bankAccountName  = $bank['account_name']     ?? '';
$bankQrImage      = $bank['qr_image']         ?? '';
$bankTransferNote = $bank['transfer_note']    ?? '';
$bankInstruction  = $bank['instruction']      ?? '';

/* Nội dung CK — ưu tiên: mã đơn + tên KH */
$transferContent = 'DH' . (int)$order['id'] . ' '
                 . mb_substr($order['full_name'] ?? 'Khach', 0, 20);

include 'includes/header.php';
?>

<main class="checkout-wrap" style="background: #f8f9fa; padding: 40px 0; min-height: 80vh;">
    <div class="checkout-container" style="max-width:720px; margin: 0 auto; padding: 0 15px;">

        <div class="checkout-card" style="background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); padding: 3rem 2rem; text-align: center; border: 1px solid #efeae0;">

            <!-- ICON THÀNH CÔNG -->
            <div style="margin-bottom: 1rem;">
                <i class="ph-fill ph-check-circle" style="font-size: 5rem; color: #166534;"></i>
            </div>

            <h1 style="font-family: 'Playfair Display', serif; font-size: 2rem; margin: 0 0 0.5rem; color: #1c1b19;">Đặt hàng thành công!</h1>
            <p style="color: #6b7280; margin-bottom: 2rem; font-size: 1rem;">
                Cảm ơn bạn đã mua sắm. Mã đơn hàng của bạn là: <b style="color: #a8461f; font-family: 'IBM Plex Mono', monospace; font-size: 1.1rem;">#<?php echo (int)$order['id']; ?></b>
            </p>

            <!-- THÔNG TIN GIAO HÀNG -->
            <div style="background: #faf8f4; padding: 1.5rem; text-align: left; margin-bottom: 2rem; border-radius: 8px; border: 1px solid #efeae0;">
                <h3 style="font-family: 'IBM Plex Mono', monospace; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 1rem; color: #8a8377; display: flex; align-items: center; gap: 6px;">
                    <i class="ph ph-map-pin" style="font-size: 1.1rem;"></i> Thông tin giao hàng
                </h3>
                <p style="margin: 0.4rem 0; color: #374151;"><b>Người nhận:</b> <?php echo sanitize($order['full_name']); ?></p>
                <p style="margin: 0.4rem 0; color: #374151;"><b>Số điện thoại:</b> <?php echo sanitize($order['phone']); ?></p>
                <p style="margin: 0.4rem 0; color: #374151;"><b>Địa chỉ:</b> <?php echo sanitize($order['address']); ?></p>
                <p style="margin: 0.4rem 0; color: #374151;"><b>Tổng tiền:</b> <span style="color: #a8461f; font-weight: 700; font-size: 1.1rem;">
                    <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                </span></p>
            </div>

            <?php if (($order['payment_method'] ?? '') === 'bank'): ?>

                <!-- PHƯƠNG THỨC: CHUYỂN KHOẢN NGÂN HÀNG -->
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 1.5rem; text-align: left; margin-bottom: 2rem; border-radius: 8px;">
                    <h3 style="font-family: 'IBM Plex Mono', monospace; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 1rem; color: #1d4ed8; display: flex; align-items: center; gap: 6px;">
                        <i class="ph ph-bank" style="font-size: 1.2rem;"></i> Hướng dẫn chuyển khoản
                    </h3>

                    <!-- MÃ QR -->
                    <div style="text-align: center; margin: 1.25rem 0;">
                        <?php if (!empty($bankQrImage)): ?>
                            <img src="<?php echo htmlspecialchars($bankQrImage); ?>"
                                 alt="Mã QR chuyển khoản <?php echo htmlspecialchars($bankName); ?>"
                                 loading="lazy"
                                 style="max-width:220px; width:100%; height:auto; border-radius:8px; border:1px solid #e5e7eb; background:#fff; padding:6px;">
                            <p style="margin:0.5rem 0 0; font-size:0.82rem; color:#64748b;">
                                Mở app ngân hàng → Quét mã QR
                            </p>
                        <?php else: ?>
                            <!-- QR động VietQR — tự sinh từ bank_bin + account_no -->
                            <?php
                            $qrAmount  = (int)$order['total_amount'];
                            $qrContent = $transferContent;
                            $qrUrl = "https://img.vietqr.io/image/{$bankBin}-{$bankAccountNo}-compact2.png"
                                   . '?amount=' . $qrAmount
                                   . '&addInfo=' . urlencode($qrContent)
                                   . '&accountName=' . urlencode($bankAccountName);
                            ?>
                            <img src="<?php echo htmlspecialchars($qrUrl); ?>"
                                 alt="Mã QR chuyển khoản <?php echo htmlspecialchars($bankName); ?>"
                                 loading="lazy"
                                 style="max-width:220px; width:100%; height:auto; border-radius:8px; border:1px solid #e5e7eb; background:#fff; padding:6px;">
                            <p style="margin:0.5rem 0 0; font-size:0.82rem; color:#64748b;">
                                Quét để tự điền số tiền + nội dung
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- THÔNG TIN CHUYỂN KHOẢN -->
                    <div style="background:#fff; border:1px solid #dbeafe; border-radius:6px; padding:1rem; margin-top:1rem;">
                        <p style="margin:0.4rem 0; color: #374151;">
                            <b>Ngân hàng:</b> <?php echo htmlspecialchars($bankName ?: '—'); ?>
                        </p>
                        <p style="margin:0.4rem 0; color: #374151;">
                            <b>Số tài khoản:</b>
                            <span style="font-family:'IBM Plex Mono', monospace; font-weight:700; letter-spacing:0.03em;">
                                <?php echo htmlspecialchars($bankAccountNo ?: '—'); ?>
                            </span>
                        </p>
                        <p style="margin:0.4rem 0; color: #374151;">
                            <b>Chủ tài khoản:</b> <?php echo htmlspecialchars($bankAccountName ?: '—'); ?>
                        </p>
                        <p style="margin:0.4rem 0; color: #374151;">
                            <b>Số tiền:</b>
                            <span style="color: #a8461f; font-weight:700;">
                                <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                            </span>
                        </p>
                        <p style="margin:0.4rem 0; color: #374151;">
                            <b>Nội dung CK:</b>
                            <span style="font-family:'IBM Plex Mono', monospace; background:#fef3c7; padding:2px 8px; border-radius:4px; color:#92400e; font-weight:700;">
                                <?php echo htmlspecialchars($transferContent); ?>
                            </span>
                        </p>
                    </div>

                    <?php if (!empty($bankTransferNote)): ?>
                        <div style="margin-top:1rem; padding:0.75rem 1rem; background:#fef9c3; border-left:3px solid #eab308; border-radius:4px; font-size:0.85rem; color:#713f12; display: flex; align-items: flex-start; gap: 8px;">
                            <i class="ph-fill ph-warning-circle" style="font-size: 1.2rem; margin-top: 2px;"></i>
                            <div><?php echo htmlspecialchars($bankTransferNote); ?></div>
                        </div>
                    <?php endif; ?>

                    <p style="margin-top:1rem; font-size:0.85rem; color:#475569; line-height: 1.5;">
                        <?php if (!empty($bankInstruction)): ?>
                            <?php echo nl2br(htmlspecialchars($bankInstruction)); ?>
                        <?php else: ?>
                            Vui lòng chuyển khoản trong vòng <b>24h</b>. Đơn hàng sẽ được xử lý sau khi nhận được thanh toán.
                        <?php endif; ?>
                    </p>
                </div>

            <?php elseif (($order['payment_method'] ?? '') === 'card'): ?>

                <!-- PHƯƠNG THỨC: THẺ TÍN DỤNG -->
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.5rem; text-align: left; margin-bottom: 2rem; border-radius: 8px;">
                    <h3 style="font-family: 'IBM Plex Mono', monospace; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 1rem; color: #166534; display: flex; align-items: center; gap: 6px;">
                        <i class="ph ph-credit-card" style="font-size: 1.2rem;"></i> Thanh toán bằng thẻ
                    </h3>
                    <p style="margin:0.4rem 0; color: #374151;">Bạn đã tiến hành thanh toán thẻ thành công.</p>
                    <p style="margin:0.4rem 0; color: #374151;">Chúng tôi sẽ gửi hàng đến cho bạn sơm nhất có thể !.</p>
                </div>

            <?php elseif (($order['payment_method'] ?? '') === 'wallet'): ?>

                <!-- PHƯƠNG THỨC: VÍ ATELIER PAY -->
                <div style="background: #fdf7f3; border: 1px solid #fed7aa; padding: 1.5rem; text-align: left; margin-bottom: 2rem; border-radius: 8px;">
                    <h3 style="font-family: 'IBM Plex Mono', monospace; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 1rem; color: #a8461f; display: flex; align-items: center; gap: 6px;">
                        <i class="ph ph-wallet" style="font-size: 1.2rem;"></i> Thanh toán qua Ví Atelier Pay
                    </h3>
                    <?php if (($order['payment_status'] ?? '') === 'paid'): ?>
                        <p style="margin:0.4rem 0; color: #166534; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                            <i class="ph-fill ph-check-circle"></i> Đã thanh toán thành công.
                        </p>
                        <p style="margin:0.4rem 0; color: #374151;">Số tiền <b><?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ</b> đã được trừ vào ví của bạn.</p>
                    <?php else: ?>
                        <p style="margin:0.4rem 0; color: #c2410c;">Hệ thống đang ghi nhận thanh toán từ ví của bạn. Vui lòng chờ trong giây lát.</p>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <!-- PHƯƠNG THỨC: THANH TOÁN KHI NHẬN HÀNG (COD) -->
                <div style="background: #fefce8; border: 1px solid #fde047; padding: 1.5rem; text-align: left; margin-bottom: 2rem; border-radius: 8px;">
                    <h3 style="font-family: 'IBM Plex Mono', monospace; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 1rem; color: #854d0e; display: flex; align-items: center; gap: 6px;">
                        <i class="ph ph-money" style="font-size: 1.2rem;"></i> Thanh toán khi nhận hàng (COD)
                    </h3>
                    <p style="margin:0.4rem 0; color: #374151;">
                        Bạn sẽ thanh toán <b><?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ</b> cho nhân viên giao hàng khi nhận được sản phẩm.
                    </p>
                    <p style="margin:0.4rem 0; color: #374151;">Vui lòng kiểm tra hàng trước khi thanh toán.</p>
                </div>

            <?php endif; ?>

            <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                <a href="account.php" style="display:inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 24px; background: #fff; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 0.95rem; transition: 0.2s;">
                    <i class="ph ph-user"></i> Xem đơn hàng
                </a>
                <a href="products.php" style="display:inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 24px; background: #1c1b19; color: #fff; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 0.95rem; transition: 0.2s; box-shadow: 0 2px 8px rgba(28,27,25,0.2);">
                    <i class="ph ph-shopping-bag"></i> Tiếp tục mua sắm
                </a>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>