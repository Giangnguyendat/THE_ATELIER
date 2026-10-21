<?php
require_once 'includes/config.php';

$orderId = (int)($_GET['order_id'] ?? 0);
$method  = $_GET['method'] ?? 'cod';

if ($orderId <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$orderId]);
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

<main class="checkout-wrap">
    <div class="checkout-container" style="max-width:720px">

        <div class="checkout-card" style="text-align:center;padding:3rem 2rem">

            <div style="font-size:4rem;margin-bottom:1rem">✓</div>

            <h1 class="checkout-title" style="margin-bottom:0.5rem">Đặt hàng thành công!</h1>
            <p class="checkout-subtitle" style="margin-bottom:2rem">
                Mã đơn hàng: <b style="color:var(--color-rust-deep)">#<?php echo (int)$order['id']; ?></b>
            </p>

            <div style="background:var(--color-ivory);padding:1.5rem;text-align:left;margin-bottom:2rem;border:1px solid var(--color-stone)">
                <h3 style="font-family:var(--font-mono);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 1rem;color:var(--color-stone)">
                    Thông tin giao hàng
                </h3>
                <p style="margin:0.35rem 0"><b>Người nhận:</b> <?php echo sanitize($order['full_name']); ?></p>
                <p style="margin:0.35rem 0"><b>SĐT:</b> <?php echo sanitize($order['phone']); ?></p>
                <p style="margin:0.35rem 0"><b>Địa chỉ:</b> <?php echo sanitize($order['address']); ?></p>
                <p style="margin:0.35rem 0"><b>Tổng tiền:</b> <span style="color:var(--color-rust-deep);font-weight:700">
                    <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                </span></p>
            </div>

            <?php if (($order['payment_method'] ?? '') === 'bank'): ?>

                <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:1.5rem;text-align:left;margin-bottom:2rem;border-radius:6px">
                    <h3 style="font-family:var(--font-mono);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 1rem;color:#1d4ed8">
                        🏦 Hướng dẫn chuyển khoản
                    </h3>

                    <!-- MÃ QR -->
                    <div class="bank-qr" style="text-align:center;margin:1.25rem 0">
                        <?php if (!empty($bankQrImage)): ?>
                            <!-- Ảnh QR admin tự upload -->
                            <img src="<?php echo htmlspecialchars($bankQrImage); ?>"
                                 alt="Mã QR chuyển khoản <?php echo htmlspecialchars($bankName); ?>"
                                 loading="lazy"
                                 style="max-width:220px;width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;background:#fff;padding:6px;">
                            <p style="margin:0.5rem 0 0;font-size:0.82rem;color:#64748b">
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
                                 style="max-width:220px;width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;background:#fff;padding:6px;">
                            <p style="margin:0.5rem 0 0;font-size:0.82rem;color:#64748b">
                                Quét để tự điền số tiền + nội dung
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- THÔNG TIN CHUYỂN KHOẢN -->
                    <div style="background:#fff;border:1px solid #dbeafe;border-radius:6px;padding:1rem;margin-top:1rem">
                        <p style="margin:0.4rem 0">
                            <b>Ngân hàng:</b>
                            <?php echo htmlspecialchars($bankName ?: '—'); ?>
                        </p>
                        <p style="margin:0.4rem 0">
                            <b>Số tài khoản:</b>
                            <span style="font-family:var(--font-mono);font-weight:700;letter-spacing:0.03em">
                                <?php echo htmlspecialchars($bankAccountNo ?: '—'); ?>
                            </span>
                        </p>
                        <p style="margin:0.4rem 0">
                            <b>Chủ tài khoản:</b>
                            <?php echo htmlspecialchars($bankAccountName ?: '—'); ?>
                        </p>
                        <p style="margin:0.4rem 0">
                            <b>Số tiền:</b>
                            <span style="color:var(--color-rust-deep);font-weight:700">
                                <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                            </span>
                        </p>
                        <p style="margin:0.4rem 0">
                            <b>Nội dung CK:</b>
                            <span style="font-family:var(--font-mono);background:#fef3c7;padding:2px 8px;border-radius:4px;color:#92400e;font-weight:700">
                                <?php echo htmlspecialchars($transferContent); ?>
                            </span>
                        </p>
                    </div>

                    <?php if (!empty($bankTransferNote)): ?>
                        <p style="margin-top:1rem;padding:0.75rem 1rem;background:#fef9c3;border-left:3px solid #eab308;border-radius:4px;font-size:0.85rem;color:#713f12">
                            📌 <?php echo htmlspecialchars($bankTransferNote); ?>
                        </p>
                    <?php endif; ?>

                    <p style="margin-top:1rem;font-size:0.85rem;color:#475569">
                        <?php if (!empty($bankInstruction)): ?>
                            <?php echo nl2br(htmlspecialchars($bankInstruction)); ?>
                        <?php else: ?>
                            Vui lòng chuyển khoản trong vòng <b>24h</b>. Đơn hàng sẽ được xử lý sau khi nhận được thanh toán.
                        <?php endif; ?>
                    </p>
                </div>

            <?php elseif (($order['payment_method'] ?? '') === 'card'): ?>

                <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:1.5rem;text-align:left;margin-bottom:2rem;border-radius:6px">
                    <h3 style="font-family:var(--font-mono);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 1rem;color:#166534">
                        💳 Thanh toán bằng thẻ
                    </h3>
                    <p style="margin:0.4rem 0">Hệ thống sẽ gửi email hướng dẫn thanh toán qua cổng an toàn trong vài phút.</p>
                    <p style="margin:0.4rem 0">Vui lòng kiểm tra hộp thư đến của bạn.</p>
                </div>

            <?php else: ?>

                <div style="background:#fefce8;border:1px solid #fde047;padding:1.5rem;text-align:left;margin-bottom:2rem;border-radius:6px">
                    <h3 style="font-family:var(--font-mono);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 1rem;color:#854d0e">
                        💵 Thanh toán khi nhận hàng (COD)
                    </h3>
                    <p style="margin:0.4rem 0">
                        Bạn sẽ thanh toán
                        <b><?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ</b>
                        cho nhân viên giao hàng khi nhận được sản phẩm.
                    </p>
                    <p style="margin:0.4rem 0">Vui lòng kiểm tra hàng trước khi thanh toán.</p>
                </div>

            <?php endif; ?>

            <a href="products.php" class="btn-checkout-submit" style="display:inline-block;text-decoration:none;max-width:280px">
                TIẾP TỤC MUA SẮM
            </a>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>