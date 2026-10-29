<?php
/**
 * Chatbot Helper — Hybrid rule-based + OpenAI fallback
 * Bao gồm: rules, gender detection, product search, stock check,
 *          promotions, product detail, context-aware, OpenAI integration
 */

function chatbotGenderLabel(string $gender): string {
    return [
        'male'   => 'Nam',
        'female' => 'Nữ',
        'unisex' => 'Unisex',
    ][$gender] ?? 'Unisex';
}

function chatbotDetectGenderFromName(string $name, string $dbGender = 'unisex'): string {
    if (in_array($dbGender, ['male', 'female'], true)) return $dbGender;

    $name = mb_strtolower($name);
    foreach (['nữ', 'nu ', ' nu', 'váy', 'đầm', 'chân váy'] as $kw) {
        if (mb_strpos($name, $kw) !== false) return 'female';
    }
    foreach (['nam', 'men', 'boy'] as $kw) {
        if (mb_strpos($name, $kw) !== false) return 'male';
    }
    return 'unisex';
}

/* ============================================================
   PARSE SỐ ĐO + TÍNH SIZE
   ============================================================ */
function chatbotParseMeasurements(string $text): ?array {
    $t = mb_strtolower(trim($text));

    // Chuẩn hóa "1m70" → "170"
    $t = preg_replace_callback('/1m(\d{1,2})\b/u', function ($m) {
        $cm = (int)$m[1];
        return $cm < 10 ? (string)(100 + $cm) : (string)(100 + $cm);
    }, $t);

    $numbers = [];
    if (preg_match_all('/\d+(?:\.\d+)?/u', $t, $m)) {
        $numbers = array_map('floatval', $m[0]);
    }
    if (count($numbers) < 2) return null;

    $height = null; $weight = null;
    foreach ($numbers as $n) {
        if ($height === null && $n >= 130 && $n <= 220)      $height = $n;
        elseif ($weight === null && $n >= 30 && $n <= 140)   $weight = $n;
    }
    if (!$height || !$weight) return null;
    return ['height' => $height, 'weight' => $weight];
}

function chatbotComputeSize(float $heightCm, float $weightKg): array {
    $bmi = $weightKg / (($heightCm / 100) ** 2);

    if ($weightKg < 50)      $size = 'S';
    elseif ($weightKg < 60)  $size = 'M';
    elseif ($weightKg < 70)  $size = 'L';
    elseif ($weightKg < 80)  $size = 'XL';
    else                     $size = '2XL';

    if ($heightCm >= 175 && $size !== '2XL' && $bmi >= 18.5) {
        $size = ['S'=>'M','M'=>'L','L'=>'XL','XL'=>'2XL'][$size] ?? $size;
    }
    if ($heightCm < 155 && $size !== 'S' && $bmi < 25) {
        $size = ['M'=>'S','L'=>'M','XL'=>'L','2XL'=>'XL'][$size] ?? $size;
    }

    if ($bmi < 18.5)      $note = 'Bạn hơi gầy — có thể chọn size nhỏ hơn nếu thích ôm vừa.';
    elseif ($bmi < 24.9)  $note = 'Vóc dáng cân đối — mặc size này sẽ vừa vặn.';
    elseif ($bmi < 29.9)  $note = 'Bạn hơi đầy đặn — nên chọn size này để thoải mái.';
    else                  $note = 'Nên chọn size này hoặc nhắn shop tư vấn thêm.';

    return ['size' => $size, 'bmi' => round($bmi, 1), 'height' => $heightCm, 'weight' => $weightKg, 'note' => $note];
}

function chatbotFormatSizeResponse(array $calc): string {
    $size = $calc['size'];
    $alt = ['S'=>[null,'M'],'M'=>['S','L'],'L'=>['M','XL'],'XL'=>['L','2XL'],'2XL'=>['XL',null]][$size] ?? [null,null];

    $altText = '';
    if ($alt[0] && $alt[1]) {
        $altText = "Nếu thích **ôm sát** → **{$alt[0]}** · Nếu thích **thoải mái** → **{$alt[1]}**\n\n";
    } elseif ($alt[0]) {
        $altText = "Nếu thích ôm sát hơn → **{$alt[0]}**\n\n";
    } elseif ($alt[1]) {
        $altText = "Nếu thích rộng hơn → **{$alt[1]}**\n\n";
    }

    return "**Kết quả tư vấn size:**\n\n"
         . "Chiều cao: **{$calc['height']}cm** · Cân nặng: **{$calc['weight']}kg** · BMI: **{$calc['bmi']}**\n\n"
         . "**Size phù hợp: {$size}**\n\n"
         . "{$calc['note']}\n\n"
         . $altText
         . "Bạn muốn mình gợi ý **sản phẩm size {$size}** không ạ?";
}

/* ============================================================
   RULES
   ============================================================ */
