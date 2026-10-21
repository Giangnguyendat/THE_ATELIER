<?php
/**
 * ============================================================
 * VOUCHER HELPER — Logic kiểm tra & sử dụng voucher
 * Hỗ trợ voucher: 1 lần / N lần / Không giới hạn
 * Có atomic UPDATE — chống race condition
 * ============================================================
 */

if (!function_exists('checkVoucherUsable')) {
    /**
     * Kiểm tra voucher có còn hiệu lực không (CHỈ ĐỌC, KHÔNG UPDATE)
     *
     * @param PDO   $pdo
     * @param array $voucher  Row từ bảng vouchers
     * @return array {ok: bool, msg: string, remaining: int|null}
     */
    function checkVoucherUsable(PDO $pdo, array $voucher): array
    {
        $usageLimit = isset($voucher['usage_limit']) ? (int)$voucher['usage_limit'] : 1;
        $usedCount  = isset($voucher['used_count'])  ? (int)$voucher['used_count']  : 0;
        $used       = (int)($voucher['used'] ?? 0);

        /* ---- Voucher dùng 1 lần ---- */
        if ($usageLimit === 1) {
            if ($used === 1 || $usedCount >= 1) {
                return [
                    'ok'        => false,
                    'msg'       => 'Voucher đã được sử dụng.',
                    'remaining' => 0,
                ];
            }
            return [
                'ok'        => true,
                'msg'       => 'Voucher chỉ dùng được 1 lần.',
                'remaining' => 1,
            ];
        }

        /* ---- Voucher giới hạn N lần ---- */
        if ($usageLimit > 1) {
            if ($usedCount >= $usageLimit) {
                return [
                    'ok'        => false,
                    'msg'       => "Voucher đã hết lượt sử dụng ({$usedCount}/{$usageLimit}).",
                    'remaining' => 0,
                ];
            }
            $remaining = $usageLimit - $usedCount;
            return [
                'ok'        => true,
                'msg'       => "Còn {$remaining}/{$usageLimit} lượt sử dụng.",
                'remaining' => $remaining,
            ];
        }

        /* ---- Voucher không giới hạn (usage_limit = 0) ---- */
        return [
            'ok'        => true,
            'msg'       => 'Voucher không giới hạn lượt sử dụng.',
            'remaining' => null,
        ];
    }
}

