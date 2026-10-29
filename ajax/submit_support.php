<?php
// File: ajax/submit_support.php
require_once __DIR__ . '/../includes/config.php';

// SỬA LẠI ĐƯỜNG DẪN TRỎ VÀO THƯ MỤC ADMIN
$notifHelper = __DIR__ . '/../admin/includes/notification_helper.php';
if (file_exists($notifHelper)) {
    require_once $notifHelper;
}

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit($d) { ob_end_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/* ---------- CSRF ---------- */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
}

/* ---------- Inputs ---------- */
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '')                                        jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập họ tên.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))          jsonExit(['ok' => false, 'msg' => 'Email không hợp lệ.']);
if ($subject === '')                                     jsonExit(['ok' => false, 'msg' => 'Vui lòng chọn chủ đề.']);
if (mb_strlen($message) < 10)                            jsonExit(['ok' => false, 'msg' => 'Nội dung cần ít nhất 10 ký tự.']);

if (mb_strlen($name) > 100)    $name    = mb_substr($name, 0, 100);
if (mb_strlen($email) > 150)   $email   = mb_substr($email, 0, 150);
if (mb_strlen($phone) > 20)    $phone   = mb_substr($phone, 0, 20);
if (mb_strlen($subject) > 100) $subject = mb_substr($subject, 0, 100);
if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);

/* ---------- Chủ đề map ---------- */
$subjectMap = [
    'order'    => 'Vấn đề về đơn hàng',
    'return'   => 'Yêu cầu đổi trả / hoàn tiền',
    'payment'  => 'Thanh toán / ví Atelier Pay',
    'product'  => 'Sản phẩm / size / chất liệu',
    'shipping' => 'Vận chuyển / giao hàng',
    'account'  => 'Tài khoản / mật khẩu',
    'other'    => 'Khác',
];
$subjectLabel = $subjectMap[$subject] ?? $subject;

/* ---------- Anti-spam: 1 IP tối đa 3 request / 10 phút ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'support_rate_' . md5($ip);

if (!isset($_SESSION[$rateKey]) || !is_array($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = [];
}
$_SESSION[$rateKey] = array_filter($_SESSION[$rateKey], function ($t) {
    return $t > time() - 600;
});

if (count($_SESSION[$rateKey]) >= 3) {
    jsonExit(['ok' => false, 'msg' => 'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau 10 phút.']);
}

/* ---------- Insert ---------- */
$userId = isLoggedIn() ? (int)$_SESSION['user_id'] : null;

try {
    $stmt = $pdo->prepare("
        INSERT INTO support_requests
        (user_id, name, email, phone, subject, message, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'new', NOW())
    ");
    $stmt->execute([
        $userId,
        $name,
        $email,
        $phone !== '' ? $phone : null,
        $subjectLabel,
        $message,
    ]);
    $requestId = (int)$pdo->lastInsertId();
} catch (Exception $e) {
    jsonExit(['ok' => false, 'msg' => 'Lỗi hệ thống khi lưu dữ liệu.']);
}

/* Đánh dấu rate limit */
$_SESSION[$rateKey][] = time();

/* ---------- Notify admin ---------- */
try {
    if (function_exists('addNotification')) {
        $priority = in_array($subject, ['return', 'payment'], true) ? 'high' : 'normal';
        
        $iconMap = [
            'order'    => 'ph-package',
            'return'   => 'ph-arrows-clockwise',
            'payment'  => 'ph-credit-card',
            'product'  => 'ph-t-shirt',
            'shipping' => 'ph-truck',
            'account'  => 'ph-user',
            'other'    => 'ph-chat-circle',
        ];
        $iconClass = $iconMap[$subject] ?? 'ph-chat-circle';

        addNotification(
            'support_new', 
            'Yêu cầu hỗ trợ mới #' . $requestId, 
            "Từ: {$name} <{$email}>\n" . ($phone ? "SĐT: {$phone}\n" : '') . "Chủ đề: {$subjectLabel}\n\n" . mb_substr($message, 0, 200) . (mb_strlen($message) > 200 ? '...' : ''),
            'support.php?id=' . $requestId, 
            $priority,                      
            $iconClass                      
        );
    }
} catch (Throwable $e) {
    error_log('Notify admin error: ' . $e->getMessage());
}

/* ---------- Mail xác nhận cho user ---------- */
try {
    // Kiểm tra an toàn xem file mailer.php đang nằm ở đâu (gốc hay admin)
    $mailerPath = __DIR__ . '/../includes/mailer.php';
    if (!file_exists($mailerPath)) {
        $mailerPath = __DIR__ . '/../admin/includes/mailer.php';
    }

    if (file_exists($mailerPath)) {
        require_once $mailerPath;

        $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#1c1b19;">
            <h2 style="color:#a8461f;font-family:Georgia,serif;font-weight:500;">Đã nhận yêu cầu hỗ trợ của bạn</h2>
            <p>Xin chào <b>' . htmlspecialchars($name) . '</b>,</p>
            <p>THE ATELIER đã nhận được yêu cầu hỗ trợ của bạn với nội dung:</p>
            <div style="background:#faf8f4;border-left:4px solid #a8461f;padding:14px 18px;border-radius:6px;margin:16px 0;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#8a8377;margin-bottom:6px;font-weight:700;">' 
                    . htmlspecialchars($subjectLabel) . '</div>
                <div style="color:#37423a;line-height:1.6;">' . nl2br(htmlspecialchars($message)) . '</div>
            </div>
            <p>Chúng tôi sẽ phản hồi qua email <b>' . htmlspecialchars($email) . '</b> trong vòng <b>24 giờ làm việc</b>.</p>
            <p>Mã yêu cầu: <b style="font-family:monospace;color:#a8461f;">#SR' . $requestId . '</b></p>
            <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
            <p style="color:#8a8377;font-size:13px;">— THE ATELIER Customer Care</p>
        </div>';

        if (function_exists('sendMail')) {
            sendMail($email, $name, 'Đã nhận yêu cầu hỗ trợ #SR' . $requestId . ' — THE ATELIER', $body);
        }
    }
} catch (Throwable $e) {
    error_log('Send mail error: ' . $e->getMessage());
}

jsonExit([
    'ok'         => true,
    'msg'        => 'Đã gửi yêu cầu thành công! Chúng tôi sẽ phản hồi trong 24h làm việc.',
    'request_id' => $requestId,
]);