function chatbotGetRules(): array {
    return [
        /* ===== SỐ ĐO ===== */
        'measurements' => [
            'patterns' => [
                '/^\s*(1[3-9]\d|2[0-2]\d)\s+(3[0-9]|[4-9]\d|1[0-4]\d)\s*$/u',
                '/^\s*(3[0-9]|[4-9]\d|1[0-4]\d)\s+(1[3-9]\d|2[0-2]\d)\s*$/u',
                '/1m\d{1,3}\s*\d{2,3}\s*(kg)?/iu',
                '/\d{2,3}\s*(kg)?\s*1m\d{1,3}/iu',
                '/(cao|nặng)\s*1?m?\d/iu',
            ],
            'response' => '__COMPUTE_SIZE__',
        ],

        /* ===== CHÀO ===== */
        'greeting' => [
            'patterns' => [
                '/(^|\s)(xin\s+)?chào(\s+(shop|bạn|ad|em|anh|chị))?(\s|$)/iu',
                '/^(hi|hello|hey|hii+|helo)\b/iu',
            ],
            'response' => "Chào bạn! Mình là **Atelier Assistant**.\n\nMình giúp được bạn:\n- Tư vấn **size** — cho mình cân nặng + chiều cao\n- **Tìm sản phẩm** nam/nữ, theo mùa, dịp\n- **Kiểm tra tồn kho** — VD: \"áo thun nam còn M không\"\n- **Khuyến mãi** đang diễn ra\n- **Đổi trả**, **hủy đơn**, **địa chỉ**\n\nBạn cần gì ạ?",
        ],

        /* ===== HOÀN TRẢ ===== */
        'return_product' => [
            'patterns' => [
                '/hoàn\s+(trả|hàng|lại|tiền)/iu',
                '/trả\s+(hàng|lại|đồ|sản\s+phẩm)/iu',
                '/đổi\s+(trả|hàng|đồ|sản\s+phẩm)/iu',
                '/đổi\s+size|đổi\s+cỡ/iu',
                '/chính\s+sách\s+(hoàn|đổi|trả)/iu',
                '/bảo\s+hành/iu',
            ],
            'response' => "**Chính sách hoàn trả — THE ATELIER:**\n\n**Điều kiện đổi/trả:**\n- Trong **30 ngày** kể từ khi nhận hàng\n- Sản phẩm còn **nguyên tag**, chưa qua sử dụng\n- Còn **hoá đơn** hoặc mã đơn\n\n**Được đổi/trả khi:**\n- Lỗi từ shop → đổi mới / hoàn **100%**\n- Không vừa size → đổi size **miễn phí lần đầu**\n- Không đúng mô tả → hoàn **100%**\n\n**Không áp dụng:**\n- Sản phẩm sale trên **50%**\n- Đồ lót, phụ kiện cá nhân\n- SP đã qua sử dụng/giặt ủi\n\nBạn muốn đổi/trả SP nào ạ?",
        ],

        /* ===== HỦY ĐƠN ===== */
        'cancel_order' => [
            'patterns' => [
                '/hủy\s+(đơn|order|đặt\s+hàng)/iu',
                '/cancel\s+(đơn|order)/iu',
                '/không\s+muốn\s+(mua|đặt)\s+nữa/iu',
                '/muốn\s+hủy/iu',
            ],
            'response' => "**Hủy đơn hàng — THE ATELIER:**\n\n**Có thể hủy MIỄN PHÍ khi đơn ở:**\n- Chờ xử lý (pending)\n- Đang xử lý (processing)\n- Đã xác nhận (confirmed)\n\n**Không thể hủy khi đơn đã:**\n- Đang giao hàng\n- Đã giao thành công\n\n**Cách hủy:**\n1. Vào **Tài khoản** → **Đơn hàng của tôi**\n2. Chọn đơn → bấm **Hủy đơn hàng**\n3. Chọn lý do → xác nhận\n\nĐơn bank/card đã thanh toán sẽ được **hoàn tiền trong 12 giờ**.",
        ],

        /* ===== SIZE ===== */
        'size' => [
            'patterns' => [
                '/\bsize\b/iu',
                '/kích\s+(thước|cỡ)/iu',
                '/số\s+đo/iu',
                '/chọn\s+(size|số|kích)/iu',
            ],
            'response' => "**Hướng dẫn chọn size THE ATELIER:**\n\n| Cân nặng | Chiều cao | Size |\n|---|---|---|\n| 45-52kg | 1m50-1m58 | **S** |\n| 53-60kg | 1m55-1m65 | **M** |\n| 61-68kg | 1m60-1m72 | **L** |\n| 69-78kg | 1m65-1m78 | **XL** |\n| 79-90kg | 1m70-1m85 | **2XL** |\n\nNếu ở giữa 2 size → chọn **size lớn hơn** cho thoải mái.\n\nBạn cho mình **cân nặng + chiều cao** để tư vấn chính xác nhé!",
        ],

        /* ===== SHIP ===== */
        'ship' => [
            'patterns' => [
                '/phí\s+(ship|vận\s+chuyển|giao)/iu',
                '/ship\s+(bao\s+nhiêu|ntn)/iu',
                '/vận\s+chuyển|giao\s+hàng/iu',
                '/bao\s+lâu\s+(giao|nhận)/iu',
                '/freeship|free\s+ship/iu',
            ],
            'response' => "**Chính sách vận chuyển:**\n\n- **Miễn phí ship** đơn từ **500.000đ**\n- Đơn dưới 500k: phí **30.000đ** toàn quốc\n- **Thời gian giao:**\n  • Nội thành TP.HCM/HN: **1-2 ngày**\n  • Tỉnh khác: **2-4 ngày**\n\nĐơn được đóng gói cẩn thận, giao tận nơi!",
        ],

        /* ===== THANH TOÁN ===== */
        'payment' => [
            'patterns' => [
                '/thanh\s+toán/iu',
                '/phương\s+thức\s+trả/iu',
                '/\bcod\b/iu',
                '/chuyển\s+khoản/iu',
                '/thẻ\s+(tín\s+dụng|ghi\s+nợ)/iu',
            ],
            'response' => "**Phương thức thanh toán:**\n\n1. **COD** — Thanh toán khi nhận hàng (PIN 6 số)\n2. **Chuyển khoản** — QR / CK ngân hàng (PIN + OTP)\n3. **Thẻ tín dụng** — Visa, Mastercard, JCB (PIN + OTP)\n\n**Bảo mật 2 lớp:** Mọi giao dịch xác minh qua **PIN** + **OTP email**.",
        ],

        /* ===== KHUYẾN MÃI CHI TIẾT ===== */
        'promotion_detail' => [
            'patterns' => [
                '/(đang|hiện|sắp)\s+(có|có\s+chương)/iu',
                '/(chương\s+trình|khuyến\s+mãi|sale)\s+(gì|nào|đang|hiện|sắp)/iu',
                '/có\s+(chương\s+trình|khuyến\s+mãi|sale|giảm\s+giá)/iu',
                '/(sắp|tới)\s+(có|sẽ\s+có)\s+(khuyến\s+mãi|sale|giảm|ưu\s+đãi)/iu',
                '/giới\s+thiệu.*(khuyến\s+mãi|sale|ưu\s+đãi)/iu',
            ],
            'response' => '__SHOW_PROMOTIONS__',
        ],

        /* ===== KHUYẾN MÃI NGẮN ===== */
        'promotion' => [
            'patterns' => [
                '/giảm\s+giá|khuyến\s+mãi|voucher|mã\s+giảm|ưu\s+đãi/iu',
            ],
            'response' => "**Ưu đãi tại THE ATELIER:**\n\n- **8/3** → Giảm **30%** SP Nữ\n- **19/11** → Giảm **30%** SP Nam\n- **20/11** → Giảm **30%** SP Nữ\n- **Voucher sinh nhật** → Giảm **25%**\n\nHỏi mình \"**đang có chương trình gì**\" để xem chi tiết nhé!",
        ],

        /* ===== ĐỊA CHỈ ===== */
        'address' => [
            'patterns' => [
                '/địa\s+chỉ/iu',
                '/shop\s+(ở|tại)\s+đâu/iu',
                '/cửa\s+hàng\s+(ở|tại)\s+đâu/iu',
                '/showroom/iu',
            ],
            'response' => "**Địa chỉ cửa hàng THE ATELIER:**\n\n**Showroom chính:**\n123 Đường Thời Trang\nPhường Bến Nghé, Quận 1\nTP. Hồ Chí Minh\n\nGần ga Metro Bến Thành (đi bộ 5 phút)\nCó bãi giữ xe miễn phí\n\nGọi trước **1900 123 456** để được tư vấn nhé!",
        ],

        /* ===== GIỜ MỞ CỬA ===== */
        'opening_hours' => [
            'patterns' => [
                '/giờ\s+(mở|đóng|đóng\s+mở)\s*cửa/iu',
                '/mở\s+cửa\s+(lúc|khi|từ)/iu',
                '/giờ\s+làm\s+việc/iu',
                '/(mấy\s+giờ|bao\s+giờ)\s+(mở|đóng)/iu',
                '/cuối\s+tuần.*(mở|có)/iu',
            ],
            'response' => "**Giờ mở cửa THE ATELIER:**\n\n| Ngày | Giờ |\n|---|---|\n| Thứ 2 - Thứ 6 | 9:00 - 21:30 |\n| Thứ 7 | 9:00 - 22:00 |\n| Chủ nhật | 9:30 - 21:00 |\n| Ngày lễ | 10:00 - 20:00 |\n\nMở cửa **tất cả các ngày** · Hotline online **24/7** · Website đặt hàng **không giới hạn giờ**!",
        ],

        /* ===== LIÊN HỆ ===== */
        'contact' => [
            'patterns' => [
                '/hotline|số\s+điện\s+thoại/iu',
                '/liên\s+hệ/iu',
                '/\bemail\b/iu',
            ],
            'response' => "**Liên hệ THE ATELIER:**\n\n- **Hotline:** 1900 123 456 (8h - 22h)\n- **Email:** hello@theatelier.vn\n- **Địa chỉ:** 123 Đường Thời Trang, Q.1, TP.HCM\n\nBạn có thể chat trực tiếp với mình 24/7 nhé!",
        ],

        /* ===== CẢM ƠN ===== */
        'thanks' => [
            'patterns' => [
                '/cảm\s+ơn|thank|thanks|tks/iu',
                '/ok(e)?\s+(shop|bạn|ad)/iu',
            ],
            'response' => "Dạ không có gì ạ! Cần gì thêm bạn cứ nhắn mình nhé. Chúc bạn mua sắm vui vẻ tại THE ATELIER!",
        ],

        /* ===== TẠM BIỆT ===== */
        'goodbye' => [
            'patterns' => ['/tạm\s+biệt|\bbye\b|goodbye/iu'],
            'response' => "Tạm biệt bạn! Hẹn gặp lại tại **THE ATELIER** nhé!",
        ],

        /* ===== TƯ VẤN GIÁ / COMBO ===== */
        'price_advice' => [
            'patterns' => [
                '/(mua|tư\s+vấn).*(tiết\s+kiệm|rẻ|tối\s+ưu|hợp\s+lý)/iu',
                '/(combo|set)\s+(nào|gì).*(tốt|hợp|rẻ|tiết\s+kiệm)/iu',
                '/tư\s+vấn\s+(mua|chọn).*(combo|set|nhiều)/iu',
                '/nên\s+mua\s+(gì|combo|set)/iu',
                '/mua\s+combo\s+(lợi|có\s+lợi|tốt)\s+(gì|hơn)/iu',
            ],
            'response' => "**Tư vấn mua hàng tiết kiệm:**\n\n**Combo tiết kiệm 15-25%** so với mua lẻ:\n- Combo Áo thun + Quần jean nam — chỉ **499.000đ**\n- Combo Áo khoác + Quần dài nữ — chỉ **699.000đ**\n\n**Mẹo mua thông minh:**\n1. **Chọn combo** thay vì mua lẻ → tiết kiệm 15-25%\n2. **Đơn từ 500.000đ** → **miễn phí ship**\n3. **Voucher sinh nhật** → giảm thêm **25%**\n4. **Mua dịp sale** — 8/3, 19/11, 20/11 giảm **30%**\n\nHỏi mình \"**xem combo**\" để xem các combo đang hot nhé!",
        ],

        /* ===== TRẺ EM ===== */
        'kids' => [
            'patterns' => [
                '/(đồ|quần\s+áo|thời\s+trang)\s+(trẻ\s+em|em\s+bé|cho\s+bé|con\s+nít)/iu',
                '/trẻ\s+em|em\s+bé|con\s+nít|thiếu\s+nhi/iu',
            ],
            'response' => "**Đồ trẻ em:**\n\nRất tiếc, hiện tại **THE ATELIER chưa có dòng sản phẩm dành riêng cho trẻ em** ạ\n\n**Gợi ý:**\n- Tham khảo **size nhỏ nhất (S)** cho các bé lớn (12-14 tuổi)\n- Xem bộ sưu tập **Unisex** phù hợp cả gia đình\n\n**Đăng ký nhận tin** ở footer để được thông báo khi shop mở rộng dòng trẻ em nhé!",
        ],

        /* ===== MÙA HÈ ===== */
        'summer' => [
            'patterns' => [
                '/(đồ|quần\s+áo|thời\s+trang)\s+(mùa\s+hè|hè)/iu',
                '/mùa\s+hè|đồ\s+hè/iu',
                '/(mát|thoáng|mỏng|nhẹ).*(mùa\s+hè|hè|nóng)/iu',
                '/(mặc|mua)\s+(gì|đồ).*(hè|nóng)/iu',
            ],
            'response' => '__SHOW_SUMMER_PRODUCTS__',
        ],

        /* ===== MÙA ĐÔNG ===== */
        'winter' => [
            'patterns' => [
                '/(đồ|quần\s+áo|thời\s+trang)\s+(mùa\s+đông|đông)/iu',
                '/mùa\s+đông|đồ\s+đông/iu',
                '/(ấm|dày|len|khoác).*(mùa\s+đông|đông|lạnh)/iu',
                '/(mặc|mua)\s+(gì|đồ).*(đông|lạnh)/iu',
            ],
            'response' => '__SHOW_WINTER_PRODUCTS__',
        ],

        /* ===== THỂ THAO ===== */
        'sport' => [
            'patterns' => [
                '/(đồ|quần\s+áo)\s+(thể\s+thao|gym|chạy\s+bộ|tập\s+luyện)/iu',
                '/thể\s+thao|\bgym\b|yoga|chạy\s+bộ/iu',
                '/(mặc|mua)\s+(gì|đồ).*(tập|gym|chạy)/iu',
            ],
            'response' => '__SHOW_SPORT_PRODUCTS__',
        ],

        /* ===== ĐI CHƠI ===== */
        'casual' => [
            'patterns' => [
                '/(đồ|quần\s+áo).*(đi\s+chơi|dạo\s+phố|đi\s+cà\s+phê)/iu',
                '/(mặc|mua)\s+(gì|đồ).*(đi\s+chơi|dạo|chơi)/iu',
                '/đồ\s+(đi\s+chơi|dạo\s+phố|casual)/iu',
                '/style\s+(casual|năng\s+động)/iu',
            ],
            'response' => '__SHOW_CASUAL_PRODUCTS__',
        ],

        /* ===== ĐI LÀM ===== */
        'office' => [
            'patterns' => [
                '/(đồ|quần\s+áo).*(đi\s+làm|công\s+sở|văn\s+phòng)/iu',
                '/(mặc|mua)\s+(gì|đồ).*(đi\s+làm|công\s+sở|văn\s+phòng)/iu',
                '/đồ\s+(đi\s+làm|văn\s+phòng|office)/iu',
                '/(sang\s+trọng|lịch\s+sự|thanh\s+lịch)/iu',
            ],
            'response' => '__SHOW_OFFICE_PRODUCTS__',
        ],

        /* ===== ĐỒ NAM ===== */
        'product_male' => [
            'patterns' => [
                '/(đồ|quần\s+áo|thời\s+trang|sản\s+phẩm)\s+(nam|men)/iu',
                '/^(nam|men)\s+(có\s+gì|thế\s+nào)/iu',
            ],
            'response' => '__SHOW_MALE_PRODUCTS__',
        ],

        /* ===== ĐỒ NỮ ===== */
        'product_female' => [
            'patterns' => [
                '/(đồ|quần\s+áo|thời\s+trang|sản\s+phẩm)\s+(nữ|nu|women)/iu',
                '/^(nữ|women)\s+(có\s+gì|thế\s+nào)/iu',
            ],
            'response' => '__SHOW_FEMALE_PRODUCTS__',
        ],
                /* ============================================================
           BỔ SUNG 20 NHÓM CÂU HỎI THƯỜNG GẶP
           ============================================================ */

        /* ===== 1. KIỂM TRA ĐƠN HÀNG ===== */
        'order_tracking' => [
            'patterns' => [
                '/(kiểm\s+tra|check|tra\s+cứu|xem)\s+(đơn|order)/iu',
                '/(đơn|order)\s+(của\s+tôi|mình)\s+(đâu|đến\s+đâu|sao\s+rồi|thế\s+nào)/iu',
                '/(đơn|order)\s+(hàng)?\s*(đang|đã|sẽ)\s+(giao|ship|đi)/iu',
                '/bao\s+giờ\s+(đơn|hàng|order).*(đến|giao|nhận)/iu',
                '/(tracking|vận\s+đơn|mã\s+vận\s+đơn)/iu',
            ],
            'response' => "**Kiểm tra đơn hàng — THE ATELIER:**\n\n**Cách 1 — Tự tra cứu:**\n1. Đăng nhập tài khoản\n2. Vào **Đơn hàng của tôi**\n3. Xem **trạng thái + mã vận đơn**\n\n**Cách 2 — Nhắn mình:**\nCho mình **mã đơn** (VD: `#AT12345`) hoặc **SĐT đặt hàng**, mình sẽ kiểm tra giúp bạn.\n\n**Thời gian giao:**\n- Nội thành HCM/HN: **1-2 ngày**\n- Tỉnh khác: **2-4 ngày**\n\nĐơn quá 4 ngày chưa nhận → gọi hotline **1900 123 456** ạ!",
        ],

        /* ===== 2. ĐỔI ĐỊA CHỈ / SĐT ĐƠN HÀNG ===== */
        'order_modify' => [
            'patterns' => [
                '/(đổi|thay|sửa|chỉnh)\s+(địa\s+chỉ|sđt|số\s+điện\s+thoại|số\s+nhà)/iu',
                '/(địa\s+chỉ|sđt|số\s+điện\s+thoại)\s+(sai|nhầm|không\s+đúng|bị\s+sai)/iu',
                '/muốn\s+(đổi|thay)\s+(địa\s+chỉ|sđt)/iu',
            ],
            'response' => "**Đổi địa chỉ / SĐT giao hàng:**\n\n**Còn kịp đổi khi đơn đang:**\n- Chờ xử lý (pending)\n- Đang xử lý (processing)\n\n**Cách đổi:**\n1. Vào **Tài khoản** → **Đơn hàng của tôi**\n2. Chọn đơn → bấm **Yêu cầu thay đổi**\n3. Nhập địa chỉ/SĐT mới → xác nhận\n\n**Đơn đã giao cho đơn vị vận chuyển:**\nGọi ngay hotline **1900 123 456** để shop liên hệ shipper hỗ trợ đổi.\n\nLưu ý: Đơn COD có thể không đổi được sau khi đã ship.",
        ],

        /* ===== 3. CÁCH DÙNG VOUCHER ===== */
        'voucher_usage' => [
            'patterns' => [
                '/nhập\s+(mã|voucher|code)\s+(ở\s+đâu|như\s+thế\s+nào|ntn)/iu',
                '/(mã|voucher|code)\s+(không|ko|k)\s+(dùng|áp|sử\s+dụng)\s+(được|dc)/iu',
                '/cách\s+(dùng|áp|nhập)\s+(mã|voucher|code)/iu',
                '/(voucher|mã\s+giảm)\s+(bị|không)\s+(lỗi|hết\s+hạn)/iu',
            ],
            'response' => "**Hướng dẫn dùng mã giảm giá:**\n\n**Cách nhập:**\n1. Thêm sản phẩm vào **Giỏ hàng**\n2. Vào **Thanh toán**\n3. Tìm ô **\"Mã giảm giá\"** bên phải\n4. Nhập mã → bấm **Áp dụng**\n\n**Mã không dùng được?** Kiểm tra:\n- Mã còn **hạn** không?\n- Đơn đã đạt **giá trị tối thiểu**?\n- Sản phẩm có nằm trong **loại áp dụng**?\n- Bạn đã dùng mã này **rồi** chưa?\n\nHỏi mình \"**đang có mã gì**\" để xem các mã còn hiệu lực nhé!",
        ],

        /* ===== 4. BẢO QUẢN / CHẤT LIỆU ===== */
        'product_care' => [
            'patterns' => [
                '/cách\s+(giặt|bảo\s+quản|giữ|ủi|là)/iu',
                '/(giặt|ủi|bảo\s+quản)\s+(như\s+thế\s+nào|ntn|sao|ra\s+sao)/iu',
                '/(có|bị)\s+(phai\s+màu|co\s+rút|nhão|giãn)/iu',
                '/chất\s+liệu\s+(vải|áo|sản\s+phẩm)/iu',
                '/(vải|chất\s+liệu).*(gì|nào|thoáng|co\s+giãn)/iu',
            ],
            'response' => "**Bảo quản sản phẩm THE ATELIER:**\n\n**Chất liệu thường dùng:**\n- Cotton 100% — thoáng mát, thấm hút tốt\n- Cotton pha Spandex — co giãn nhẹ, giữ form\n- Kaki / Jean — bền, ít nhăn\n- Lụa / Voan — mềm, mát (dòng nữ)\n\n**Cách giặt đúng:**\n- Giặt máy **nước lạnh** (≤30°C)\n- Lộn trái, túi giặt cho đồ có in/thêu\n- **Không dùng** chất tẩy mạnh\n- Phơi **trong bóng râm**, tránh nắng gắt\n- Ủi ở nhiệt độ **trung bình**\n\n**Màu đậm** (đen, navy) nên giặt riêng lần đầu để tránh lem màu nhé!",
        ],

        /* ===== 5. HÀNG HẾT KHI NÀO CÓ LẠI ===== */
        'restock_notification' => [
            'patterns' => [
                '/(khi\s+nào|bao\s+giờ)\s+(có|có\s+lại|nhập|restock)/iu',
                '/(hết\s+hàng|out\s+of\s+stock).*(khi\s+nào|bao\s+giờ|có\s+lại)/iu',
                '/(thông\s+báo|báo|nhắc)\s+(tôi|mình)?\s*(khi|nếu)\s+(có\s+hàng|lại\s+hàng)/iu',
                '/(đăng\s+ký|nhận\s+tin)\s+(khi\s+có\s+hàng|restock)/iu',
            ],
            'response' => "**Thông báo khi có hàng lại:**\n\n**Cách 1 — Để lại SĐT:**\nNhắn mình **mã sản phẩm** hoặc **tên SP + size** + **SĐT** → shop sẽ gọi khi có hàng.\n\n**Cách 2 — Theo dõi tự động:**\n1. Vào trang sản phẩm đang hết hàng\n2. Bấm nút **\"Thông báo khi có hàng\"**\n3. Nhập email/SĐT → nhận thông báo tự động\n\n**Thời gian nhập hàng:**\n- Sản phẩm **bán chạy** → 3-7 ngày\n- Sản phẩm **theo mùa** → 7-14 ngày\n- **Limited Edition** → có thể không nhập lại\n\nBạn muốn thông báo cho sản phẩm nào ạ?",
        ],

        /* ===== 6. MUA SỈ / ĐẠI LÝ ===== */
        'wholesale' => [
            'patterns' => [
                '/(mua|đặt|lấy)\s+(sỉ|buôn|nhiều|số\s+lượng\s+lớn)/iu',
                '/(đại\s+lý|nhà\s+phân\s+phối|ctv|bán\s+hàng)\s+(đăng\s+ký|hợp\s+tác)/iu',
                '/chiết\s+khấu\s+(sỉ|buôn|số\s+lượng)/iu',
                '/(bán\s+buôn|wholesale)\s+(giá|bao\s+nhiêu)/iu',
            ],
            'response' => "**Chính sách mua sỉ / Đại lý THE ATELIER:**\n\n**Điều kiện:**\n- Đơn tối thiểu **20 sản phẩm** (cùng loại)\n- Hoặc tổng đơn từ **10.000.000đ**\n\n**Chiết khấu:**\n- 20-49 SP: **15%**\n- 50-99 SP: **20%**\n- 100+ SP: **25-30%** (thương lượng)\n\n**Hỗ trợ:**\n- Giao hàng toàn quốc\n- Được đổi mẫu trong 7 ngày\n- Có hợp đồng + hóa đơn VAT\n\n**Liên hệ:**\nEmail **wholesale@theatelier.vn**\nHotline **1900 123 456** (nhánh 2)\n\nBạn để lại SĐT + nhu cầu, mình chuyển bộ phận sỉ liên hệ ngay nhé!",
        ],

        /* ===== 7. THẺ THÀNH VIÊN / TÍCH ĐIỂM ===== */
        'loyalty_program' => [
            'patterns' => [
                '/(thẻ|hội\s+viên|thành\s+viên)\s+(khách\s+hàng)?.*(đăng\s+ký|làm|thế\s+nào|lợi\s+ích)/iu',
                '/tích\s+(điểm|lũy)\s+(như\s+thế\s+nào|ntn|sao|lợi\s+ích)/iu',
                '/(điểm|loyalty|khách\s+vip)\s+(thưởng|đổi|quy\s+đổi)/iu',
                '/chương\s+trình\s+(thành\s+viên|khách\s+hàng\s+thân\s+thiết)/iu',
            ],
            'response' => "**Thẻ thành viên THE ATELIER:**\n\n**Hạng thẻ & quyền lợi:**\n| Hạng | Điều kiện | Quyền lợi |\n|---|---|---|\n| **Silver** | Đơn đầu tiên | Tích 1% |\n| **Gold** | Tổng 5.000.000đ | Tích 2% + Freeship |\n| **Platinum** | Tổng 20.000.000đ | Tích 3% + ưu tiên sale |\n\n**Cách đăng ký:**\n1. Tạo tài khoản trên website (miễn phí)\n2. Mỗi đơn hoàn tất → tự tích điểm\n3. Điểm = VNĐ, dùng giảm giá đơn sau\n\n**Quà tặng:**\n- Sinh nhật: voucher **25%**\n- Giới thiệu bạn bè: cả 2 nhận **50.000đ**\n\nBạn muốn xem điểm hiện có? Vào **Tài khoản** → **Điểm thưởng** nhé!",
        ],

        /* ===== 8. GÓI QUÀ ===== */
        'gift_wrap' => [
            'patterns' => [
                '/(gói|đóng|bọc)\s+(quà|hộp|gift)/iu',
                '/(thiệp|card|note)\s+(chúc|gửi|tặng|kèm)/iu',
                '/quà\s+tặng\s+(sinh\s+nhật|kỷ\s+niệm|valentine|8/3|20/10)/iu',
                '/có\s+(gói|đóng)\s+quà\s+(không|ko|k)/iu',
            ],
            'response' => "**Dịch vụ gói quà THE ATELIER:**\n\n**Miễn phí:**\n- Túi giấy + ribbon thương hiệu\n- Thiệp chúc viết tay (nếu yêu cầu)\n\n**Có phí — Gói Premium (30.000đ):**\n- Hộp cứng cao cấp\n- Ruy băng lụa + hoa khô\n- Thiệp in theo yêu cầu\n\n**Cách yêu cầu:**\nKhi đặt hàng, ghi chú vào ô **\"Ghi chú đơn hàng\"** VD:\n> _\"Gói quà sinh nhật, thiệp: Chúc mừng sinh nhật em yêu!\"_\n\nShop sẽ chuẩn bị và chụp ảnh gửi bạn trước khi ship nhé!",
        ],

        /* ===== 9. PHÍ ĐỔI TRẢ ===== */
        'exchange_fee' => [
            'patterns' => [
                '/phí\s+(đổi|trả|hoàn)/iu',
                '/(đổi|trả)\s+(có\s+mất\s+phí|tốn\s+phí|mất\s+tiền|free)/iu',
                '/(đổi\s+size|đổi\s+mẫu)\s+(có\s+mất\s+phí|tốn\s+tiền|free)/iu',
                '/(miễn\s+phí|free)\s+(đổi|trả)/iu',
            ],
            'response' => "**Phí đổi/trả hàng:**\n\n**Miễn phí — Khi lỗi từ shop:**\n- Giao sai mẫu/size/màu\n- Sản phẩm lỗi, rách, hỏng\n- Thiếu phụ kiện\n→ Shop chịu **100%** phí 2 chiều\n\n**Có phí — Khi đổi ý:**\n- Đổi size/mẫu **lần đầu**: **Miễn phí** (khách chịu phí ship 1 chiều ~30.000đ)\n- Đổi **lần 2 trở đi**: 30.000đ/lần\n- Trả hàng không mua lại: phí **30.000đ**\n\n**Lưu ý:**\n- Đổi trong **30 ngày**\n- SP còn nguyên tag, chưa qua sử dụng\n\nBạn muốn đổi sản phẩm nào ạ?",
        ],

        /* ===== 10. ĐỒNG KIỂM KHI NHẬN ===== */
        'inspect_before_pay' => [
            'patterns' => [
                '/(đồng\s+kiểm|kiểm\s+tra\s+hàng|coi\s+hàng|xem\s+hàng)\s+(trước|khi|rồi)/iu',
                '/(có\s+được|cho)\s+(xem|kiểm|coi|thử)\s+(hàng|đồ)\s+(trước|khi)/iu',
                '/đồng\s+kiểm\s+(khi\s+nhận|cod)/iu',
                '/(nhận\s+hàng|giao\s+hàng)\s+(có\s+được|cho)\s+(xem|kiểm|thử)/iu',
            ],
            'response' => "**Đồng kiểm khi nhận hàng (COD):**\n\n**Được phép:**\n- Mở hộp kiểm tra **số lượng, mẫu mã, size, màu**\n- **Không thử** (mặc) sản phẩm\n- Quay video mở hộp để đối chiếu\n\n**Quy trình:**\n1. Nhận hàng từ shipper\n2. **Quay video** mở hộp\n3. Kiểm tra sản phẩm trong **2-3 phút**\n4. Đúng → thanh toán\n5. Sai/lỗi → **từ chối nhận**, shipper hoàn về shop\n\n**Lưu ý:**\n- Đơn **chuyển khoản trước** không áp dụng đồng kiểm\n- Shipper có quyền **không cho đồng kiểm** với đơn giá trị cao (tùy đơn vị)\n\nBạn yên tâm đặt hàng nhé!",
        ],

        /* ===== 11. HÓA ĐƠN VAT ===== */
        'invoice_vat' => [
            'patterns' => [
                '/(hóa\s+đơn|hoá\s+đơn|invoice)\s+(vat|đỏ|điện\s+tử|công\s+ty)/iu',
                '/(xuất|viết|lấy)\s+(hóa\s+đơn|hoá\s+đơn|vat)/iu',
                '/công\s+ty\s+(có\s+được|xuất)\s+(hóa\s+đơn|vat)/iu',
            ],
            'response' => "**Xuất hóa đơn VAT:**\n\n**Hỗ trợ khi:**\n- Đơn từ **2.000.000đ**\n- Khách hàng là **công ty** hoặc **cá nhân kinh doanh**\n\n**Cách yêu cầu:**\n1. Khi thanh toán → tick **\"Xuất hóa đơn VAT\"**\n2. Điền: **Tên công ty, MST, địa chỉ, email**\n3. Hóa đơn điện tử gửi về email trong **24h** sau khi đơn hoàn tất\n\n**Ngoài ra:**\n- Hóa đơn **bán lẻ** (không VAT): có sẵn mọi đơn, in từ **Tài khoản → Đơn hàng**\n\nCần hỗ trợ? Gọi **1900 123 456** (nhánh 3) — bộ phận kế toán nhé!",
        ],

        /* ===== 12. CHÍNH HÃNG / AUTH ===== */
        'authenticity' => [
            'patterns' => [
                '/(hàng|sản\s+phẩm)\s+(chính\s+hãng|auth|authentic|thật|real)/iu',
                '/(có\s+phải|đây\s+là)\s+(hàng\s+thật|hàng\s+auth|fake|nhái)/iu',
                '/(hàng|sản\s+phẩm)\s+(fake|nhái|giả|copy)/iu',
                '/(cam\s+kết|đảm\s+bảo)\s+(chính\s+hãng|chất\s+lượng)/iu',
            ],
            'response' => "**Cam kết chất lượng THE ATELIER:**\n\n**100% chính hãng — Made by THE ATELIER:**\n- Tự thiết kế + sản xuất tại xưởng Việt Nam\n- Không bán hàng **fake, nhái, rep** thương hiệu khác\n- Mỗi SP có **tag + mã QR** xác thực\n\n**Quy trình kiểm soát:**\n1. QC nguyên liệu đầu vào\n2. QC sau may (form, đường chỉ, màu)\n3. QC đóng gói (ảnh + video)\n\n**Nếu phát hiện lỗi từ shop:**\n- Đổi mới **100%**\n- Hoàn tiền **100%** nếu không còn hàng\n- Bồi thường theo **chính sách đổi trả**\n\n**Xác thực nhanh:**\nQuét QR trên tag → hiển thị **thông tin sản phẩm** chính thức.\n\nBạn yên tâm mua sắm nhé!",
        ],

        /* ===== 13. KÊNH BÁN HÀNG CHÍNH THỨC ===== */
        'official_channels' => [
            'patterns' => [
                '/(bán|bán\s+hàng|có)\s+(trên|ở)\s+(shopee|lazada|tiki|tiktok)/iu',
                '/(shopee|lazada|tiki|tiktok|facebook|instagram)\s+(shop|của\s+shop|chính\s+thức)/iu',
                '/kênh\s+(bán|phân\s+phối)\s+(chính\s+thức|nào)/iu',
                '/(mua|đặt)\s+(hàng)?\s+(ở\s+đâu|chỗ\s+nào|địa\s+chỉ\s+nào)/iu',
            ],
            'response' => "**Kênh bán hàng chính thức THE ATELIER:**\n\n**Website (chính):**\n- Đặt hàng + thanh toán online\n- Xem đầy đủ SP, size, tồn kho\n- **Ưu đãi tốt nhất** — voucher độc quyền\n\n**Mạng xã hội:**\n- Facebook: **fb.com/theatelier.vn**\n- Instagram: **@theatelier.vn**\n- TikTok Shop: **@theatelier.vn**\n\n**Sàn TMĐT:**\n- Shopee: **TheAtelier Official**\n- Lazada: **TheAtelier Official**\n\n**Showroom:**\n123 Đường Thời Trang, Q.1, TP.HCM\n\n**Cảnh báo:** Shop **KHÔNG** bán qua Zalo cá nhân, không yêu cầu CK vào STK lạ. Đặt hàng chỉ qua các kênh trên nhé!",
        ],

        /* ===== 14. PHÀN NÀN / GÓP Ý ===== */
        'complaint' => [
            'patterns' => [
                '/(phàn\s+nàn|khiếu\s+nại|báo\s+cáo|tố\s+cáo)/iu',
                '/(góp\s+ý|feedback|đánh\s+giá)\s+(shop|cửa\s+hàng|dịch\s+vụ)/iu',
                '/(dịch\s+vụ|nhân\s+viên|shipper)\s+(tệ|kém|thái\s+độ|không\s+tốt)/iu',
                '/(sản\s+phẩm|hàng)\s+(bị|bị)\s+(lỗi|hỏng|rách|sai)/iu',
            ],
            'response' => "**Tiếp nhận phản hồi THE ATELIER:**\n\n**Mình rất xin lỗi vì trải nghiệm chưa tốt!**\nShop ghi nhận mọi phản hồi để cải thiện.\n\n**Cách gửi phản hồi:**\n1. **Hotline:** 1900 123 456 (8h-22h)\n2. **Email:** support@theatelier.vn\n3. **Form:** Tài khoản → **Gửi phản hồi**\n4. **Chat trực tiếp** với mình — mình sẽ ghi nhận\n\n**Với sản phẩm lỗi:**\n1. Chụp ảnh/video sản phẩm\n2. Gửi qua email hoặc chat\n3. Shop phản hồi trong **2h làm việc**\n4. Đổi mới/hoàn tiền theo chính sách\n\nBạn vui lòng **mô tả vấn đề** để mình chuyển bộ phận xử lý nhanh nhất ạ!",
        ],

        /* ===== 15. SHIP QUỐC TẾ ===== */
        'international_shipping' => [
            'patterns' => [
                '/(ship|giao|gửi|vận\s+chuyển)\s+(quốc\s+tế|nước\s+ngoài|hải\s+ngoại|mỹ|canada|úc|nhật|hàn)/iu',
                '/(ở|tại|đang\s+ở)\s+(nước\s+ngoài|mỹ|canada|úc|nhật|hàn|đức|pháp).*(mua|đặt)/iu',
                '/giao\s+hàng\s+(quốc\s+tế|international)/iu',
            ],
            'response' => "**Giao hàng quốc tế:**\n\n**Hiện tại THE ATELIER:**\n- Giao **toàn quốc Việt Nam**\n- **Chưa** hỗ trợ ship quốc tế trực tiếp\n\n**Giải pháp cho khách nước ngoài:**\n1. **Nhờ người thân** ở VN đặt + nhận hàng\n2. **Dùng dịch vụ chuyển phát** (VD: Viettel Post, DHL) — bạn tự sắp xếp\n3. **Liên hệ trực tiếp** hotline để được tư vấn case-by-case\n\n**Lưu ý:**\n- Đơn quốc tế qua dịch vụ trung gian: shop không chịu phí ship + thuế nhập khẩu\n- Chưa hỗ trợ thanh toán quốc tế (chỉ COD/CK VNĐ)\n\nShop đang xem xét mở rộng ship quốc tế trong tương lai ạ!",
        ],

        /* ===== 16. THỬ ĐỒ TẠI SHOWROOM ===== */
        'try_on' => [
            'patterns' => [
                '/(thử|mặc\s+thử|try)\s+(đồ|hàng|sản\s+phẩm|quần\s+áo)\s+(ở|tại|tại\s+shop)/iu',
                '/(đến|có\s+thể\s+đến|ghé)\s+(shop|showroom|cửa\s+hàng)\s+(thử|mặc)/iu',
                '/thử\s+đồ\s+(trước|khi)\s+(mua|mua\s+online)/iu',
                '/(có|cho)\s+(thử|mặc\s+thử)\s+(đồ|hàng)/iu',
            ],
            'response' => "**Thử đồ tại Showroom THE ATELIER:**\n\n**Địa chỉ:**\n123 Đường Thời Trang, P. Bến Nghé, Q.1, TP.HCM\n\n**Giờ mở cửa:**\n- T2-T6: 9:00 - 21:30\n- T7: 9:00 - 22:00\n- CN: 9:30 - 21:00\n\n**Quy trình:**\n1. Đến showroom, nhân viên tư vấn\n2. Chọn mẫu + size muốn thử\n3. Phòng thử **riêng tư**, có gương lớn\n4. Thoải mái thử **không giới hạn** số lượng\n\n**Lưu ý:**\n- **Không cần đặt trước**\n- Có thể **giữ hàng** trước 2h nếu gọi hotline\n- Có bãi xe miễn phí\n\nBạn muốn giữ mẫu nào để đến thử? Nhắn mình nhé!",
        ],

        /* ===== 17. THỜI GIAN HOÀN TIỀN ===== */
        'refund_timing' => [
            'patterns' => [
                '/bao\s+lâu\s+(thì\s+)?(hoàn\s+tiền|nhận\s+lại\s+tiền|refund)/iu',
                '/(hoàn\s+tiền|refund)\s+(bao\s+lâu|khi\s+nào|mấy\s+ngày)/iu',
                '/(tiền\s+hoàn|tiền\s+trả)\s+(vào|đến|về)\s+(đâu|khi\s+nào)/iu',
            ],
            'response' => "**Thời gian hoàn tiền:**\n\n**Đơn COD (chưa thanh toán):**\n- Hủy đơn → **không cần hoàn tiền**\n\n**Đơn Chuyển khoản / Ví điện tử:**\n- Shop xử lý trong **12 giờ làm việc**\n- Tiền vào tài khoản: **1-3 ngày** (tùy ngân hàng)\n\n**Đơn Thẻ tín dụng:**\n- Shop xử lý trong **12 giờ**\n- Ngân hàng hoàn: **3-7 ngày làm việc**\n- Có thể hiển thị ở **sao kê kỳ sau**\n\n**Trả hàng hoàn tiền:**\n- Nhận hàng hoàn → kiểm tra **1-2 ngày**\n- Xác nhận → hoàn tiền **3-5 ngày**\n\n**Lưu ý:**\n- Phí ship **không hoàn** (trừ lỗi shop)\n- Mọi giao dịch đều có **email xác nhận**\n\nBạn đang chờ hoàn tiền đơn nào? Cho mình **mã đơn** để kiểm tra ạ!",
        ],

        /* ===== 18. PHỐI ĐỒ / MIX MATCH ===== */
        'mix_match' => [
            'patterns' => [
                '/(phối|mix|kết\s+hợp)\s+(đồ|outfit|trang\s+phục|với)/iu',
                '/(gợi\s+ý|tư\s+vấn)\s+(phối|outfit|mix\s+match|style)/iu',
                '/(mặc|diện)\s+(gì|sao)\s+(đẹp|hợp|với)/iu',
                '/(áo|quần|váy)\s+(này|đó)\s+(mặc|phối)\s+(với\s+gì|sao)/iu',
            ],
            'response' => "**Gợi ý phối đồ THE ATELIER:**\n\n**Cho Nam:**\n- **Áo thun trắng + Quần jean xanh** — basic, dễ mặc\n- **Sơ mi + Quần tây** — lịch sự đi làm\n- **Áo khoác bomber + Jogger** — năng động, đường phố\n- **Polo + Short kaki** — casual cuối tuần\n\n**Cho Nữ:**\n- **Váy hoa + Sandal** — dịu dàng, mùa hè\n- **Sơ mi trắng + Chân váy** — thanh lịch công sở\n- **Áo thun oversize + Jean** — cá tính\n- **Đầm suông + Túi tote** — thanh lịch đi tiệc\n\n**Nguyên tắc phối:**\n- Tối đa **3 màu** trong 1 outfit\n- **Mix basic + statement** — 1 món nổi, còn lại trung tính\n\nBạn đang có món gì? Mình gợi ý phối cụ thể hơn nhé!",
        ],

        /* ===== 19. FORM / ĐỘ ÔM CỦA SẢN PHẨM ===== */
        'size_fit' => [
            'patterns' => [
                '/(form|dáng)\s+(áo|quần|váy|sản\s+phẩm)\s+(rộng|ôm|vừa|to|nhỏ|slim)/iu',
                '/(áo|quần|váy|sản\s+phẩm)\s+(này|đó)\s+(rộng|ôm|vừa|to|nhỏ)/iu',
                '/(có|bị)\s+(rộng|ôm|chật|to)\s+(không|ko|k)/iu',
                '/(size|sz)\s+(này|đó)\s+(to|nhỏ|rộng|chật)/iu',
            ],
            'response' => "**Về form dáng sản phẩm THE ATELIER:**\n\n**Các dòng form phổ biến:**\n- **Regular fit** — vừa vặn, chuẩn form\n- **Slim fit** — ôm nhẹ, tôn dáng\n- **Oversize** — rộng thoải mái, trẻ trung\n- **Relaxed fit** — rộng nhẹ, dễ chịu\n\n**Lưu ý chung:**\n- Áo thun nam thường **regular fit** — đúng size\n- Áo thun nữ **có thể ôm hơn 1 size** — nếu thích rộng nên **tăng 1 size**\n- Quần jean **co giãn nhẹ** — form chuẩn\n- Đầm/váy: thường **ôm nhẹ** ở eo\n\n**Xem chi tiết:**\nMỗi sản phẩm có ghi **form** + **bảng size** trên trang chi tiết.\n\nNếu vẫn phân vân, cho mình **cân nặng + chiều cao**, mình tư vấn size chính xác nhé!",
        ],

        /* ===== 20. THỜI HẠN KHUYẾN MÃI ===== */
        'promotion_expiry' => [
            'patterns' => [
                '/(sale|khuyến\s+mãi|giảm\s+giá)\s+(đến|tới|kéo\s+dài)\s+(khi\s+nào|bao\s+lâu|ngày)/iu',
                '/(đến|tới)\s+(khi\s+nào|bao\s+giờ|ngày\s+nào)\s+(hết|kết\s+thúc)\s+(sale|khuyến\s+mãi|giảm\s+giá)/iu',
                '/(sale|khuyến\s+mãi).*(còn|sắp)\s+(hết|kết\s+thúc)/iu',
                '/(hạn|thời\s+hạn)\s+(sale|khuyến\s+mãi|voucher)/iu',
            ],
            'response' => "**Thời hạn khuyến mãi THE ATELIER:**\n\n**Các chương trình định kỳ:**\n| Dịp | Giảm | Thời gian |\n|---|---|---|\n| **8/3** | 30% SP Nữ | 1/3 - 10/3 |\n| **19/11** | 30% SP Nam | 15/11 - 22/11 |\n| **20/11** | 30% SP Nữ | 18/11 - 25/11 |\n| **Black Friday** | 40-50% | Cuối tháng 11 |\n| **Sinh nhật** | 25% | Cả tháng sinh nhật |\n\n**Voucher:**\n- Mỗi voucher có **hạn sử dụng riêng**\n- Xem chi tiết trên trang **Ưu đãi** hoặc trong **Tài khoản → Voucher của tôi**\n\n**Theo dõi sale sớm:**\n- Đăng ký nhận tin ở footer\n- Follow Facebook/Instagram\n\nHỏi mình \"**đang có chương trình gì**\" để xem chi tiết nhé!",
        ],
    ];
}

