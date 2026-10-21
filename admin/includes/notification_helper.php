<?php
if (!function_exists('addNotification')) {
    function addNotification($type, $title, $message, $link = '', $priority = 'normal', $icon = 'ph-bell') {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO admin_notifications (type, title, message, link, priority, icon, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$type, $title, $message, $link, $priority, $icon]);
            return true;
        } catch (Exception $e) {
            error_log("Lỗi tạo thông báo: " . $e->getMessage());
            return false;
        }
    }
}