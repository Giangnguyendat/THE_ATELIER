<?php
// 1. Khôi phục lại file config quan trọng
require_once __DIR__ . '/../includes/config.php';
// 2. Gọi file helper thông báo
require_once __DIR__ . '/includes/notification_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit(array $data) {
    ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Auth ---------- */
if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}

$adminId = (int)$_SESSION['user_id'];
$role    = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'super_admin'], true)) {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền.']);
}

/* ---------- CSRF ---------- */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.']);
}

/* ---------- Inputs ---------- */
$returnId = (int)($_POST['return_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$pin      = trim($_POST['pin'] ?? '');
$reason   = trim($_POST['reason'] ?? '');

if ($returnId <= 0) {
    jsonExit(['ok' => false, 'msg' => 'ID không hợp lệ.']);
}

$validActions = ['approve', 'reject', 'mark_refunded'];
if (!in_array($action, $validActions, true)) {
    jsonExit(['ok' => false, 'msg' => 'Hành động không hợp lệ.']);
}

if ($pin === '' || !preg_match('/^\d{6}$/', $pin)) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập mã PIN 6 số.']);
}

if ($action === 'reject' && $reason === '') {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập lý do từ chối.']);
}

/* ============================================================
   🔐 VERIFY ADMIN PIN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT payment_pin, pin_attempts, pin_locked_until
    FROM users WHERE id = ?
");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy tài khoản admin.']);
}

if (empty($admin['payment_pin'])) {
    jsonExit([
        'ok'  => false,
        'msg' => 'Bạn chưa thiết lập mã PIN. Vui lòng thiết lập trong ví Atelier Pay trước.',
    ]);
}

if (!empty($admin['pin_locked_until']) && strtotime($admin['pin_locked_until']) > time()) {
    $remainSec = strtotime($admin['pin_locked_until']) - time();
    $remainMin = max(1, (int)ceil($remainSec / 60));
    jsonExit(['ok' => false, 'msg' => "Mã PIN đang bị khóa. Vui lòng thử lại sau {$remainMin} phút."]);
}

if (!password_verify($pin, $admin['payment_pin'])) {
    $attempts = (int)$admin['pin_attempts'] + 1;

    if ($attempts >= 5) {
        $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        $pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = ? WHERE id = ?")
            ->execute([$lockUntil, $adminId]);
        jsonExit(['ok' => false, 'msg' => '❌ Sai mã PIN 5 lần. Vui lòng thử lại sau 15 phút.']);
    }

    $pdo->prepare("UPDATE users SET pin_attempts = ? WHERE id = ?")
        ->execute([$attempts, $adminId]);

    $left = 5 - $attempts;
    jsonExit(['ok' => false, 'msg' => "❌ Mã PIN không đúng. Còn {$left} lần thử."]);
}

/* PIN đúng → reset */
$pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")
    ->execute([$adminId]);

/* ============================================================
   LOAD RETURN REQUEST
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM return_requests WHERE id = ?");
$stmt->execute([$returnId]);
$ret = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ret) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy yêu cầu trả hàng.']);
}

// Mã đơn hoàn dùng chung cho tất cả các action
$codeStr = $ret['return_code'] ?: ('RT#' . $returnId);

/* ============================================================
   HANDLE ACTIONS
   ============================================================ */
try {
    /* ==================== 1. APPROVE (Chỉ duyệt, CHƯA hoàn tiền) ==================== */
    if ($action === 'approve') {
        if ($ret['status'] !== 'pending') {
            jsonExit(['ok' => false, 'msg' => 'Yêu cầu này không còn ở trạng thái chờ duyệt.']);
        }

        $pdo->beginTransaction();

        // Chỉ cập nhật trạng thái thành 'approved' (Đang giao hàng / Chờ nhận hàng)
        $pdo->prepare("
            UPDATE return_requests
            SET status = 'approved',
                processed_at = NOW(),
                admin_note = CONCAT(COALESCE(admin_note, ''), 'Đã duyệt, chờ nhận hàng hoàn lúc ', NOW(), '. ')
            WHERE id = ?
        ")->execute([$returnId]);

        // Cập nhật đơn hàng gốc (chờ hoàn tiền)
        $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")
            ->execute([$ret['order_id']]);

        $pdo->commit();

        // --- THÔNG BÁO CHO ADMIN ---
        addNotification(
            'return_approved',
            'Duyệt trả hàng',
            'Đã duyệt yêu cầu ' . $codeStr . '. Đang chờ khách gửi hàng hoàn về.',
            'returns.php',
            'normal',
            'ph-truck'
        );

        // --- MAIL: Gửi thông báo cho khách hàng ---
        notifyUserApproved($ret);

        jsonExit([
            'ok'  => true,
            'msg' => '✅ Đã duyệt yêu cầu. Đơn chuyển sang trạng thái Đang giao hàng.',
        ]);
    }

    /* ==================== 2. REJECT (Từ chối) ==================== */
    if ($action === 'reject') {
        if ($ret['status'] !== 'pending') {
            jsonExit(['ok' => false, 'msg' => 'Chỉ có thể từ chối yêu cầu đang chờ duyệt.']);
        }

        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE return_requests
            SET status = 'rejected',
                processed_at = NOW(),
                admin_note = CONCAT(COALESCE(admin_note, ''), ?, ' — ', NOW(), '. ')
            WHERE id = ?
        ")->execute(['Lý do từ chối: ' . $reason, $returnId]);

        // Đưa order payment_status về paid nếu đã paid
        $stmtO = $pdo->prepare("SELECT payment_status FROM orders WHERE id = ?");
        $stmtO->execute([$ret['order_id']]);
        $oldPayStatus = $stmtO->fetchColumn();

        if ($oldPayStatus === 'refund_pending') {
            $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")
                ->execute([$ret['order_id']]);
        }

        $pdo->commit();

        addNotification(
            'return_rejected',
            'Từ chối trả hàng',
            'Đã từ chối yêu cầu ' . $codeStr . '. Lý do: ' . mb_substr($reason, 0, 80) . '...',
            'returns.php',
            'normal',
            'ph-x-circle'
        );

        notifyUserRejected($ret, $reason);

        jsonExit([
            'ok'  => true,
            'msg' => '✅ Đã từ chối yêu cầu trả hàng.',
        ]);
    }

    /* ==================== 3. MARK REFUNDED (Nhận hàng & Hoàn tiền) ==================== */
    if ($action === 'mark_refunded') {
        if (!in_array($ret['status'], ['approved', 'refunding'], true)) {
            jsonExit(['ok' => false, 'msg' => 'Yêu cầu này chưa được duyệt hoặc đã xử lý xong.']);
        }

        $pdo->beginTransaction();

        // 1. Hoàn kho + giảm sold_count
        $stmtItems = $pdo->prepare("SELECT * FROM return_items WHERE return_id = ?");
        $stmtItems->execute([$returnId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $it) {
            if (!empty($it['size'])) {
                $pdo->prepare("
                    UPDATE product_sizes
                    SET stock = stock + ?
                    WHERE product_id = ? AND size = ?
                ")->execute([$it['quantity'], $it['product_id'], $it['size']]);
            }

            $pdo->prepare("
                UPDATE products
                SET sold_count = GREATEST(0, sold_count - ?)
                WHERE id = ?
            ")->execute([$it['quantity'], $it['product_id']]);
        }

        // 2. Xử lý hoàn tiền
        $refundAmount = (float)$ret['refund_amount'];
        $refundMethod = $ret['refund_method'];

        // Nếu hoàn vào ví Atelier Pay -> Cộng tiền
        if ($refundMethod === 'wallet') {
            $stmtB = $pdo->prepare("SELECT atelier_pay_balance FROM users WHERE id = ?");
            $stmtB->execute([$ret['user_id']]);
            $balanceBefore = (float)$stmtB->fetchColumn();
            $balanceAfter = $balanceBefore + $refundAmount;

            // Cập nhật số dư
            $pdo->prepare("UPDATE users SET atelier_pay_balance = ? WHERE id = ?")
                ->execute([$balanceAfter, $ret['user_id']]);

            // Ghi log giao dịch ví
            $pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after,
                 status, description, order_id, created_at)
                VALUES (?, 'refund', ?, ?, ?, 'completed', ?, ?, NOW())
            ")->execute([
                $ret['user_id'],
                $refundAmount,
                $balanceBefore,
                $balanceAfter,
                'Hoàn tiền trả hàng đơn #' . $ret['order_id'] . ' (mã ' . $codeStr . ')',
                $ret['order_id'],
            ]);
        }

        // 3. Cập nhật trạng thái đơn hoàn -> refunded (Đã hoàn tiền)
        $pdo->prepare("
            UPDATE return_requests
            SET status = 'refunded',
                refunded_at = NOW(),
                admin_note = CONCAT(COALESCE(admin_note, ''), 'Đã nhận hàng hoàn & xử lý hoàn tiền lúc ', NOW(), '. ')
            WHERE id = ?
        ")->execute([$returnId]);

        $pdo->commit();

        // Thông báo admin
        addNotification(
            'return_refunded',
            'Nhận hàng & Hoàn tiền',
            'Đã nhận hàng và hoàn ' . number_format($refundAmount, 0, ',', '.') . 'đ cho yêu cầu ' . $codeStr . '.',
            'returns.php',
            'high',
            'ph-check-circle'
        );

        // Gửi mail thông báo cho khách
        notifyUserRefunded($ret, $refundAmount, $refundMethod);

        $msg = $refundMethod === 'wallet' 
             ? '✅ Đã nhận hàng và cộng ' . number_format($refundAmount, 0, ',', '.') . 'đ vào ví khách.' 
             : '✅ Đã nhận hàng và đánh dấu hoàn tất chuyển khoản.';

        jsonExit(['ok' => true, 'msg' => $msg]);
    }

    jsonExit(['ok' => false, 'msg' => 'Hành động không được xử lý.']);

} catch (Exception $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonExit(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $ex->getMessage()]);
}

/* ============================================================
   CÁC HÀM GỬI MAIL CHO USER
   ============================================================ */

// Hàm mới: Báo khách hàng đã duyệt yêu cầu (kêu gửi hàng về shop)
function notifyUserApproved(array $ret) {
    global $pdo;
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt->execute([$ret['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['email'])) return;

        $code = $ret['return_code'] ?: ('RT#' . $ret['id']);

        $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;">
            <h2 style="color:#166534;">✅ Yêu cầu trả hàng đã được duyệt</h2>
            <p>Xin chào <b>' . htmlspecialchars($u['full_name']) . '</b>,</p>
            <p>Yêu cầu trả hàng <b>' . htmlspecialchars($code) . '</b> của bạn đã được chấp nhận.</p>
            <p>Vui lòng đóng gói sản phẩm và gửi về địa chỉ Shop. Hệ thống đã cấp <b>Mã vận đơn</b> cho bạn trong mục Lịch sử đơn hàng.</p>
            <p>Sau khi Shop nhận được hàng, chúng tôi sẽ tiến hành hoàn tiền cho bạn.</p>
            <p>Cảm ơn bạn đã tin tưởng THE ATELIER.</p>
        </div>';

        sendMail($u['email'], $u['full_name'], 'Yêu cầu trả hàng ' . $code . ' đã được duyệt — THE ATELIER', $body);
    } catch (Exception $e) {}
}

function notifyUserRefunded(array $ret, float $amount, string $method) {
    global $pdo;
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt->execute([$ret['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['email'])) return;

        $code = $ret['return_code'] ?: ('RT#' . $ret['id']);
        $amountFmt = number_format($amount, 0, ',', '.');

        $methodText = [
            'wallet' => 'đã được cộng vào ví Atelier Pay của bạn',
            'bank'   => 'đã được chuyển khoản thành công',
        ][$method] ?? 'đang được xử lý';

        $title = '✅ Hoàn tiền thành công';

        $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;">
            <h2 style="color:#a8461f;">' . $title . '</h2>
            <p>Xin chào <b>' . htmlspecialchars($u['full_name']) . '</b>,</p>
            <p>Shop đã nhận được hàng hoàn và xử lý hoàn tiền cho yêu cầu <b>' . htmlspecialchars($code) . '</b>.</p>
            <table style="border-collapse:collapse;margin:12px 0;">
                <tr><td style="padding:4px 12px 4px 0;color:#6b7280;">Số tiền hoàn:</td>
                    <td><b style="color:#a8461f;">' . $amountFmt . 'đ</b></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#6b7280;">Trạng thái:</td>
                    <td><b>' . htmlspecialchars($methodText) . '</b></td></tr>
            </table>
            <p>Cảm ơn bạn đã tin tưởng THE ATELIER.</p>
        </div>';

        sendMail($u['email'], $u['full_name'], $title . ' — THE ATELIER', $body);
    } catch (Exception $e) {}
}

function notifyUserRejected(array $ret, string $reason) {
    global $pdo;
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt->execute([$ret['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['email'])) return;

        $code = $ret['return_code'] ?: ('RT#' . $ret['id']);

        $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;">
            <h2 style="color:#b91c1c;">❌ Yêu cầu trả hàng bị từ chối</h2>
            <p>Xin chào <b>' . htmlspecialchars($u['full_name']) . '</b>,</p>
            <p>Rất tiếc, yêu cầu trả hàng <b>' . htmlspecialchars($code) . '</b> của bạn đã bị từ chối.</p>
            <div style="background:#fef2f2;border-left:4px solid #b91c1c;padding:12px 16px;border-radius:6px;margin:16px 0;">
                <div style="font-size:13px;color:#991b1b;text-transform:uppercase;font-weight:700;margin-bottom:4px;">Lý do:</div>
                <div style="color:#7f1d1d;">' . nl2br(htmlspecialchars($reason)) . '</div>
            </div>
            <p>Nếu bạn có thắc mắc, vui lòng liên hệ hotline <b>1900 123 456</b>.</p>
        </div>';

        sendMail($u['email'], $u['full_name'], 'Yêu cầu trả hàng ' . $code . ' bị từ chối — THE ATELIER', $body);
    } catch (Exception $e) {}
}