function chatbotMatchRule(string $message): ?array {
    $rules = chatbotGetRules();
    $msg = mb_strtolower(trim($message));

    foreach ($rules as $key => $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $msg)) {
                return ['response' => $rule['response'], 'rule' => $key];
            }
        }
    }
    return null;
}

/* ============================================================
   EXTRACT PRODUCT KEYWORD
   ============================================================ */
function chatbotExtractProductKeyword(string $message): ?string {
    $msg = mb_strtolower(trim($message));

    if (preg_match('/(đồ|quần\s+áo|thời\s+trang|sản\s+phẩm)?\s*(nam|men|boy)\b/iu', $msg)
        && !preg_match('/(nữ|girl|women)/iu', $msg)) {
        return 'nam';
    }
    if (preg_match('/(váy|đầm|chân\s+váy)/iu', $msg)
        || preg_match('/(đồ|quần\s+áo|thời\s+trang|sản\s+phẩm)?\s*(nữ|nu|girl|women)\b/iu', $msg)) {
        return 'nữ';
    }

    $keywords = [
        'áo thun', 'áo sơ mi', 'áo khoác', 'áo len', 'áo polo',
        'quần jean', 'quần tây', 'quần short', 'quần jogger', 'quần',
        'váy', 'đầm', 'chân váy', 'áo', 'sơ mi',
        'giày', 'dép', 'túi', 'balo', 'mũ', 'nón', 'phụ kiện',
    ];
    foreach ($keywords as $kw) {
        if (mb_strpos($msg, $kw) !== false) return $kw;
    }

    if (preg_match('/(tìm|search|kiếm|gợi ý|gợi í|recommend|có gì)/iu', $msg)) {
        return 'all';
    }
    return null;
}

