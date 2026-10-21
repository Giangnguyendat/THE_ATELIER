<?php
/**
 * Cronjob gửi email sinh nhật.
 * Chạy 1 lần/ngày: 0 9 * * * php /path/to/cron/birthday_email.php
 * (9h sáng mỗi ngày)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';

$today = date('m-d');  // VD: "10-01"
$year  = date('Y');

// Lấy user có sinh nhật hôm nay
$stmt = $pdo->prepare("
    SELECT id, full_name, email, birthday 
    FROM users 
    WHERE DATE_FORMAT(birthday, '%m-%d') = ?
      AND email IS NOT NULL AND email != ''
");
$stmt->execute([$today]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
$failed = 0;

foreach ($users as $u) {
    // Đã gửi năm nay chưa?
    $check = $pdo->prepare("
        SELECT id FROM email_logs 
        WHERE user_id = ? AND type = 'birthday' AND YEAR(sent_at) = ?
        LIMIT 1
    ");
    $check->execute([$u['id'], $year]);
    if ($check->fetch()) continue;

    // Tạo voucher sinh nhật 25%
    $code = createBirthdayVoucher((int)$u['id'], 25, 30);

    // Build nội dung email
    $body = buildBirthdayEmail($u, $code);

    // Gửi
    $ok = sendMail($u['email'], $u['full_name'], '🎂 Chúc mừng sinh nhật từ THE ATELIER!', $body);

    // Log
    $log = $pdo->prepare("
        INSERT INTO email_logs (user_id, type, email, status) 
        VALUES (?, 'birthday', ?, ?)
    ");
    $log->execute([$u['id'], $u['email'], $ok ? 'sent' : 'failed']);

    if ($ok) $sent++; else $failed++;
}

echo "Birthday emails: sent={$sent}, failed={$failed}\n";

/**
 * Template email sinh nhật
 */
function buildBirthdayEmail(array $user, ?string $voucherCode): string {
    $name = htmlspecialchars($user['full_name']);
    $code = htmlspecialchars($voucherCode ?? '');
    $siteUrl = 'http://localhost/clothing_shop';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f2ece0;padding:40px 20px;margin:0">
    <div style="max-width:560px;margin:0 auto;background:#fffdf9;border:1px solid #8a8377">
        <div style="background:#1c1b19;color:#fffdf9;padding:30px 20px;text-align:center">
            <h1 style="margin:0;font-size:24px;letter-spacing:0.05em">🎂 HAPPY BIRTHDAY!</h1>
            <p style="margin:10px 0 0;font-size:13px;opacity:0.7;letter-spacing:0.1em">THE ATELIER</p>
        </div>

        <div style="padding:30px 25px;line-height:1.7;color:#1c1b19;font-size:15px">
            <p>Chào <b>{$name}</b>,</p>
            <p>Cảm ơn bạn đã đồng hành cùng <b>THE ATELIER</b> trong suốt thời gian qua. 
               Nhân dịp sinh nhật của bạn, chúng tôi gửi tặng bạn một món quà nhỏ:</p>

            <div style="background:#fef3c7;border-left:4px solid #d97706;padding:20px;margin:25px 0;text-align:center">
                <p style="margin:0;font-size:13px;letter-spacing:0.1em;text-transform:uppercase;color:#92400e">
                    VOUCHER GIẢM GIÁ
                </p>
                <p style="margin:10px 0;font-size:42px;font-weight:700;color:#78350f">25%</p>
                <p style="margin:0;font-size:13px;color:#92400e">Cho mọi đơn hàng trong 30 ngày tới</p>
            </div>

            <p style="text-align:center;margin:25px 0">
                <span style="display:inline-block;background:#1c1b19;color:#fffdf9;padding:15px 30px;
                             font-family:'Courier New',monospace;font-size:18px;letter-spacing:0.15em;
                             border:2px dashed #b98a1d">
                    {$code}
                </span>
            </p>

            <p style="text-align:center;margin:30px 0">
                <a href="{$siteUrl}/products.php" 
                   style="display:inline-block;background:#a8461f;color:#fffdf9;padding:14px 32px;
                          text-decoration:none;font-size:14px;letter-spacing:0.1em;text-transform:uppercase">
                    Mua sắm ngay
                </a>
            </p>

            <p style="font-size:13px;color:#8a8377;margin-top:30px">
                * Voucher có hiệu lực trong 30 ngày kể từ ngày nhận. Áp dụng 1 lần cho 1 đơn hàng.
            </p>
        </div>

        <div style="background:#e8e0d0;padding:20px;text-align:center;font-size:12px;color:#8a8377">
            © <?php echo date('Y'); ?> THE ATELIER. All rights reserved.
        </div>
    </div>
</body>
</html>
HTML;
}