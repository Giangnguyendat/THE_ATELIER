<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/src/Exception.php';

function sendMail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);

    try {
        // ============ SMTP CONFIG ============
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

        // ⬇️ ĐỔI 2 DÒNG NÀY
        $mail->Username   = 'giangnguyendat532@gmail.com';        // Gmail thật
        $mail->Password   = 'kxba bqjs lfow juao';            // App Password 16 ký tự

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // Bỏ verify SSL nếu XAMPP báo lỗi certificate
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // ============ NGƯỜI GỬI / NHẬN ============
        $mail->setFrom('giangnguyendat532@gmail.com', 'THE ATELIER');  // ⬅️ Cùng Gmail trên
        $mail->addAddress($to, $toName);
        $mail->addReplyTo('giangnguyendat532@gmail.com', 'THE ATELIER');

        // ============ NỘI DUNG ============
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log lỗi để debug
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}