/* ============================================================
   DETECT STOCK QUERY
   ============================================================ */
function chatbotDetectStockQuery(string $message): ?array {
    $msg = mb_strtolower(trim($message));

    // Phải có từ khóa tồn kho
    if (!preg_match('/(còn|hết|tồn|available)/iu', $msg)) return null;

    // Detect size
    $size = null;
    if (preg_match('/(?:size|cỡ|sz)\s*(2xl|xl|l|m|s)\b/iu', $msg, $m)) {
        $size = strtoupper($m[1]);
    } elseif (preg_match('/\b(2xl|xl|l|m|s)\b/iu', $msg, $m)) {
        $size = strtoupper($m[1]);
    }
    if ($size && !in_array($size, ['S','M','L','XL','2XL'], true)) $size = null;

    // Bỏ stop words
    $cleaned = preg_replace([
        '/\b(còn|hết|tồn|không|ko|k|ạ|vậy|thì|cho|mình|hỏi|shop|ơi|và|có|không\?)\b/iu',
        '/(?:size|cỡ|sz)\s*(2xl|xl|l|m|s)/iu',
        '/\b(2xl|xl|l|m|s)\b/iu',
        '/[?.,!;:]/u',
    ], ' ', $msg);

    $keyword = trim(preg_replace('/\s+/', ' ', $cleaned));
    if (mb_strlen($keyword) < 3) return null;

    return ['keyword' => $keyword, 'size' => $size];
}

