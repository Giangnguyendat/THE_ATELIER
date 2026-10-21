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
                // Ghi log lỗi nếu cần, nhưng không làm crash trang
                error_log("Lỗi query discount_events: " . $e->getMessage());
            }
        }

        // ============================================================
        // 2. KIỂM TRA SỰ KIỆN NÀO ĐANG ÁP DỤNG
        // ============================================================
        if (!empty($cachedEvents)) {
            $todayDay   = (int)date('j'); // Ngày (1-31)
            $todayMonth = (int)date('n'); // Tháng (1-12)

            foreach ($cachedEvents as $ev) {
                $sm = (int)$ev['start_month'];
                $sd = (int)$ev['start_day'];
                $em = (int)$ev['end_month'];
                $ed = (int)$ev['end_day'];

                // Kiểm tra thời gian (Hỗ trợ cả bắc tháng và bắc năm)
                $isTimeMatch = false;
                
                if ($sm === $em) {
                    // Sự kiện trong cùng 1 tháng (VD: 10/10 - 20/10)
                    if ($todayMonth === $sm && $todayDay >= $sd && $todayDay <= $ed) {
                        $isTimeMatch = true;
                    }
                } else {
                    // Sự kiện bắc qua 2 tháng hoặc 2 năm (VD: 25/11 - 05/12 hoặc 25/12 - 05/01)
                    if (($todayMonth === $sm && $todayDay >= $sd) || ($todayMonth === $em && $todayDay <= $ed)) {
                        $isTimeMatch = true;
                    }
                }

                if (!$isTimeMatch) continue;

                                // Kiểm tra giới tính áp dụng
                $evGender = $ev['target_gender']; // 'male', 'female', 'unisex', 'all'
                $isGenderMatch = false;

                if ($evGender === 'all') {
                    // Sự kiện dành cho TẤT CẢ sản phẩm -> Luôn khớp
                    $isGenderMatch = true;
                } elseif ($evGender === $productGender) {
                    // Khớp chính xác (Ví dụ: sự kiện Nữ áp dụng cho SP Nữ, Unisex áp dụng cho SP Unisex)
                    $isGenderMatch = true;
                } elseif ($productGender === 'unisex' && in_array($evGender, ['male', 'female'])) {
                    // Sự kiện dành riêng cho Nam HOẶC Nữ vẫn áp dụng cho sản phẩm Unisex
                    $isGenderMatch = true;
                }

                if ($isGenderMatch) {
                    $evDiscount = (int)$ev['discount_percent'];
                    
                    // Nếu mức giảm của sự kiện cao hơn mức giảm hiện tại -> Ghi đè
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
            $finalPrice = $originalPrice * (1 - $bestDiscount / 100);
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