if (!function_exists('applyVoucher')) {
    /**
     * Đánh dấu voucher đã dùng 1 lần — ATOMIC UPDATE
     * An toàn khi nhiều user dùng cùng lúc (race condition safe)
     *
     * @param PDO      $pdo
     * @param int      $voucherId
     * @param int      $orderId
     * @param int|null $userId
     * @param float    $discountAmount
     * @return bool  true = OK, false = voucher đã hết lượt/bị dùng mất
     */
    function applyVoucher(PDO $pdo, int $voucherId, int $orderId, ?int $userId, float $discountAmount = 0): bool
    {
        try {
            /* Load thông tin cơ bản */
            $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
            $stmt->execute([$voucherId]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                error_log("[applyVoucher] Voucher #{$voucherId} không tồn tại");
                return false;
            }

            $usageLimit = (int)($voucher['usage_limit'] ?? 1);
            $ok = false;

            /* ============================================================
               🔒 ATOMIC UPDATE — điều kiện trong WHERE
               ============================================================ */

            if ($usageLimit === 1) {
                /* ---- Voucher 1 lần ---- */
                $stmt = $pdo->prepare("
                    UPDATE vouchers
                    SET used_count = used_count + 1,
                        used       = 1,
                        used_at    = NOW(),
                        order_id   = ?
                    WHERE id = ?
                      AND used = 0
                      AND used_count = 0
                ");
                $stmt->execute([$orderId, $voucherId]);
                $ok = ($stmt->rowCount() > 0);

                if (!$ok) {
                    error_log("[applyVoucher] Voucher #{$voucherId} đã bị user khác dùng (limit=1)");
                    return false;
                }

            } elseif ($usageLimit > 1) {
                /* ---- Voucher giới hạn N lần ----
                   Dùng GREATEST để tránh logic IF phức tạp:
                   - used = 1 khi (used_count + 1) >= usage_limit
                   - used_at = NOW() khi đủ lượt cuối
                */
                $stmt = $pdo->prepare("
                    UPDATE vouchers
                    SET used_count = used_count + 1,
                        used       = IF(used_count + 1 >= ?, 1, 0),
                        used_at    = IF(used_count + 1 >= ?, NOW(), used_at),
                        order_id   = ?
                    WHERE id = ?
                      AND used_count < ?
                ");
                $stmt->execute([
                    $usageLimit,
                    $usageLimit,
                    $orderId,
                    $voucherId,
                    $usageLimit,
                ]);
                $ok = ($stmt->rowCount() > 0);

                if (!$ok) {
                    error_log("[applyVoucher] Voucher #{$voucherId} đã hết lượt (limit={$usageLimit})");
                    return false;
                }

            } else {
                /* ---- Voucher không giới hạn (usage_limit = 0) ---- */
                $stmt = $pdo->prepare("
                    UPDATE vouchers
                    SET used_count = used_count + 1,
                        order_id   = ?
                    WHERE id = ?
                ");
                $stmt->execute([$orderId, $voucherId]);
                $ok = ($stmt->rowCount() > 0);
            }

            /* ============================================================
               📝 Log usage
               ============================================================ */
            try {
                $pdo->prepare("
                    INSERT INTO voucher_usages
                    (voucher_id, voucher_code, user_id, order_id, discount_amount, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'applied', NOW())
                ")->execute([
                    $voucherId,
                    $voucher['code'],
                    $userId,
                    $orderId,
                    $discountAmount,
                ]);
            } catch (Exception $e) {
                error_log('[applyVoucher log] ' . $e->getMessage());
                /* Không rollback nếu log lỗi — voucher đã trừ rồi */
            }

            return true;

        } catch (Exception $e) {
            error_log('[applyVoucher] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('revertVoucherByOrder')) {
    /**
     * Hoàn trả voucher khi đơn hàng bị hủy
     * Tự tìm usage của order → giảm counter → đánh dấu cancelled
     *
     * @param PDO $pdo
     * @param int $orderId
     * @return bool
     */
    function revertVoucherByOrder(PDO $pdo, int $orderId): bool
    {
        try {
            /* Tìm usage của order này */
            $stmt = $pdo->prepare("
                SELECT * FROM voucher_usages
                WHERE order_id = ? AND status = 'applied'
            ");
            $stmt->execute([$orderId]);
            $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($usages)) {
                return false;
            }

            foreach ($usages as $u) {
                /* Load voucher hiện tại */
                $stmtV = $pdo->prepare("
                    SELECT usage_limit, used_count FROM vouchers WHERE id = ?
                ");
                $stmtV->execute([$u['voucher_id']]);
                $v = $stmtV->fetch(PDO::FETCH_ASSOC);

                if (!$v) continue;

                $usageLimit = (int)$v['usage_limit'];
                $currentCount = (int)$v['used_count'];
                $newCount = max(0, $currentCount - 1);

                /* ------------------------------------------------------------
                   Logic `used` flag:
                   - usage_limit = 1 → used = 1 khi used_count >= 1, ngược lại 0
                   - usage_limit > 1 → used = 1 khi used_count >= usage_limit
                   - usage_limit = 0 → giữ nguyên used = 0
                   ------------------------------------------------------------ */
                if ($usageLimit === 0) {
                    $markUsed = 0;
                    $markUsedAt = 'used_at';   // giữ nguyên
                } elseif ($usageLimit === 1) {
                    $markUsed = ($newCount >= 1) ? 1 : 0;
                    $markUsedAt = $markUsed ? 'used_at' : 'NULL';
                } else {
                    $markUsed = ($newCount >= $usageLimit) ? 1 : 0;
                    $markUsedAt = $markUsed ? 'used_at' : 'NULL';
                }

                /* Update counter + flag */
                $sql = "
                    UPDATE vouchers
                    SET used_count = ?,
                        used       = ?,
                        used_at    = " . $markUsedAt . ",
                        order_id   = NULL
                    WHERE id = ?
                ";
                $pdo->prepare($sql)->execute([
                    $newCount,
                    $markUsed,
                    $u['voucher_id'],
                ]);

                /* Đánh dấu usage là cancelled */
                $pdo->prepare("
                    UPDATE voucher_usages
                    SET status = 'cancelled',
                        cancelled_at = NOW()
                    WHERE id = ?
                ")->execute([$u['id']]);
            }

            return true;

        } catch (Exception $e) {
            error_log('[revertVoucherByOrder] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('resetVoucherUses')) {
    /**
     * Reset số lượt dùng voucher về 0 (chỉ dùng cho admin)
     *
     * @param PDO $pdo
     * @param int $voucherId
     * @return bool
     */
    function resetVoucherUses(PDO $pdo, int $voucherId): bool
    {
        try {
            $pdo->prepare("
                UPDATE vouchers
                SET used_count = 0,
                    used       = 0,
                    used_at    = NULL,
                    order_id   = NULL
                WHERE id = ?
            ")->execute([$voucherId]);

            $pdo->prepare("
                DELETE FROM voucher_usages
                WHERE voucher_id = ?
            ")->execute([$voucherId]);

            return true;
        } catch (Exception $e) {
            error_log('[resetVoucherUses] ' . $e->getMessage());
            return false;
        }
    }
}