/* ============================================================
   DETECT PRODUCT REFERENCE ("sản phẩm thứ X")
   ============================================================ */
function chatbotDetectProductReference(string $message): ?int {
    $msg = mb_strtolower(trim($message));

    $ordinalMap = [
        'nhất' => 0, 'đầu tiên' => 0, 'đầu' => 0, '1' => 0, 'một' => 0,
        'hai' => 1, 'nhì' => 1, '2' => 1,
        'ba' => 2, '3' => 2,
        'tư' => 3, 'bốn' => 3, '4' => 3,
        'năm' => 4, '5' => 4,
    ];

    $patterns = [
        '/(?:sản\s+phẩm|cái|món|item|sp)\s+(?:thứ\s+|số\s+)?([0-9]+|nhất|nhì|ba|tư|bốn|năm|một|hai|đầu\s+tiên)/iu',
        '/^(?:thứ|số)\s+([0-9]+|nhất|nhì|ba|tư|bốn|năm|một|hai)/iu',
        '/tìm\s+hiểu\s+(?:về\s+)?(?:sản\s+phẩm\s+|cái\s+|món\s+)?(?:thứ\s+|số\s+)?([0-9]+|nhất|nhì|ba|tư|bốn|năm|một|hai|đầu\s+tiên)/iu',
        '/xem\s+(?:chi\s+tiết\s+)?(?:sản\s+phẩm\s+|cái\s+|món\s+)?(?:thứ\s+|số\s+)?([0-9]+|nhất|nhì|ba|tư|bốn|năm|một|hai|đầu\s+tiên)/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $msg, $m)) {
            $key = trim(mb_strtolower($m[1]));
            if (isset($ordinalMap[$key])) return $ordinalMap[$key];
        }
    }

    if (preg_match('/(?:sản\s+phẩm|cái|món|sp)\s+(?:này|đó|kia|ấy)/iu', $msg)) {
        return 0;
    }
    if (preg_match('/\bvề\s+(?:sản\s+phẩm\s+)?(?:số\s+|thứ\s+)?([0-9]+)\b/iu', $msg, $m)) {
        $idx = (int)$m[1] - 1;
        return $idx >= 0 ? $idx : null;
    }
    return null;
}

/* ============================================================
   SESSION CONTEXT
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
   PRODUCT QUERIES
   ============================================================ */
function chatbotGetProductById(PDO $pdo, int $productId): ?array {
    if ($productId <= 0) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.price, p.discount_percent, p.image_url,
                   p.description, p.gender, p.sold_count, p.category_id,
                   c.name AS category_name,
                   CASE WHEN p.discount_percent > 0
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100)
                        ELSE p.price END AS final_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = ? LIMIT 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return null;

        $product['sizes']       = chatbotGetProductSizes($pdo, $productId);
        $product['total_stock'] = array_sum(array_column($product['sizes'], 'stock'));
        $product['gender']      = chatbotDetectGenderFromName($product['name'], $product['gender'] ?? 'unisex');
        return $product;
    } catch (Exception $e) {
        error_log('chatbotGetProductById error: ' . $e->getMessage());
        return null;
    }
}

function chatbotGetProductSizes(PDO $pdo, int $productId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT size, stock FROM product_sizes
            WHERE product_id = ?
            ORDER BY FIELD(size, 'S', 'M', 'L', 'XL', '2XL')
        ");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function chatbotGetProductsByFilter(PDO $pdo, array $filters = []): array {
    try {
        $where = ['1=1']; $params = [];

        if (!empty($filters['keyword'])) {
            $kw = '%' . mb_strtolower(trim($filters['keyword'])) . '%';
            $where[] = "(LOWER(p.name) LIKE ? OR LOWER(p.description) LIKE ?)";
            $params[] = $kw; $params[] = $kw;
        }
        if (!empty($filters['gender']) && in_array($filters['gender'], ['male','female'], true)) {
            if ($filters['gender'] === 'male') {
                $where[] = "(p.gender = 'male' OR (p.gender = 'unisex'
                    AND (LOWER(p.name) LIKE '%nam%' OR LOWER(p.name) LIKE '%men%')
                    AND LOWER(p.name) NOT LIKE '%nữ%' AND LOWER(p.name) NOT LIKE '%nu %'))";
            } else {
                $where[] = "(p.gender = 'female' OR (p.gender = 'unisex'
                    AND (LOWER(p.name) LIKE '%nữ%' OR LOWER(p.name) LIKE '%nu %'
                        OR LOWER(p.name) LIKE '%váy%' OR LOWER(p.name) LIKE '%đầm%')))";
            }
        }
        if (!empty($filters['category'])) {
            $where[] = "p.category_id = ?"; $params[] = (int)$filters['category'];
        }
        if (!empty($filters['min_price'])) {
            $where[] = "p.price >= ?"; $params[] = (int)$filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = "p.price <= ?"; $params[] = (int)$filters['max_price'];
        }
        if (!empty($filters['on_sale'])) {
            $where[] = "p.discount_percent > 0";
        }
        if (!empty($filters['in_stock'])) {
            $where[] = "EXISTS (SELECT 1 FROM product_sizes ps WHERE ps.product_id = p.id AND ps.stock > 0)";
        }

        $sortMap = [
            'best_seller' => 'p.sold_count DESC, p.id DESC',
            'price_asc'   => 'p.price ASC',
            'price_desc'  => 'p.price DESC',
            'newest'      => 'p.created_at DESC, p.id DESC',
            'discount'    => 'p.discount_percent DESC, p.sold_count DESC',
            'default'     => 'p.sold_count DESC, p.id DESC',
        ];
        $sort   = $sortMap[$filters['sort'] ?? 'default'] ?? $sortMap['default'];
        $limit  = max(1, min(50, (int)($filters['limit']  ?? 4)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $sql = "
            SELECT p.id, p.name, p.price, p.discount_percent, p.image_url,
                   p.gender, p.sold_count, p.category_id, c.name AS category_name,
                   CASE WHEN p.discount_percent > 0
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100)
                        ELSE p.price END AS final_price,
                   (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id) AS total_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $sort LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['gender'] = chatbotDetectGenderFromName($r['name'], $r['gender'] ?? 'unisex');
        }
        return $rows;
    } catch (Exception $e) {
        error_log('chatbotGetProductsByFilter error: ' . $e->getMessage());
        return [];
    }
}

