<?php
/**
 * Chatbot Helper — THE ATELIER
 * Nâng cấp: Thêm tính năng Phối đồ (Mix & Match) theo Giới tính & Phong cách.
 */

function chatbotGenderLabel(string $gender): string {
    return [
        'male'   => 'Nam',
        'female' => 'Nữ',
        'unisex' => 'Unisex',
    ][$gender] ?? 'Unisex';
}

/* ============================================================
   1. TÍNH TOÁN SIZE THEO SỐ ĐO
   ============================================================ */
function chatbotParseMeasurements(string $text): ?array {
    $t = mb_strtolower(trim($text));
    $t = preg_replace_callback('/1m(\d{1,2})\b/u', function ($m) {
        $cm = (int)$m[1];
        return (string)(100 + $cm);
    }, $t);

    $numbers = [];
    if (preg_match_all('/\d+(?:\.\d+)?/u', $t, $m)) {
        $numbers = array_map('floatval', $m[0]);
    }
    if (count($numbers) < 2) return null;

    $height = null; 
    $weight = null;
    foreach ($numbers as $n) {
        if ($height === null && $n >= 130 && $n <= 220)      $height = $n;
        elseif ($weight === null && $n >= 30 && $n <= 140)   $weight = $n;
    }
    if (!$height || !$weight) return null;

    $bmi = $weight / (($height / 100) ** 2);
    if ($weight < 50)       $size = 'S';
    elseif ($weight < 60)   $size = 'M';
    elseif ($weight < 70)   $size = 'L';
    elseif ($weight < 80)   $size = 'XL';
    else                    $size = '2XL';

    return [
        'height' => $height,
        'weight' => $weight,
        'bmi'    => round($bmi, 1),
        'size'   => $size
    ];
}

/* ============================================================
   2. QUẢN LÝ NGỮ CẢNH (SESSION CONTEXT)
   ============================================================ */
function chatbotSaveProductContext(array $products): void {
    if (empty($products)) return;
    $ids = [];
    foreach ($products as $p) {
        if (!empty($p['id'])) $ids[] = (int)$p['id'];
    }
    if (!empty($ids)) {
        $_SESSION['chat_last_products']    = $ids;
        $_SESSION['chat_last_products_at'] = time();
    }
}

function chatbotLoadProductContext(): array {
    $ids = $_SESSION['chat_last_products'] ?? [];
    $at  = $_SESSION['chat_last_products_at'] ?? 0;
    if (time() - $at > 600) {
        unset($_SESSION['chat_last_products'], $_SESSION['chat_last_products_at']);
        return [];
    }
    return $ids;
}

/* ============================================================
   3. TRUY VẤN DỮ LIỆU TỪ DATABASE
   ============================================================ */

// Lấy danh sách size & số lượng tồn kho từ bảng product_sizes
function chatbotGetProductSizes(PDO $pdo, int $productId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT size, stock FROM product_sizes 
            WHERE product_id = ? 
            ORDER BY FIELD(size, 'S', 'M', 'L', 'XL', '2XL')
        ");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { 
        return []; 
    }
}

// Lấy chi tiết 1 sản phẩm kèm size & tồn kho
function chatbotGetProductById(PDO $pdo, int $productId): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.price, p.discount_percent, p.image_url, 
                   p.gender, p.sold_count, p.stock, p.product_type, p.description,
                   c.name AS category_name,
                   CASE WHEN p.discount_percent > 0 
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100) 
                        ELSE p.price END AS final_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return null;

        $product['sizes'] = chatbotGetProductSizes($pdo, (int)$product['id']);
        return $product;
    } catch (Exception $e) {
        return null;
    }
}

