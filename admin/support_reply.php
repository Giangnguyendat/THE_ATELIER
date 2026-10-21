<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit($d) { ob_end_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/* Auth */
if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}
$adminId = (int)$_SESSION['user_id'];
$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền.']);
}

/* CSRF */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên hết hạn. Vui lòng tải lại trang.']);
}

/* Inputs */
$requestId = (int)($_POST['request_id'] ?? 0);
$email     = trim($_POST['email'] ?? '');
$content   = trim($_POST['content'] ?? '');
$status    = $_POST['status'] ?? 'resolved';

if ($requestId <= 0)                 jsonExit(['ok' => false, 'msg' => 'ID yêu cầu không hợp lệ.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonExit(['ok' => false, 'msg' => 'Email không hợp lệ.']);
if (mb_strlen($content) < 10)        jsonExit(['ok' => false, 'msg' => 'Nội dung cần ít nhất 10 ký tự.']);
if (mb_strlen($content) > 5000)      $content = mb_substr($content, 0, 5000);

if (!in_array($status, ['processing', 'resolved', 'closed'], true)) {
    $status = 'resolved';
}

/* Load request */
$stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
$stmt->execute([$requestId]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy yêu cầu.']);
}

/* Gửi mail */
$mailSent = false;
$mailError = '';
try {
    require_once __DIR__ . '/../includes/mailer.php';

    $safeName    = htmlspecialchars($r['name']);
    $safeSubject = htmlspecialchars($r['subject']);
    $safeMsg     = nl2br(htmlspecialchars($r['message']));
    $safeReply   = nl2br(htmlspecialchars($content));
    $reqCode     = '#SR' . $r['id'];

    $subject = 'Phản hồi yêu cầu hỗ trợ ' . $reqCode . ' — THE ATELIER';

    $body = '
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f8f5ef;font-family:Arial,Helvetica,sans-serif;color:#24211d;">
    <div style="max-width:600px;margin:0 auto;background:#ffffff;">

        <div style="background:#1c1b19;padding:32px 24px;text-align:center;">
            <h1 style="margin:0;font-family:Georgia,serif;font-size:24px;color:#fffdf9;letter-spacing:3px;font-weight:400;">
                THE ATELIER
            </h1>
            <p style="margin:8px 0 0;font-size:11px;color:#b98a1d;letter-spacing:3px;text-transform:uppercase;">
                Phản hồi yêu cầu hỗ trợ
            </p>
        </div>

        <div style="padding:32px 32px 24px;">

            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                Xin chào <b>' . $safeName . '</b>,
            </p>

            <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#4b5563;">
                Cảm ơn bạn đã liên hệ <b>THE ATELIER</b>. Chúng tôi đã tiếp nhận và xử lý yêu cầu
                <b style="color:#a8461f;font-family:monospace;">' . $reqCode . '</b> của bạn.
            </p>

            <div style="background:#faf8f4;border:1px solid #efeae0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#8a8377;font-weight:700;margin-bottom:8px;">
                    Yêu cầu của bạn
                </div>
                <div style="font-size:13px;color:#6b7280;margin-bottom:6px;"><b>Chủ đề:</b> ' . $safeSubject . '</div>
                <div style="font-size:13px;color:#4b5563;line-height:1.6;font-style:italic;padding-left:12px;border-left:2px solid #e8e0d0;">'
                    . $safeMsg . '</div>
            </div>

            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:20px;margin-bottom:24px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#166534;font-weight:700;margin-bottom:10px;">
                    ✅ Phản hồi từ THE ATELIER
                </div>
                <div style="font-size:15px;color:#14532d;line-height:1.75;">
                    ' . $safeReply . '
                </div>
            </div>

            <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.7;">
                Nếu cần hỗ trợ thêm, bạn có thể phản hồi trực tiếp email này hoặc gọi hotline bên dưới.
            </p>

            <div style="background:#faf8f4;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
                <div style="font-size:13px;color:#4b5563;line-height:1.8;">
                    📞 Hotline: <b style="color:#24211d;">1900 123 456</b><br>
                    📧 Email: <b style="color:#24211d;">support@theatelier.vn</b><br>
                    🕐 Giờ làm việc: 8:00 - 21:00 (T2 - CN)
                </div>
            </div>

            <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.7;">
                Trân trọng,<br>
                <b style="color:#24211d;">Đội ngũ THE ATELIER</b>
            </p>

        </div>

        <div style="background:#faf8f4;padding:24px 32px;text-align:center;border-top:1px solid #efeae0;">
            <p style="margin:0 0 6px;font-family:Georgia,serif;font-size:16px;color:#1c1b19;letter-spacing:2px;">
                THE ATELIER
            </p>
            <p style="margin:0;font-size:11px;color:#8a8377;line-height:1.6;">
                Thời trang không chỉ là mặc — mà là phong cách sống.<br>
                © ' . date('Y') . ' THE ATELIER. All rights reserved.
            </p>
        </div>

    </div>
</body>
</html>';

    $mailSent = sendMail($email, $r['name'], $subject, $body);
} catch (Exception $e) {
    $mailError = $e->getMessage();
}

if (!$mailSent) {
    jsonExit([
        'ok'  => false,
        'msg' => 'Không gửi được mail: ' . ($mailError ?: 'Lỗi SMTP'),
    ]);
}

/* Update DB */
try {
    $pdo->prepare("
        UPDATE support_requests
        SET reply_content   = ?,
            replied_at      = NOW(),
            replied_by      = ?,
            replied_to_email = ?,
            status          = ?,
            resolved_at     = " . ($status === 'resolved' || $status === 'closed' ? 'NOW()' : 'NULL') . "
        WHERE id = ?
    ")->execute([$content, $adminId, $email, $status, $requestId]);
} catch (Exception $e) {
    jsonExit([
        'ok'  => false,
        'msg' => 'Đã gửi mail nhưng không update DB: ' . $e->getMessage(),
    ]);
}

/* Notify admin (log nội bộ) */
try {
    require_once __DIR__ . '/../includes/notification_helper.php';
    if (function_exists('notifyAdmin')) {
        notifyAdmin(
            'support_replied',
            '✅ Đã trả lời yêu cầu #SR' . $requestId,
            "Gửi tới: {$email}\nĐã chuyển sang: {$status}",
            [
                'link'     => 'support.php?id=' . $requestId,
                'ref_id'   => $requestId,
                'ref_type' => 'support',
                'icon'     => '✅',
                'priority' => 'low',
            ]
        );
    }
} catch (Exception $e) { /* ignore */ }

jsonExit([
    'ok'  => true,
    'msg' => '✅ Đã gửi mail tới ' . $email,
]);