function chatbotGetDiscountedProducts(PDO $pdo, int $limit = 4): array {
    return chatbotGetProductsByFilter($pdo, ['on_sale' => true, 'sort' => 'discount', 'limit' => $limit]);
}

function chatbotGetNewestProducts(PDO $pdo, int $limit = 4): array {
    return chatbotGetProductsByFilter($pdo, ['sort' => 'newest', 'limit' => $limit]);
}

function chatbotGetProductsByCategory(PDO $pdo, int $categoryId, int $limit = 4): array {
    return chatbotGetProductsByFilter($pdo, ['category' => $categoryId, 'limit' => $limit]);
}

function chatbotGetRelatedProducts(PDO $pdo, int $productId, int $limit = 3): array {
    try {
        $stmt = $pdo->prepare("SELECT category_id, gender FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $base = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$base) return [];

        $where = ['p.id != ?']; $params = [$productId];
        if (!empty($base['category_id'])) {
            $where[] = "p.category_id = ?"; $params[] = $base['category_id'];
        } elseif (!empty($base['gender']) && $base['gender'] !== 'unisex') {
            $where[] = "p.gender = ?"; $params[] = $base['gender'];
        }

        $limit = max(1, min(10, $limit));
        $sql = "SELECT id, name, price, discount_percent, image_url, gender
                FROM products p
                WHERE " . implode(' AND ', $where) . "
                ORDER BY sold_count DESC, id DESC LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['gender'] = chatbotDetectGenderFromName($r['name'], $r['gender'] ?? 'unisex');
        }
        return $rows;
    } catch (Exception $e) { return []; }
}