// Tìm kiếm thông minh theo product_type, giới tính, hot, sale
function chatbotSearchProductsSmart(PDO $pdo, string $message, int $limit = 4): array {
    try {
        $msg = mb_strtolower(trim($message));
        $params = [];
        $where = ["1=1"];
        $orderBy = "p.sold_count DESC, p.id DESC";

        if (preg_match('/(đôi|couple|cặp|set đôi)/iu', $msg)) {
            $where[] = "p.product_type = 'do_doi'";
        } elseif (preg_match('/(mũ|nón|phụ kiện)/iu', $msg)) {
            $where[] = "p.product_type = 'phu_kien'";
        } elseif (preg_match('/(váy|đầm|chân váy)/iu', $msg)) {
            $where[] = "p.product_type = 'vay_nu'";
        } elseif (preg_match('/(combo|set)/iu', $msg)) {
            if (preg_match('/(nữ|gái)/iu', $msg)) {
                $where[] = "p.product_type = 'combo_nu'";
            } elseif (preg_match('/(nam|trai)/iu', $msg)) {
                $where[] = "p.product_type = 'combo_nam'";
            } else {
                $where[] = "p.product_type LIKE 'combo%'";
            }
        } elseif (preg_match('/(áo nam|áo sơ mi nam|áo thun nam|áo khoác nam|hoodie nam)/iu', $msg)) {
            $where[] = "p.product_type IN ('ao_nam', 'ao_unisex')";
        } elseif (preg_match('/(quần nam|quần jean nam|quần tây nam|quần short nam)/iu', $msg)) {
            $where[] = "p.product_type IN ('quan_nam', 'quan_unisex')";
        } elseif (preg_match('/(áo nữ|sơ mi nữ|blazer nữ|áo kiểu)/iu', $msg)) {
            $where[] = "p.product_type IN ('ao_nu', 'ao_unisex')";
        } elseif (preg_match('/(quần nữ|baggy|short nữ)/iu', $msg)) {
            $where[] = "p.product_type IN ('quan_nu', 'quan_unisex')";
        } 
        elseif (preg_match('/(nữ|women|girl)/iu', $msg) && !preg_match('/(nam|men)/iu', $msg)) {
            $where[] = "(p.product_type IN ('ao_nu', 'quan_nu', 'vay_nu', 'combo_nu') OR p.gender = 'female')";
        } elseif (preg_match('/(nam|men|boy)/iu', $msg) && !preg_match('/(nữ|women)/iu', $msg)) {
            $where[] = "(p.product_type IN ('ao_nam', 'quan_nam', 'combo_nam') OR p.gender = 'male')";
        }

        if (preg_match('/(giảm giá|sale|khuyến mãi|ưu đãi)/iu', $msg)) {
            $where[] = "p.discount_percent > 0";
            $orderBy = "p.discount_percent DESC, p.sold_count DESC";
        } elseif (preg_match('/(bán chạy|hot|mua nhiều)/iu', $msg)) {
            $orderBy = "p.sold_count DESC, p.id DESC";
        } elseif (preg_match('/(mới|new)/iu', $msg)) {
            $orderBy = "p.created_at DESC, p.id DESC";
        }

        $sql = "
            SELECT p.id, p.name, p.price, p.discount_percent, p.image_url, 
                   p.gender, p.sold_count, p.stock, p.category_id, p.product_type,
                   c.name AS category_name,
                   CASE WHEN p.discount_percent > 0 
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100) 
                        ELSE p.price END AS final_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$orderBy}
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$p) {
            $p['sizes'] = chatbotGetProductSizes($pdo, (int)$p['id']);
        }

        return $products;
    } catch (Exception $e) {
        error_log('chatbotSearchProductsSmart error: ' . $e->getMessage());
        return [];
    }
}

