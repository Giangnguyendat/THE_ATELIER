<?php
/**
 * File: includes/discount_helper.php
 * Chức năng: Tính toán giá sản phẩm sau khi áp dụng tất cả các chương trình giảm giá.
 * Tối ưu: Sử dụng static cache để tránh lỗi N+1 Query khi render danh sách sản phẩm.
 */

if (!function_exists('calculateProductPrice')) {
    /**
     * Tính giá sản phẩm kết hợp giảm giá riêng và sự kiện giảm giá theo mùa/giới tính.
     * 
     * @param array $product Mảng chứa thông tin sản phẩm (bắt buộc có: price, discount_percent, gender).
     * @return array Mảng chứa: original, final, saved, percent, source, event_name.
     */
    function calculateProductPrice(array $product): array {
        global $pdo;

        // Cache danh sách sự kiện để không phải query DB nhiều lần trong cùng 1 request
        static $cachedEvents = null;

        $originalPrice   = (float)($product['price'] ?? 0);
        $productDiscount = (int)($product['discount_percent'] ?? 0);
        $productGender   = $product['gender'] ?? 'unisex';

        // Mặc định ban đầu (Chỉ có giảm giá của sản phẩm)
        $bestDiscount = $productDiscount;
        $eventName    = '';
        $source       = $productDiscount > 0 ? 'product' : 'none';

        // ============================================================
        // 1. LẤY DANH SÁCH SỰ KIỆN ĐANG BẬT (Chỉ query DB 1 lần duy nhất)
        // ============================================================
        if ($cachedEvents === null) {
            $cachedEvents = [];
            try {
                // Lấy các sự kiện đang active
                $stmt = $pdo->query("SELECT * FROM discount_events WHERE active = 1");
                $cachedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Lỗi query discount_events: " . $e->getMessage());
            }
        }

        // ============================================================
        // 2. KIỂM TRA SỰ KIỆN NÀO ĐANG ÁP DỤNG
        // ============================================================
        if (!empty($cachedEvents)) {
            $today = new DateTime();
            $currentMD = (int)$today->format('m') * 100 + (int)$today->format('d'); // VD: Ngày 09/11 -> 1109

            foreach ($cachedEvents as $ev) {
                $startMD = ((int)$ev['start_month']) * 100 + (int)$ev['start_day'];
                $endMD   = ((int)$ev['end_month']) * 100 + (int)$ev['end_day'];

                // Kiểm tra thời gian chuẩn xác (cùng tháng, qua nhiều tháng, hoặc vắt qua năm mới)
                $isTimeMatch = false;
                if ($startMD <= $endMD) {
                    // Cùng năm: ví dụ 08/03 (308) -> 15/03 (315) hoặc 01/05 (501) -> 31/07 (731)
                    $isTimeMatch = ($currentMD >= $startMD && $currentMD <= $endMD);
                } else {
                    // Vắt qua năm mới: ví dụ 25/12 (1225) -> 05/01 (105)
                    $isTimeMatch = ($currentMD >= $startMD || $currentMD <= $endMD);
                }

                if (!$isTimeMatch) {
                    continue;
                }

                // Kiểm tra giới tính áp dụng
                $evGender = $ev['target_gender'] ?? 'all'; // 'male', 'female', 'unisex', 'all'
                $isGenderMatch = false;

                if ($evGender === 'all') {
                    // Sự kiện dành cho TẤT CẢ sản phẩm
                    $isGenderMatch = true;
                } elseif ($evGender === $productGender) {
                    // Khớp chính xác giới tính
                    $isGenderMatch = true;
                } elseif ($productGender === 'unisex' && in_array($evGender, ['male', 'female'], true)) {
                    // Sự kiện cho Nam hoặc Nữ vẫn áp dụng với đồ Unisex
                    $isGenderMatch = true;
                }

                if ($isGenderMatch) {
                    $evDiscount = (int)$ev['discount_percent'];

                    // Lấy mức chiết khấu cao nhất
                    if ($evDiscount > $bestDiscount) {
                        $bestDiscount = $evDiscount;
                        $eventName    = $ev['name'];
                        $source       = 'event';
                    }
                }
            }
        }

        // ============================================================
        // 3. TÍNH TOÁN GIÁ CUỐI CÙNG
        // ============================================================
        $finalPrice = $originalPrice;
        if ($bestDiscount > 0) {
            $finalPrice = max(0, $originalPrice * (1 - $bestDiscount / 100));
        }

        return [
            'original'   => $originalPrice,
            'final'      => $finalPrice,
            'saved'      => $originalPrice - $finalPrice,
            'percent'    => $bestDiscount,
            'source'     => $source,     // 'product' | 'event' | 'none'
            'event_name' => $eventName,  // Tên sự kiện (nếu source là 'event')
        ];
    }
}