function chatbotGetCategories(PDO $pdo): array {
    try {
        return $pdo->query("
            SELECT c.id, c.name, c.slug,
                (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count
            FROM categories c ORDER BY c.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function chatbotGetShopStats(PDO $pdo): array {
    try {
        $stats = [];
        $stats['total_products']    = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $stats['total_categories']  = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        $stats['products_in_stock'] = (int)$pdo->query("SELECT COUNT(DISTINCT product_id) FROM product_sizes WHERE stock > 0")->fetchColumn();
        $stats['products_on_sale']  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE discount_percent > 0")->fetchColumn();

        $priceRow = $pdo->query("SELECT MIN(price) as min_p, MAX(price) as max_p FROM products")->fetch(PDO::FETCH_ASSOC);
        $stats['price_min'] = (float)($priceRow['min_p'] ?? 0);
        $stats['price_max'] = (float)($priceRow['max_p'] ?? 0);
        return $stats;
    } catch (Exception $e) { return []; }
}

/* ============================================================
   CHECK STOCK
   ============================================================ */
function chatbotCheckStock(PDO $pdo, string $keyword, ?string $size): ?array {
    try {
        $kw   = mb_strtolower(trim($keyword));
        $like = '%' . $kw . '%';

        $stmt = $pdo->prepare("
            SELECT id, name, price, discount_percent, image_url, gender
            FROM products
            WHERE LOWER(name) LIKE ? OR LOWER(description) LIKE ?
            ORDER BY
                CASE WHEN LOWER(name) = ? THEN 0
                     WHEN LOWER(name) LIKE ? THEN 1
                     ELSE 2 END,
                sold_count DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$like, $like, $kw, $kw . '%']);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return null;

        $sizes = chatbotGetProductSizes($pdo, (int)$product['id']);
        return ['product' => $product, 'sizes' => $sizes];
    } catch (Exception $e) {
        error_log('chatbotCheckStock error: ' . $e->getMessage());
        return null;
    }
}

function chatbotFormatStockResponse(array $data, ?string $askedSize): string {
    $product = $data['product'];
    $sizes   = $data['sizes'];
    $name    = $product['name'];

    $sizeMap = [];
    foreach ($sizes as $s) $sizeMap[strtoupper($s['size'] ?? '')] = (int)($s['stock'] ?? 0);

    if ($askedSize) {
        $stock = $sizeMap[$askedSize] ?? -1;

        if ($stock > 0) {
            return "**{$name}** — Size **{$askedSize}** vẫn **còn hàng**!\n\n"
                 . "Tồn kho: **{$stock} cái**\n\n"
                 . "Nhấn vào sản phẩm bên dưới để đặt ngay nhé!";
        }
        if ($stock === 0) {
            $available = array_keys(array_filter($sizeMap, fn($s) => $s > 0));
            $altText = !empty($available)
                ? "\n\nHiện vẫn còn size: **" . implode(', ', $available) . "**"
                : "\n\nToàn bộ size đã hết hàng.";

            return "**{$name}** — Rất tiếc, size **{$askedSize}** hiện đã **hết hàng**."
                 . $altText . "\n\n"
                 . "Shop dự kiến **nhập thêm hàng trong 3-7 ngày** tới.\n\n"
                 . "Bạn để lại **SĐT** hoặc **liên hệ hotline 1900 123 456** để được thông báo khi có hàng nhé!";
        }

        $available = array_keys(array_filter($sizeMap, fn($s) => $s > 0));
        $sizeList = !empty($available) ? implode(', ', $available) : '(chưa có)';
        return "**{$name}** không có size **{$askedSize}** ạ.\n\n"
             . "Các size hiện có: **{$sizeList}**";
    }

    if (empty($sizes)) {
        return "Sản phẩm **{$name}** chưa có thông tin size.\n\n"
             . "Vui lòng liên hệ hotline **1900 123 456** để được tư vấn ạ!";
    }

    $out = "**{$name}** — Tình trạng tồn kho:\n\n";
    $hasStock = false;
    foreach ($sizes as $s) {
        $sz = strtoupper($s['size'] ?? '?');
        $st = (int)($s['stock'] ?? 0);
        if ($st > 0) {
            $out .= "- Size **{$sz}** — còn **{$st} cái**\n";
            $hasStock = true;
        } else {
            $out .= "- Size **{$sz}** — hết hàng\n";
        }
    }
    $out .= "\n";
    $out .= $hasStock
        ? "Bạn muốn đặt size nào ạ?"
        : "Sản phẩm hiện đã hết hàng. Shop sẽ nhập thêm trong **3-7 ngày** nhé!";
    return $out;
}

/* ============================================================
   SEARCH THEO DỊP / MÙA / PHONG CÁCH
   ============================================================ */
function chatbotSearchByOccasion(PDO $pdo, string $occasion, int $limit = 4): array {
    try {
        $keywords = [];
        switch ($occasion) {
            case 'summer': $keywords = ['thun', 'sơ mi', 'polo', 'cotton', 'thoáng', 'mát', 'short', 'jean']; break;
            case 'winter': $keywords = ['khoác', 'len', 'dày', 'ấm', 'gió', 'windbreaker', 'hoodie']; break;
            case 'sport':  $keywords = ['thể thao', 'gym', 'jogger', 'short', 'thoáng', 'co giãn']; break;
            case 'casual': $keywords = ['thun', 'jean', 'short', 'casual', 'trẻ', 'năng động']; break;
            case 'office': $keywords = ['sơ mi', 'tây', 'form', 'lịch sự', 'thanh lịch', 'slimfit']; break;
            default: return [];
        }

        $whereParts = []; $params = [];
        foreach ($keywords as $kw) {
            $whereParts[] = "LOWER(p.name) LIKE ?";
            $whereParts[] = "LOWER(p.description) LIKE ?";
            $params[] = '%' . mb_strtolower($kw) . '%';
            $params[] = '%' . mb_strtolower($kw) . '%';
        }

        $limit = max(1, min(10, $limit));
        $whereSql = implode(' OR ', $whereParts);

        $sql = "
            SELECT p.id, p.name, p.price, p.discount_percent, p.image_url,
                   p.gender, p.sold_count,
                   CASE WHEN p.discount_percent > 0
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100)
                        ELSE p.price END AS final_price,
                   (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id) AS total_stock
            FROM products p
            WHERE ($whereSql)
            ORDER BY p.sold_count DESC, p.id DESC LIMIT $limit
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['gender'] = chatbotDetectGenderFromName($r['name'], $r['gender'] ?? 'unisex');
        }
        return $rows;
    } catch (Exception $e) {
        error_log('chatbotSearchByOccasion error: ' . $e->getMessage());
        return [];
    }
}

function chatbotGetOccasionMessage(string $occasion): string {
    $map = [
        'summer' => "**Đồ mùa hè tại THE ATELIER** — mát mẻ, thoáng khí:",
        'winter' => "**Đồ mùa đông tại THE ATELIER** — ấm áp, phong cách:",
        'sport'  => "**Đồ thể thao tại THE ATELIER** — thoải mái, năng động:",
        'casual' => "**Đồ đi chơi tại THE ATELIER** — trẻ trung, năng động:",
        'office' => "**Đồ đi làm tại THE ATELIER** — lịch sự, sang trọng:",
    ];
    return $map[$occasion] ?? "Đây là những sản phẩm phù hợp:";
}

/* ============================================================
   FORMAT SẢN PHẨM
   ============================================================ */
function chatbotFormatProductText(array $p): string {
    $name     = $p['name'] ?? 'Sản phẩm';
    $price    = (float)($p['price'] ?? 0);
    $discount = (int)($p['discount_percent'] ?? 0);
    $gender   = chatbotDetectGenderFromName($name, $p['gender'] ?? 'unisex');

    $finalPrice = $discount > 0 ? round($price * (100 - $discount) / 100) : $price;
    $line = "- {$name} [" . chatbotGenderLabel($gender) . "] — " . number_format($finalPrice, 0, ',', '.') . "đ";

    if ($discount > 0) $line .= " (giảm {$discount}%)";
    if (isset($p['total_stock'])) {
        $stock = (int)$p['total_stock'];
        $line .= $stock > 0 ? " · còn {$stock} sp" : " · hết hàng";
    }
    return $line;
}

function chatbotFormatProductsText(array $products): string {
    if (empty($products)) return '(Không có sản phẩm nào)';
    return implode("\n", array_map('chatbotFormatProductText', $products));
}

function chatbotFormatSizesText(array $sizes): string {
    if (empty($sizes)) return '(Chưa có size)';
    $parts = [];
    foreach ($sizes as $s) $parts[] = ($s['size'] ?? '?') . ': ' . (int)($s['stock'] ?? 0);
    return implode(' · ', $parts);
}

function chatbotFormatProductDetail(array $product): string {
    $name        = $product['name'] ?? 'Sản phẩm';
    $price       = (float)($product['price'] ?? 0);
    $discount    = (int)($product['discount_percent'] ?? 0);
    $description = trim($product['description'] ?? '');
    $gender      = chatbotDetectGenderFromName($name, $product['gender'] ?? 'unisex');
    $category    = $product['category_name'] ?? '';

    $finalPrice = $discount > 0 ? round($price * (100 - $discount) / 100) : $price;

    $out = "**{$name}**\n\n";

    if ($discount > 0) {
        $out .= "**Giá:** " . number_format($finalPrice, 0, ',', '.') . "đ";
        $out .= " ~~" . number_format($price, 0, ',', '.') . "đ~~ _(giảm {$discount}%)_\n";
    } else {
        $out .= "**Giá:** " . number_format($finalPrice, 0, ',', '.') . "đ\n";
    }

    $meta = [];
    if ($category) $meta[] = "Danh mục: **{$category}**";
    $meta[] = "Giới tính: **" . chatbotGenderLabel($gender) . "**";
    if (isset($product['sold_count'])) $meta[] = "Đã bán: **" . (int)$product['sold_count'] . "**";
    $out .= implode(' · ', $meta) . "\n";

    if ($description !== '') {
        $short = mb_substr($description, 0, 200);
        if (mb_strlen($description) > 200) $short .= '...';
        $out .= "\n{$short}\n";
    }

    $sizes = $product['sizes'] ?? [];
    if (!empty($sizes)) {
        $out .= "\n**Size & tồn kho:**\n";
        $hasStock = false;
        foreach ($sizes as $s) {
            $sz = $s['size'] ?? '?';
            $st = (int)($s['stock'] ?? 0);
            if ($st > 0) { $out .= "- Size **{$sz}** — còn **{$st} cái**\n"; $hasStock = true; }
            else         { $out .= "- Size **{$sz}** — Hết hàng\n"; }
        }
        $out .= "\n";
        $out .= $hasStock
            ? "Bạn muốn đặt size nào? Nhắn mình để được hỗ trợ nhé!"
            : "Rất tiếc, sản phẩm hiện đang **hết hàng**. Bạn có muốn xem sản phẩm tương tự không?";
    } else {
        $out .= "\nSản phẩm chưa có thông tin size. Vui lòng liên hệ hotline **1900 123 456**.";
    }

    return $out;
}

/* ============================================================
   KHUYẾN MÃI
   ============================================================ */
function chatbotGetActivePromotions(PDO $pdo): array {
    $promotions = [];
    $today = new DateTime();
    $currentYear = (int)date('Y');

    try {
        $events = $pdo->query("SELECT * FROM discount_events WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as $e) {
            $start = new DateTime(sprintf('%d-%02d-%02d', $currentYear, (int)$e['start_month'], (int)$e['start_day']));
            $end   = new DateTime(sprintf('%d-%02d-%02d', $currentYear, (int)$e['end_month'], (int)$e['end_day']));

            if ($end < $today) { $start->modify('+1 year'); $end->modify('+1 year'); }
            $isActive = ($today >= $start && $today <= $end);

            $promotions[] = [
                'type'      => 'event',
                'name'      => $e['name'],
                'discount'  => (int)$e['discount_percent'],
                'gender'    => $e['target_gender'],
                'start'     => $start->format('d/m'),
                'end'       => $end->format('d/m'),
                'is_active' => $isActive,
            ];
        }

        $saleInfo = $pdo->query("
            SELECT COUNT(*) AS total, MAX(discount_percent) AS max_discount
            FROM products WHERE discount_percent > 0
        ")->fetch(PDO::FETCH_ASSOC);

        if ((int)$saleInfo['total'] > 0) {
            $promotions[] = [
                'type'         => 'products',
                'count'        => (int)$saleInfo['total'],
                'max_discount' => (int)$saleInfo['max_discount'],
            ];
        }

        $vStmt = $pdo->prepare("
            SELECT code, discount_percent, max_discount_amount, min_order_amount
            FROM vouchers
            WHERE used = 0 AND user_id IS NULL
              AND valid_from <= CURDATE() AND valid_to >= CURDATE()
            ORDER BY discount_percent DESC LIMIT 5
        ");
        $vStmt->execute();
        $vouchers = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($vouchers)) $promotions[] = ['type' => 'vouchers', 'list' => $vouchers];
    } catch (Exception $e) {
        error_log('chatbotGetActivePromotions error: ' . $e->getMessage());
    }
    return $promotions;
}

function chatbotFormatPromotionResponse(PDO $pdo): string {
    $promotions = chatbotGetActivePromotions($pdo);
    if (empty($promotions)) {
        return "Hiện tại shop chưa có chương trình khuyến mãi nào đang chạy ạ.\n\n"
             . "Bạn **đăng ký nhận tin** ở footer để nhận thông báo sale sớm nhất nhé!";
    }

    $current = []; $upcoming = []; $productSale = null; $vouchers = [];
    foreach ($promotions as $p) {
        if ($p['type'] === 'event') {
            if ($p['is_active']) $current[]  = $p;
            else                 $upcoming[] = $p;
        } elseif ($p['type'] === 'products') $productSale = $p;
        elseif ($p['type'] === 'vouchers')   $vouchers    = $p['list'];
    }

    $out = "**Chương trình khuyến mãi THE ATELIER:**\n\n";

    if (!empty($current)) {
        $out .= "**ĐANG DIỄN RA:**\n";
        foreach ($current as $p) {
            $g = ['male' => 'Nam', 'female' => 'Nữ', 'unisex' => 'tất cả'][$p['gender']] ?? 'tất cả';
            $out .= "- **{$p['name']}** — giảm **{$p['discount']}%** SP **{$g}** (đến hết {$p['end']})\n";
        }
        $out .= "\n";
    }

    if ($productSale) {
        $out .= "**{$productSale['count']} sản phẩm** đang giảm giá";
        if ($productSale['max_discount'] > 0) $out .= " (cao nhất **{$productSale['max_discount']}%**)";
        $out .= "\n\n";
    }

    if (!empty($vouchers)) {
        $out .= "**Mã giảm giá:**\n";
        foreach ($vouchers as $v) {
            $out .= "- **{$v['code']}** — giảm {$v['discount_percent']}%";
            if (!empty($v['max_discount_amount'])) {
                $out .= " (tối đa " . number_format((float)$v['max_discount_amount'], 0, ',', '.') . "đ)";
            }
            if (!empty($v['min_order_amount']) && (float)$v['min_order_amount'] > 0) {
                $out .= " — đơn từ " . number_format((float)$v['min_order_amount'], 0, ',', '.') . "đ";
            }
            $out .= "\n";
        }
        $out .= "\n";
    }

    if (!empty($upcoming)) {
        $out .= "**SẮP TỚI:**\n";
        foreach ($upcoming as $p) {
            $g = ['male' => 'Nam', 'female' => 'Nữ', 'unisex' => 'tất cả'][$p['gender']] ?? 'tất cả';
            $out .= "- **{$p['name']}** ({$p['start']}) — giảm **{$p['discount']}%** SP **{$g}**\n";
        }
        $out .= "\n";
    }

    $out .= "**Mẹo:** Mua **combo** hoặc đơn từ **500.000đ** để được **miễn phí ship** nhé!";
    return $out;
}

/* ============================================================
   FALLBACK — Không hiểu → hotline
   ============================================================ */
function chatbotGetFallbackMessage(): string {
    return "Xin lỗi, mình đang không hiểu câu hỏi. Bạn thử lại hoặc gọi hotline 1900 123 456 nhé!\n\n"
         . "Bạn vui lòng **liên hệ hotline 1900 123 456** (8h - 22h hàng ngày) "
         . "hoặc **email hello@theatelier.vn** để được nhân viên hỗ trợ chi tiết nhé!\n\n"
         . "Mình có thể giúp bạn ngay:\n"
         . "- Tư vấn **size** — cho mình cân nặng + chiều cao\n"
         . "- **Tìm sản phẩm** — nam/nữ, theo mùa, dịp\n"
         . "- **Kiểm tra tồn kho** — VD: \"áo thun nam còn M không\"\n"
         . "- **Khuyến mãi** đang diễn ra\n"
         . "- **Đổi trả**, **hủy đơn**, **địa chỉ**, **giờ mở cửa**";
}

/* ============================================================
   SYSTEM PROMPT BUILDER
   ============================================================ */
function chatbotBuildEnhancedSystemPrompt(PDO $pdo): string {
    $stats       = chatbotGetShopStats($pdo);
    $categories  = chatbotGetCategories($pdo);
    $topProducts = chatbotGetProductsByFilter($pdo, ['sort' => 'best_seller', 'limit' => 5]);
    $onSale      = chatbotGetDiscountedProducts($pdo, 3);
    $newest      = chatbotGetNewestProducts($pdo, 3);

    $catList = '';
    foreach ($categories as $c) $catList .= "- {$c['name']} ({$c['product_count']} sp)\n";

    $priceRange = '';
    if (!empty($stats['price_min'])) {
        $priceRange = "Giá: từ " . number_format($stats['price_min'], 0, ',', '.') . "đ đến "
                    . number_format($stats['price_max'], 0, ',', '.') . "đ";
    }

    return "Bạn là \"Atelier Assistant\" — nhân viên tư vấn của THE ATELIER.

## Phong cách
- Thân thiện, ngắn gọn (tối đa 150 từ)
- Xưng \"mình\" — gọi khách là \"bạn\"
- Dùng **bold** cho từ khóa
- Không biết → đề nghị liên hệ hotline 1900 123 456

## Thông tin shop
- Bán quần áo nam/nữ phong cách lookbook
- Tổng: {$stats['total_products']} sản phẩm, {$stats['products_in_stock']} còn hàng
- Đang giảm giá: {$stats['products_on_sale']} sản phẩm
- $priceRange
- Size: S, M, L, XL, 2XL
- **Miễn phí ship** đơn từ 500.000đ
- **Đổi trả 30 ngày**
- Thanh toán: COD / CK / Thẻ (PIN + OTP)
- Sự kiện: 8/3 (nữ 30%), 19/11 (nam 30%), 20/11 (nữ 30%)

## Danh mục
$catList
## TOP 5 bán chạy
" . chatbotFormatProductsText($topProducts) . "

## Đang giảm giá
" . chatbotFormatProductsText($onSale) . "

## Hàng mới về
" . chatbotFormatProductsText($newest) . "

## KHÔNG
- Không bịa giá, tồn kho
- Không hứa giảm giá ngoài chương trình
- Không tiết lộ thông tin nội bộ";
}

/* ============================================================
   OPENAI
   ============================================================ */
function chatbotCallOpenAI(string $userMessage, array $history, string $systemPrompt, array $options = []): ?string {
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY) || strpos(OPENAI_API_KEY, 'sk-') !== 0) {
        return null;
    }

    $complexity   = $options['complexity'] ?? 'simple';
    $useReasoning = ($complexity === 'complex');

    if ($useReasoning) {
        /* o3-mini — model có reasoning nội bộ */
        $model           = 'o3-mini';
        $reasoningEffort = 'medium';
        $maxTokens       = 2000;
        $timeout         = 90;
        $connectTimeout  = 15;

        $messages = [];
        foreach ($history as $h) {
            if (!empty($h['role']) && !empty($h['content'])) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
        $messages[] = [
            'role'    => 'user',
            'content' => $systemPrompt
                       . "\n\n---\n\n**Câu hỏi của khách:**\n" . $userMessage
                       . "\n\n**Yêu cầu:** Suy nghĩ kỹ trước khi trả lời. Phân tích từng ý nhỏ, "
                       . "đối chiếu thông tin shop, đưa ra câu trả lời mạch lạc, có cấu trúc rõ ràng.",
        ];

        $payload = [
            'model'                 => $model,
            'messages'              => $messages,
            'max_completion_tokens' => $maxTokens,
            'reasoning_effort'      => $reasoningEffort,
        ];
    } else {
        /* gpt-4o-mini — xử lý nhanh */
        $temperature    = ($complexity === 'medium') ? 0.5 : 0.7;
        $maxTokens      = ($complexity === 'medium') ? 600 : 400;
        $timeout        = 30;
        $connectTimeout = 10;

        $sysPrompt = $systemPrompt;
        if ($complexity === 'medium') {
            $sysPrompt .= "\n\n**Lưu ý:** Câu hỏi có thể chứa nhiều ý. Hãy trả lời đầy đủ từng ý, "
                        . "có thể dùng bullet list để rõ ràng. Suy nghĩ từng bước trước khi kết luận.";
        }

        $messages = [['role' => 'system', 'content' => $sysPrompt]];
        foreach ($history as $h) {
            if (!empty($h['role']) && !empty($h['content'])) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model'       => 'gpt-4o-mini',
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[chatbot] curl error: ' . $curlErr);
        return null;
    }
    if ($httpCode !== 200 || !$response) {
        error_log('[chatbot] HTTP ' . $httpCode . ' — ' . substr((string)$response, 0, 300));
        /* Nếu o3-mini lỗi (chưa bật billing / chưa có quyền) → fallback */
        if ($useReasoning) {
            error_log('[chatbot] o3-mini failed → fallback to gpt-4o-mini');
            return chatbotCallOpenAI($userMessage, $history, $systemPrompt, ['complexity' => 'medium']);
        }
        return null;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

/* ============================================================
   SEARCH PRODUCTS
   ============================================================ */
function chatbotSearchProducts(PDO $pdo, string $keyword, int $limit = 4): array {
    $keyword = mb_strtolower(trim($keyword));

    try {
        if ($keyword === 'all' || $keyword === '') {
            $stmt = $pdo->prepare("
                SELECT id, name, price, discount_percent, image_url, gender, sold_count
                FROM products ORDER BY sold_count DESC, id DESC LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($keyword === 'nam' || $keyword === 'men') {
            $stmt = $pdo->prepare("
                SELECT id, name, price, discount_percent, image_url, gender
                FROM products
                WHERE (gender = 'male' OR (gender = 'unisex'
                    AND (LOWER(name) LIKE '%nam%' OR LOWER(name) LIKE '%men%')
                    AND LOWER(name) NOT LIKE '%nữ%' AND LOWER(name) NOT LIKE '%nu %'))
                ORDER BY sold_count DESC, id DESC LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (in_array($keyword, ['nữ', 'nu', 'women'], true)) {
            $stmt = $pdo->prepare("
                SELECT id, name, price, discount_percent, image_url, gender
                FROM products
                WHERE (gender = 'female' OR (gender = 'unisex'
                    AND (LOWER(name) LIKE '%nữ%' OR LOWER(name) LIKE '%nu %'
                        OR LOWER(name) LIKE '%váy%' OR LOWER(name) LIKE '%đầm%')))
                ORDER BY sold_count DESC, id DESC LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $like = '%' . $keyword . '%';
            $stmt = $pdo->prepare("
                SELECT id, name, price, discount_percent, image_url, gender
                FROM products
                WHERE LOWER(name) LIKE ? OR LOWER(description) LIKE ?
                ORDER BY sold_count DESC LIMIT ?
            ");
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($rows as &$r) {
            $r['gender'] = chatbotDetectGenderFromName($r['name'], $r['gender'] ?? 'unisex');
        }
        return $rows;
    } catch (Exception $e) {
        error_log('chatbotSearchProducts error: ' . $e->getMessage());
        return [];
    }
}

/* ============================================================
   MAIN ENTRY — Trả lời tin nhắn
   ============================================================ */
   /* ============================================================
   ĐÁNH GIÁ ĐỘ PHỨC TẠP CÂU HỎI
   ============================================================ */
function chatbotDetectComplexity(string $message): array {
    $message = trim($message);
    $len = mb_strlen($message);
    $questionCount = substr_count($message, '?') + substr_count($message, '？');

    $connectorCount = preg_match_all(
        '/(và|hoặc|nhưng|thì|với|cùng|so\s+sánh|khác\s+nhau|ngoài\s+ra|bên\s+cạnh)/iu',
        $message
    );

    $needsAnalysis = (bool)preg_match(
        '/(so\s+sánh|khác\s+nhau|tại\s+sao|vì\s+sao|nên\s+chọn|cách\s+chọn|phân\s+tích|'
        . 'lợi\s+ích|ưu\s+nhược|phù\s+hợp\s+với|tư\s+vấn\s+chi\s+tiết|'
        . 'kết\s+hợp|gợi\s+ý\s+outfit|set\s+đồ|mix\s+match)/iu',
        $message
    );

    $score = 0;
    if ($len > 80)              $score += 1;
    if ($len > 200)             $score += 2;
    if ($questionCount >= 2)    $score += 1;
    if ($questionCount >= 3)    $score += 1;
    if ($connectorCount >= 2)   $score += 1;
    if ($needsAnalysis)         $score += 2;

    $level = 'simple';
    if ($score >= 5)        $level = 'complex';
    elseif ($score >= 2)    $level = 'medium';

    return [
        'level'          => $level,
        'score'          => $score,
        'length'         => $len,
        'questions'      => $questionCount,
        'connectors'     => $connectorCount,
        'needs_analysis' => $needsAnalysis,
    ];
}
function chatbotReply(PDO $pdo, string $message): array {
    $message = trim($message);
    $result = ['message' => '', 'products' => [], 'source' => 'rule'];

    /* ---- 1. Rule match ---- */
    $rule = chatbotMatchRule($message);
    if ($rule) {
        // Marker: Tính size
        if ($rule['response'] === '__COMPUTE_SIZE__') {
            $measure = chatbotParseMeasurements($message);
            if ($measure) {
                $calc = chatbotComputeSize($measure['height'], $measure['weight']);
                $result['message'] = chatbotFormatSizeResponse($calc);
                $result['source']  = 'rule_size';
                $products = chatbotGetProductsByFilter($pdo, ['limit' => 3, 'sort' => 'best_seller']);
                if (!empty($products)) $result['products'] = $products;
                return $result;
            }
        }

        // Marker: Show SP theo giới tính
        if ($rule['response'] === '__SHOW_MALE_PRODUCTS__') {
            $products = chatbotGetProductsByFilter($pdo, ['gender' => 'male', 'limit' => 4, 'sort' => 'best_seller']);
            $result['products'] = $products;
            $result['message']  = "Đây là những sản phẩm **dành cho Nam** tại THE ATELIER.\n\nBạn xem thử nhé!";
            $result['source']   = 'rule_gender';
            return $result;
        }
        if ($rule['response'] === '__SHOW_FEMALE_PRODUCTS__') {
            $products = chatbotGetProductsByFilter($pdo, ['gender' => 'female', 'limit' => 4, 'sort' => 'best_seller']);
            $result['products'] = $products;
            $result['message']  = "Đây là những sản phẩm **dành cho Nữ** tại THE ATELIER.\n\nBạn xem thử nhé!";
            $result['source']   = 'rule_gender';
            return $result;
        }

        // Marker: Show SP theo mùa/dịp
        $occasionMarkers = [
            '__SHOW_SUMMER_PRODUCTS__' => 'summer',
            '__SHOW_WINTER_PRODUCTS__' => 'winter',
            '__SHOW_SPORT_PRODUCTS__'  => 'sport',
            '__SHOW_CASUAL_PRODUCTS__' => 'casual',
            '__SHOW_OFFICE_PRODUCTS__' => 'office',
        ];
        if (isset($occasionMarkers[$rule['response']])) {
            $occasion = $occasionMarkers[$rule['response']];
            $products = chatbotSearchByOccasion($pdo, $occasion, 4);
            if (!empty($products)) {
                $result['products'] = $products;
                $result['message']  = chatbotGetOccasionMessage($occasion);
            } else {
                $result['message'] = "Mình chưa tìm thấy sản phẩm phù hợp cho danh mục này. Bạn vào mục **Sản phẩm** để xem toàn bộ nhé!";
            }
            $result['source'] = 'rule_occasion';
            return $result;
        }

        // Marker: Show khuyến mãi
        if ($rule['response'] === '__SHOW_PROMOTIONS__') {
            $result['message'] = chatbotFormatPromotionResponse($pdo);
            $result['source']  = 'rule_promotion';
            return $result;
        }

        // Rule thường
        $result['message'] = $rule['response'];
        return $result;
    }

    /* ---- 2. Check tồn kho cụ thể ---- */
    $stockQuery = chatbotDetectStockQuery($message);
    if ($stockQuery !== null) {
        $stockData = chatbotCheckStock($pdo, $stockQuery['keyword'], $stockQuery['size']);
        if ($stockData) {
            $result['message']  = chatbotFormatStockResponse($stockData, $stockQuery['size']);
            $result['products'] = [$stockData['product']];
            $result['source']   = 'stock_check';
            return $result;
        }
        $result['message'] = "Mình không tìm thấy sản phẩm \"**{$stockQuery['keyword']}**\" trong shop.\n\n"
                           . "Bạn kiểm tra lại tên sản phẩm hoặc vào mục **Sản phẩm** để xem danh sách nhé!\n"
                           . "Hoặc gọi hotline **1900 123 456** để được hỗ trợ ạ.";
        $result['source'] = 'stock_not_found';
        return $result;
    }

    /* ---- 3. Product reference (thứ X / cái này) ---- */
    $productIdx = chatbotDetectProductReference($message);
    if ($productIdx !== null) {
        $lastIds = chatbotLoadProductContext();
        if (!empty($lastIds) && isset($lastIds[$productIdx])) {
            $productId = (int)$lastIds[$productIdx];
            $product = chatbotGetProductById($pdo, $productId);
            if ($product) {
                $result['message']  = chatbotFormatProductDetail($product);
                $result['products'] = [$product];
                $result['source']   = 'product_detail';
                return $result;
            }
        }
        $result['message'] = "Mình chưa thấy danh sách sản phẩm nào bạn xem trước đó.\n\n"
                           . "Bạn muốn tìm sản phẩm gì? Ví dụ:\n"
                           . "- \"shop có đồ nam không\"\n"
                           . "- \"tìm áo thun\"\n"
                           . "- \"sản phẩm đang sale\"";
        return $result;
    }

    /* ---- 4. Intent detection nâng cao ---- */
    $msg = mb_strtolower($message);

    // Hỏi shop có gì
    if (preg_match('/(shop|cửa hàng|bên mình)\s+(có|bán)\s+(gì|những gì)/iu', $msg)
        || preg_match('/(danh mục|loại sản phẩm|mặt hàng)/iu', $msg)) {
        $categories = chatbotGetCategories($pdo);
        if (!empty($categories)) {
            $lines = '';
            foreach ($categories as $c) $lines .= "- **{$c['name']}** ({$c['product_count']} sản phẩm)\n";
            $result['message'] = "THE ATELIER hiện có các danh mục sau:\n\n$lines\nBạn muốn xem danh mục nào ạ?";
            return $result;
        }
    }

    // Hàng mới
    if (preg_match('/(hàng|sản phẩm)\s+(mới|mới về|mới nhất)/iu', $msg)) {
        $products = chatbotGetNewestProducts($pdo, 4);
        if (!empty($products)) {
            $result['products'] = $products;
            $result['message']  = "Đây là những sản phẩm **mới nhất** vừa về shop:";
            return $result;
        }
    }

    // Khoảng giá
    if (preg_match('/(dưới|tầm|khoảng|trên)\s*(\d+)\s*(k|nghìn|000|tr|triệu)/iu', $msg, $m)) {
        $num = (int)$m[2]; $unit = mb_strtolower($m[3]); $price = $num;
        if (in_array($unit, ['k', 'nghìn', '000'], true))  $price *= 1000;
        elseif (in_array($unit, ['tr', 'triệu'], true))    $price *= 1000000;

        $filters = ['limit' => 4, 'sort' => 'best_seller'];
        if (mb_strpos($msg, 'dưới') !== false)      $filters['max_price'] = $price;
        elseif (mb_strpos($msg, 'trên') !== false)  $filters['min_price'] = $price;
        else {
            $filters['min_price'] = (int)($price * 0.8);
            $filters['max_price'] = (int)($price * 1.2);
        }

        $products = chatbotGetProductsByFilter($pdo, $filters);
        if (!empty($products)) {
            $result['products'] = $products;
            $result['message']  = "Mình tìm được vài sản phẩm trong khoảng giá bạn hỏi:";
            return $result;
        }
    }

    // Search keyword
    $keyword = chatbotExtractProductKeyword($message);
    if ($keyword !== null) {
        $products = chatbotSearchProducts($pdo, $keyword, 4);
        if (!empty($products)) {
            $result['products'] = $products;
            $result['message']  = "Mình tìm được **" . count($products) . " sản phẩm** phù hợp.\n\nBạn xem thử nhé!";
            $result['source']   = 'rule_search';
            return $result;
        }
    }

    if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY) && strpos(OPENAI_API_KEY, 'sk-') === 0) {
        $history = $_SESSION['chat_history'] ?? [];
        if (count($history) > 20) $history = array_slice($history, -20);

        /* Phân tích độ phức tạp để chọn model + effort */
        $complexity = chatbotDetectComplexity($message);

        $systemPrompt = chatbotBuildEnhancedSystemPrompt($pdo);
        $aiReply = chatbotCallOpenAI($message, $history, $systemPrompt, [
            'complexity' => $complexity['level'],
        ]);

        if ($aiReply !== null && $aiReply !== '') {
            $result['message']    = $aiReply;
            $result['source']     = 'openai_' . $complexity['level'];
            $result['complexity'] = $complexity;

            $_SESSION['chat_history'][] = ['role' => 'user',      'content' => $message];
            $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $aiReply];

            $searchKeyword = chatbotExtractProductKeyword($aiReply);
            if ($searchKeyword !== null) {
                $products = chatbotSearchProducts($pdo, $searchKeyword, 3);
                if (!empty($products)) $result['products'] = $products;
            }

            error_log(sprintf(
                '[chatbot] complexity=%s score=%d len=%d q=%d',
                $complexity['level'], $complexity['score'],
                $complexity['length'], $complexity['questions']
            ));
            return $result;
        }
    }

    /* ---- 6. Fallback cuối — hotline ---- */
    $result['message'] = chatbotGetFallbackMessage();
    $result['source']  = 'fallback';
    return $result;
}