// Lấy thông tin voucher hợp lệ từ bảng vouchers
function chatbotGetDatabasePromotions(PDO $pdo): string {
    try {
        $vStmt = $pdo->query("
            SELECT code, discount_percent, min_order_amount 
            FROM vouchers 
            WHERE used = 0 AND (valid_to IS NULL OR valid_to >= CURDATE())
            ORDER BY discount_percent DESC LIMIT 3
        ");
        $vouchers = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($vouchers as $v) {
            $min = $v['min_order_amount'] ? " (đơn từ " . number_format($v['min_order_amount'], 0, ',', '.') . "đ)" : "";
            $out[] = "mã **{$v['code']}** (giảm {$v['discount_percent']}%{$min})";
        }
        return !empty($out) ? implode(', ', $out) : "Hiện chưa có mã giảm giá công khai.";
    } catch (Exception $e) {
        return "Hiện chưa có mã giảm giá.";
    }
}

function chatbotGetOutfit(PDO $pdo, string $gender, string $style, array $excludeIds = []): array {
    // 1. Lọc theo cột gender
    $genderCond = ($gender === 'nam') ? "p.gender IN ('male', 'unisex')" : "p.gender IN ('female', 'unisex')";
    $styleCond  = "p.style_category = '" . addslashes($style) . "'";

    // 2. Tách chặt chẽ product_type theo Giới tính (Chặn rủi ro data nhập sai)
    $topTypes    = ($gender === 'nam') ? "'ao_nam', 'ao_unisex'" : "'ao_nu', 'ao_unisex'";
    $bottomTypes = ($gender === 'nam') ? "'quan_nam', 'quan_unisex'" : "'quan_nu', 'quan_unisex', 'vay_nu'";

    // Closure xử lý truy vấn động cho Áo và Quần
    $fetchItem = function(string $typeIn, array $avoidIds) use ($pdo, $genderCond, $styleCond) {
        $excludeSQL = '';
        if (!empty($avoidIds)) {
            $inQuery = implode(',', array_map('intval', $avoidIds));
            $excludeSQL = " AND p.id NOT IN ($inQuery) ";
        }

        $sql = "SELECT p.*, CASE WHEN p.discount_percent > 0 THEN ROUND(p.price * (100 - p.discount_percent) / 100) ELSE p.price END AS final_price 
                FROM products p 
                WHERE $genderCond AND $styleCond 
                AND p.product_type IN ($typeIn) 
                $excludeSQL AND p.stock > 0 
                ORDER BY RAND() LIMIT 1";
        $item = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        // Nếu đã gửi hết sạch mẫu mới -> Hủy chặn ID cũ để lấy xoay vòng lại đúng Style đó
        if (!$item && !empty($avoidIds)) {
            $sqlFallback = "SELECT p.*, CASE WHEN p.discount_percent > 0 THEN ROUND(p.price * (100 - p.discount_percent) / 100) ELSE p.price END AS final_price 
                            FROM products p 
                            WHERE $genderCond AND $styleCond 
                            AND p.product_type IN ($typeIn) 
                            AND p.stock > 0 
                            ORDER BY RAND() LIMIT 1";
            $item = $pdo->query($sqlFallback)->fetch(PDO::FETCH_ASSOC);
        }
        return $item ?: null;
    };

    // 3. Tiến hành lấy 1 Áo và 1 Quần/Váy
    $top = $fetchItem($topTypes, $excludeIds);

    if ($top) $excludeIds[] = $top['id'];
    $bottom = $fetchItem($bottomTypes, $excludeIds);

    $result = [];
    if ($top) {
        $top['sizes'] = chatbotGetProductSizes($pdo, (int)$top['id']);
        $result[] = $top;
    }
    if ($bottom) {
        $bottom['sizes'] = chatbotGetProductSizes($pdo, (int)$bottom['id']);
        $result[] = $bottom;
    }

    // 4. Chỉ chuyển sang 'thoai_mai' nếu phong cách này hoàn toàn KHÔNG TỒN TẠI sản phẩm nào trong kho
    if (empty($result) && $style !== 'thoai_mai') {
        return chatbotGetOutfit($pdo, $gender, 'thoai_mai', []); // Reset excludeIds khi fallback
    }

    return $result;
}
function chatbotCallAI(string $systemPrompt, string $userMessage, array $history, bool $isJson = false): ?string {
    $groqKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';

    if (empty($groqKey)) {
        error_log('Chatbot AI Error: Missing Groq API Key');
        return null;
    }

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach (array_slice($history, -4) as $h) {
        if (!empty($h['role']) && !empty($h['content'])) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    // Danh sách model dự phòng (thử từ tốt nhất đến nhẹ nhất)
    $models = [
        'openai/gpt-oss-120b', 
        'openai/gpt-oss-20b',
        'llama3-8b-8192'
    ];

    foreach ($models as $modelName) {
        $payload = [
            'model' => $modelName,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 300
        ];

        if ($isJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $groqKey
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $resData = json_decode($resp, true);
            $content = $resData['choices'][0]['message']['content'] ?? null;
            
            // Nếu có nội dung trả về, thoát vòng lặp và trả kết quả
            if ($content) {
                return $content;
            }
        } elseif ($httpCode === 429) {
            // Nếu bị Rate Limit, ghi log và thử model tiếp theo
            error_log("Groq Rate Limit (429) với model: $modelName. Đang thử model khác...");
            usleep(500000); // Đợi 0.5 giây trước khi thử model khác
        } else {
            // Lỗi khác (Sai key, model không tồn tại...)
            error_log("Groq API Error (HTTP $httpCode) với model $modelName: " . $resp);
        }
    }

    return null; // Trả về null nếu tất cả các model đều thất bại
}
/* ============================================================
   6. BỘ ĐIỀU PHỐI (MAIN CONTROLLER)
   ============================================================ */
function chatbotReply(PDO $pdo, string $message): array {
    $message = trim($message);
    $msgLower = mb_strtolower($message);
    $result = ['message' => '', 'products' => [], 'suggestions' => [], 'source' => 'ai'];

    $lastContextIds = chatbotLoadProductContext();
    $referencedProduct = null;

    // ============================================================
    // BƯỚC 1: KIỂM TRA LUỒNG PHỐI ĐỒ (MIX & MATCH STATE)
    // ============================================================
    $outfitState = $_SESSION['chat_outfit_state'] ?? null;

    if ($outfitState) {
        // 1. Đang chờ khách chọn giới tính
        if ($outfitState === 'ask_gender') {
            if (preg_match('/(nam|trai|men|boy)/iu', $msgLower)) {
                $_SESSION['chat_outfit_gender'] = 'nam';
                $_SESSION['chat_outfit_state'] = 'ask_style';
                return [
                    'message' => "Dạ, bạn muốn phối theo phong cách nào: **Công sở**, **Thoải mái**, hay **Thể thao** ạ?",
                    'products' => [],
                    'suggestions' => ['Công sở', 'Thoải mái', 'Thể thao'],
                    'source' => 'flow'
                ];
            } elseif (preg_match('/(nữ|gái|women|girl)/iu', $msgLower)) {
                $_SESSION['chat_outfit_gender'] = 'nu';
                $_SESSION['chat_outfit_state'] = 'ask_style';
                return [
                    'message' => "Dạ, bạn muốn phối theo phong cách nào: **Công sở**, **Thoải mái**, hay **Thể thao** ạ?",
                    'products' => [],
                    'suggestions' => ['Công sở', 'Thoải mái', 'Thể thao'],
                    'source' => 'flow'
                ];
            } else {
                return [
                    'message' => "Dạ, bạn vui lòng cho shop biết bạn là **Nam** hay **Nữ** để shop tư vấn chính xác nhé?",
                    'products' => [],
                    'suggestions' => ['Nam', 'Nữ'],
                    'source' => 'flow'
                ];
            }
        }

        // 2. Đang chờ khách chọn phong cách
        if ($outfitState === 'ask_style') {
            $style = '';
            if (preg_match('/(công sở|van phong|office|đi làm)/iu', $msgLower)) $style = 'cong_so';
            elseif (preg_match('/(thể thao|sport|gym|chạy bộ)/iu', $msgLower)) $style = 'the_thao';
            elseif (preg_match('/(thu đông|mùa lạnh|áo len|hoodie|khoác)/iu', $msgLower)) $style = 'thu_dong';
            else $style = 'thoai_mai'; // Mặc định

            $gender = $_SESSION['chat_outfit_gender'];
            $_SESSION['chat_outfit_state'] = 'done';
            $_SESSION['chat_outfit_style'] = $style; // Lưu lại để lúc khách bấm "Khác" còn biết đường lấy tiếp
            
            $sentIds = [];
            $outfit = chatbotGetOutfit($pdo, $gender, $style, $sentIds);
                        
            if (!empty($outfit)) {
                foreach($outfit as $o) $sentIds[] = $o['id'];
                $_SESSION['chat_outfit_sent_ids'] = $sentIds;

                return [
                    'message' => "Dạ, đây là 1 bộ phối hợp với phong cách của bạn. Bạn thấy thế nào ạ?",
                    'products' => $outfit,
                    'suggestions' => ['Xem bộ khác', 'Mẫu này có size gì?', 'Chính sách đổi trả'],
                    'source' => 'flow'
                ];
            } else {
                unset($_SESSION['chat_outfit_state']);
                return [
                    'message' => "Dạ, hiện tại shop đang tạm hết hàng các mẫu cho phong cách này. Bạn thử xem các mẫu khác nhé!",
                    'products' => [],
                    'suggestions' => ['Đồ nam bán chạy', 'Đồ nữ bán chạy'],
                    'source' => 'flow'
                ];
            }
        }

// 3. Đang xem kết quả, khách muốn đổi bộ khác
        if ($outfitState === 'done') {
            if (preg_match('/(khác|tiếp|đổi|bộ mới|không thích)/iu', $msgLower)) {
                $gender = $_SESSION['chat_outfit_gender'];
                $style  = $_SESSION['chat_outfit_style'] ?? 'thoai_mai'; 
                
                $sentIds = $_SESSION['chat_outfit_sent_ids'] ?? [];
                $outfit = chatbotGetOutfit($pdo, $gender, $style, $sentIds);

                if (!empty($outfit)) {
                    foreach($outfit as $o) $sentIds[] = $o['id'];
                    $_SESSION['chat_outfit_sent_ids'] = $sentIds;
                    
                    // QUAN TRỌNG: Lưu Context để AI hiểu khi khách hỏi tiếp "Mẫu này size gì?"
                    chatbotSaveProductContext($outfit); 

                    return [
                        'message' => "Dạ, đây là một bộ phối khác dành cho bạn. Bạn thích mẫu nào ạ?",
                        'products' => $outfit,
                        'suggestions' => ['Xem bộ khác', 'Mẫu này có size gì?', 'Chính sách đổi trả'],
                        'source' => 'flow'
                    ];
                } else {
                    // Xóa hoàn toàn state nếu không thể phối đồ được nữa
                    unset($_SESSION['chat_outfit_state'], $_SESSION['chat_outfit_gender'], $_SESSION['chat_outfit_style'], $_SESSION['chat_outfit_sent_ids']);
                    return [
                        'message' => "Dạ, shop đã gợi ý hết các mẫu phù hợp với phong cách này rồi. Bạn thử tìm theo từ khóa hoặc phong cách khác nhé!",
                        'products' => [],
                        'suggestions' => ['Đồ nam bán chạy', 'Đồ nữ mới về'],
                        'source' => 'flow'
                    ];
                }
            } else {
                // Khách hỏi câu khác (VD: hỏi size, hỏi giá) -> Ngắt luồng phối đồ và dọn dẹp sạch Session
                unset($_SESSION['chat_outfit_state'], $_SESSION['chat_outfit_gender'], $_SESSION['chat_outfit_style'], $_SESSION['chat_outfit_sent_ids']);
                // Code sẽ tự động chạy tiếp xuống dưới để vào AI Engine xử lý
            }
        }
    }

    // Kích hoạt luồng phối đồ mới
    if (preg_match('/(phối đồ|mix|set đồ|mặc gì|tư vấn phối|gợi ý phối)/iu', $msgLower)) {
        $_SESSION['chat_outfit_state'] = 'ask_gender';
        return [
            'message' => "Dạ, shop rất sẵn lòng tư vấn. Bạn là **Nam** hay **Nữ** ạ?",
            'products' => [],
            'suggestions' => ['Nam', 'Nữ'],
            'source' => 'flow'
        ];
    }

    // ============================================================
    // BƯỚC 2: CÁC LOGIC CŨ (AI, TÌM KIẾM, CHÍNH SÁCH)
    // ============================================================
    $isPolicyQuery = (bool)preg_match('/(đổi trả|hoàn tiền|đồng kiểm|kiểm hàng|vận chuyển|phí ship|giao hàng|hủy đơn|thanh toán|địa chỉ|showroom|cửa hàng|giờ mở|hotline)/iu', $msgLower);
    $isProductIntent = (bool)preg_match('/(tìm|mua|xem|gợi ý|sản phẩm|mẫu|đồ nam|đồ nữ|áo|quần|váy|đầm|combo|mũ|nón|bán chạy|giảm giá|sale)/iu', $msgLower);
    $isFollowUpQuestion = (bool)preg_match('/(size|cỡ|sz|còn không|còn ko|còn hàng|hết hàng|màu|chất liệu|vải|form|giá|bao nhiêu tiền)/iu', $msgLower);

    if (!empty($lastContextIds) && preg_match('/(?:cái|sản phẩm|món|sp)\s+(?:thứ\s+|số\s+)?([1-5]|nhất|hai|ba|bốn|năm)/iu', $message, $m)) {
        $map = ['1' => 0, 'nhất' => 0, '2' => 1, 'hai' => 1, '3' => 2, 'ba' => 2, '4' => 3, 'bốn' => 3];
        $idx = $map[mb_strtolower($m[1])] ?? null;
        if ($idx !== null && isset($lastContextIds[$idx])) {
            $referencedProduct = chatbotGetProductById($pdo, (int)$lastContextIds[$idx]);
        }
    } elseif (!empty($lastContextIds) && $isFollowUpQuestion && !$isProductIntent) {
        $referencedProduct = chatbotGetProductById($pdo, (int)$lastContextIds[0]);
    }

    $matchedProducts = [];
    if ($referencedProduct) {
        $matchedProducts = [$referencedProduct];
    } elseif ($isProductIntent && !$isPolicyQuery) {
        $matchedProducts = chatbotSearchProductsSmart($pdo, $message, 4);
    }

    $productsTextData = "";
    if (!empty($matchedProducts)) {
        foreach ($matchedProducts as $p) {
            $sizesInStock = [];
            foreach ($p['sizes'] ?? [] as $s) {
                if ($s['stock'] > 0) $sizesInStock[] = "{$s['size']} (còn {$s['stock']})";
            }
            $stockStr = !empty($sizesInStock) ? "Size còn: " . implode(', ', $sizesInStock) : "Hết hàng";
            $priceFmt = number_format($p['final_price'] ?? $p['price'], 0, ',', '.') . "đ";
            $productsTextData .= "- {$p['name']} | Giá: {$priceFmt} | Kho: [{$stockStr}]\n";
        }
    }

    $promotionsInfo = chatbotGetDatabasePromotions($pdo);
    $measurementData = chatbotParseMeasurements($message);

    $systemPrompt = "Bạn là trợ lý chăm sóc khách hàng của thương hiệu thời trang THE ATELIER.

QUY TẮC CỐT LÕI:
1. TRẢ LỜI ĐỦ Ý & HOÀN CHỈNH: Trả lời ngắn gọn từ 1 đến 3 câu nhưng câu văn PHẢI hoàn chỉnh, đủ chủ ngữ vị ngữ, tuyệt đối không ngắt câu giữa chừng.
2. CHÍNH SÁCH: Nếu khách hỏi chính sách, trả lời đúng thông tin dưới đây và KHÔNG kèm theo gợi ý sản phẩm không liên quan.
3. TƯ VẤN SẢN PHẨM: Nếu khách hỏi mua/tìm đồ, giới thiệu ngắn gọn điểm nổi bật của mẫu phù hợp.

BỘ CHÍNH SÁCH CHUẨN CỦA SHOP:
- Đổi trả: Hỗ trợ đổi trả trong 30 ngày (sản phẩm còn nguyên tag, chưa qua giặt ủi). Đổi size miễn phí lần đầu. Lỗi từ shop được miễn phí ship 2 chiều.
- Đồng kiểm: Cho phép khách mở gói hàng kiểm tra đúng mẫu, đúng màu, đúng size trước khi thanh toán (không hỗ trợ mặc thử).
- Vận chuyển: Freeship toàn quốc cho đơn từ 500.000đ (dưới 500k phí ship từ 20-24k). Thời gian giao 1-2 ngày đối với HN/HCM và 2-4 ngày đối với tỉnh thành khác.
- Hủy đơn: Hủy hoặc sửa địa chỉ miễn phí khi đơn chưa giao cho bên vận chuyển.
- Showroom & Hotline: số 9 Vũ Đức Úy, phường Thanh Liệt, Hà Nội(9h00 - 21h30). Hotline: 1900 123 456.

DỮ LIỆU SẢN PHẨM HIỆN CÓ LIÊN QUAN:
" . ($productsTextData ?: "Không yêu cầu sản phẩm.") . "
";

    if ($measurementData) {
        $systemPrompt .= "\n[Khách: Cao {$measurementData['height']}cm, Nặng {$measurementData['weight']}kg -> Tư vấn size: {$measurementData['size']}].";
    }

    $history = $_SESSION['chat_history'] ?? [];
    $aiReply = chatbotCallAI($systemPrompt, $message, $history);

    if (!empty($aiReply)) {
        $result['message'] = trim($aiReply);
        $result['products'] = (!empty($matchedProducts) && ($isProductIntent || $referencedProduct)) ? $matchedProducts : [];
        $result['source'] = 'ai_complete';

        if ($isPolicyQuery) {
            $result['suggestions'] = ["Chính sách freeship", "Quy định đồng kiểm", "Xem mẫu bán chạy"];
        } elseif (!empty($result['products'])) {
            $result['suggestions'] = ["Có những size nào?", "Xem chi tiết", "Phí ship thế nào?"];
        } else {
            $result['suggestions'] = ["Đồ nam bán chạy", "Đồ nữ mới về", "Chính sách đổi trả", "Tư vấn phối đồ"];
        }

        $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $message];
        $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $result['message']];
        return $result;
    }

    // Fallback
    $result['source'] = 'fallback';
    if ($isPolicyQuery) {
        $result['message'] = "Dạ, THE ATELIER hỗ trợ đổi trả trong vòng 30 ngày (sản phẩm còn nguyên tag) và khách hàng được đồng kiểm xem hàng trước khi thanh toán ạ.";
        $result['products'] = [];
    } else {
        $result['message'] = "Dạ, shop gửi bạn tham khảo các mẫu phù hợp nhất ở bên dưới nhé!";
        $result['products'] = $matchedProducts;
    }
    return $result;
}