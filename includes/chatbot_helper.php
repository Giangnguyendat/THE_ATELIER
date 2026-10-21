<?php
/**
 * THE ATELIER — Chatbot Helper v3 (Smart Edition)
 * ------------------------------------------------------------------
 * Yêu cầu: PHP >= 7.4, PDO (MySQL).  API công khai KHÔNG đổi:
 *     chatbotReply(PDO $pdo, string $message): array
 *     => ['message','products','source', + 'intent','suggestions']
 *
 * Nâng cấp chính so với v2:
 *  1. Hiểu tiếng Việt KHÔNG DẤU + teencode (ko, dc, sp, bn, ntn...)
 *  2. Bóc tách "thực thể": loại SP, giới tính, màu, khoảng giá, size, sắp xếp
 *  3. Tìm sản phẩm thông minh (kết hợp nhiều tiêu chí, tự nới lỏng khi không có)
 *  4. Knowledge Base (FAQ) dạng dữ liệu — thêm câu hỏi mới chỉ cần thêm 1 mục
 *  5. Nhớ ngữ cảnh: "cái này giá bao nhiêu", "còn size M không", "so sánh 1 và 2"
 *  6. Tư vấn: quà tặng, phối đồ, dáng người, dịp (hè/đông/tiệc/biển/hẹn hò...)
 *  7. Tra cứu đơn hàng (chỉ khi đã đăng nhập)
 *  8. Groq AI có RAG: được cấp dữ liệu SP/chính sách thật → ít bịa hơn
 *  9. Sửa lỗi: rule "size" nuốt câu hỏi tồn kho, "women" bị nhận là nam,
 *     "1m6" parse sai, thiếu session_start, XSS khi echo từ khóa...
 *
 * ⚠️ Các chỗ đánh dấu [CHỈNH] là giả định về DB/chính sách của shop —
 *    hãy đối chiếu với dữ liệu thật của bạn.
 */

if (!defined('GROQ_API_KEY')) {
    $__secrets = __DIR__ . '/secrets.php';
    if (file_exists($__secrets)) require_once $__secrets;
}

/* ---------- Cấu hình shop (sửa 1 chỗ, áp dụng toàn bot) ---------- */
defined('CHATBOT_HOTLINE')     || define('CHATBOT_HOTLINE', '1900 123 456');
defined('CHATBOT_EMAIL')       || define('CHATBOT_EMAIL', 'hello@theatelier.vn');
defined('CHATBOT_FREESHIP')    || define('CHATBOT_FREESHIP', 500000);
defined('CHATBOT_SHIP_FEE')    || define('CHATBOT_SHIP_FEE', 30000);
defined('CHATBOT_RETURN_DAYS') || define('CHATBOT_RETURN_DAYS', 30);
defined('CHATBOT_BASE_URL')    || define('CHATBOT_BASE_URL', '/clothing_shop');
defined('CHATBOT_AI_LIMIT_PER_HOUR') || define('CHATBOT_AI_LIMIT_PER_HOUR', 30);

/* ============================================================
   🧰 HELPERS CHUNG
   ============================================================ */
function chatbotSession(): void {
    if (session_status() === PHP_SESSION_NONE) @session_start();
}

function chatbotGenderLabel(string $gender): string {
    return ['male' => 'Nam', 'female' => 'Nữ', 'unisex' => 'Unisex'][$gender] ?? 'Unisex';
}

function chatbotVnd($n): string {
    return number_format((float)$n, 0, ',', '.') . 'đ';
}

/** Làm sạch text của người dùng trước khi in ngược lại (chống XSS / phá markdown). */
function chatbotSafeText(string $s, int $max = 40): string {
    $s = strip_tags($s);
    $s = str_replace(['*', '_', '`', '|', '<', '>'], '', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
}

/** Chuẩn hóa: thường hóa, bỏ dấu, xử lý teencode, bỏ ký tự lạ. */
function chatbotNormalize(string $text): string {
    static $map = null;
    if ($map === null) {
        $groups = [
            'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ',
            'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
        ];
        $map = [];
        foreach ($groups as $base => $chars) {
            foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) $map[$ch] = $base;
        }
    }
    if (class_exists('Normalizer')) {
        $nf = Normalizer::normalize($text, Normalizer::FORM_C);
        if ($nf !== false) $text = $nf;
    }
    $t = mb_strtolower(trim($text), 'UTF-8');
    $t = strtr($t, $map);
    $t = preg_replace('/[^a-z0-9%#\/\.,\s\-]/u', ' ', $t);
    $slang = [
        '/\b(ko|k0|hok|khum|hem)\b/' => 'khong',
        '/\b(dc|dk)\b/'              => 'duoc',
        '/\bsp\b/'                   => 'san pham',
        '/\bj\b/'                    => 'gi',
        '/\bntn\b/'                  => 'nhu the nao',
        '/\bbn\b/'                   => 'bao nhieu',
        '/\bvs\b/'                   => 'voi',
        '/\b(sdt|dt)\b/'             => 'so dien thoai',
        '/\b(tks|thks|thanks|thank you|thank)\b/' => 'cam on',
        '/\b(okie|okla|okela|oki)\b/' => 'ok',
        '/\b(shop oi|ad oi|admin oi)\b/' => 'shop',
        '/\bmk\b/'                   => 'minh',
    ];
    $t = preg_replace(array_keys($slang), array_values($slang), $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

function chatbotWordCount(string $n): int {
    return $n === '' ? 0 : count(explode(' ', $n));
}

function chatbotSizeOrder(): array { return ['S', 'M', 'L', 'XL', '2XL']; }

function chatbotShiftSize(string $size, int $delta): string {
    $o = chatbotSizeOrder();
    $i = array_search($size, $o, true);
    if ($i === false) return $size;
    return $o[max(0, min(count($o) - 1, $i + $delta))];
}

function chatbotDetectGenderFromName(string $name, string $dbGender = 'unisex'): string {
    if (in_array($dbGender, ['male', 'female'], true)) return $dbGender;
    $n = mb_strtolower($name);
    if (preg_match('/(?<![\p{L}])(nữ|women|woman|girl|lady)(?![\p{L}])|váy|đầm/u', $n)) return 'female';
    if (preg_match('/(?<![\p{L}])(nam|men|man|boy)(?![\p{L}])/u', $n)) return 'male';
    return 'unisex';
}

/** Chuyển sản phẩm sang "thẻ" cho JS */
function chatbotMapProductsToCards(array $products): array {
    $cards = [];
    foreach ($products as $p) {
        $price     = (float)($p['price'] ?? $p['base_price'] ?? 0);
        $discount  = (int)($p['discount_percent'] ?? 0);
        $salePrice = isset($p['sale_price']) ? (float)$p['sale_price'] : null;
        $final     = $salePrice ?: ($discount > 0 ? round($price * (100 - $discount) / 100) : $price);
        $img       = $p['image_url'] ?? $p['thumbnail'] ?? '';
        $cards[] = [
            'id'               => (int)($p['id'] ?? 0),
            'name'             => (string)($p['name'] ?? ''),
            'slug'             => (string)($p['slug'] ?? ''),
            'url'              => CHATBOT_BASE_URL . '/product-details.php?id=' . (int)($p['id'] ?? 0),
            'price'            => (int)$final,
            'old_price'        => ($salePrice || $discount > 0) ? (int)$price : null,
            'discount_percent' => $discount,
            'in_stock'         => isset($p['total_stock']) ? ((int)$p['total_stock'] > 0) : null,
            'image_url'        => $img !== '' ? CHATBOT_BASE_URL . '/uploads/' . $img : '',
        ];
    }
    return $cards;
}

function chatbotFinalPrice(array $p): float {
    $price = (float)($p['price'] ?? 0);
    if (isset($p['sale_price']) && (float)$p['sale_price'] > 0) return (float)$p['sale_price'];
    $d = (int)($p['discount_percent'] ?? 0);
    return $d > 0 ? round($price * (100 - $d) / 100) : $price;
}

/* ============================================================
   📏 SỐ ĐO → SIZE
   ============================================================ */

/** Trích chiều cao/cân nặng. $strict=true: chỉ nhận dạng có đơn vị/nhãn rõ ràng. */
function chatbotExtractHW(string $text, bool $strict = false): array {
    $t = mb_strtolower(trim($text));
    // 1m65 | 1m 65 | 1m6 (=160) | 1,65m | 1.65m
    $t = preg_replace_callback('/(?<![\d.,])1\s*m\s*(\d{1,2})(?!\d)/u', function ($m) {
        return (string)(strlen($m[1]) === 1 ? 100 + (int)$m[1] * 10 : 100 + (int)$m[1]);
    }, $t);
    $t = preg_replace_callback('/(?<![\d.,])1[.,](\d{2})\s*m\b/u', fn($m) => (string)(100 + (int)$m[1]), $t);

    $h = null; $w = null;
    if (preg_match('/(\d{3})\s*cm\b/u', $t, $m)) $h = (float)$m[1];
    if (preg_match('/(\d{2,3}(?:[.,]\d)?)\s*(?:kg|ky|kí|ki)\b/u', $t, $m)) $w = (float)str_replace(',', '.', $m[1]);
    if ($h === null && preg_match('/(?:cao|chiều cao|chieu cao)\s*(\d{3})\b/u', $t, $m)) $h = (float)$m[1];
    if ($w === null && preg_match('/(?:nặng|nang|cân nặng|can nang|cân|can)\s*(\d{2,3})\b/u', $t, $m)) $w = (float)$m[1];
    if ($h !== null && ($h < 130 || $h > 220)) $h = null;
    if ($w !== null && ($w < 30  || $w > 140)) $w = null;

    if (!$strict && ($h === null || $w === null) && preg_match_all('/\d+(?:\.\d+)?/u', $t, $mm)) {
        foreach (array_map('floatval', $mm[0]) as $n) {
            if ($h === null && $n >= 130 && $n <= 220) { $h = $n; continue; }
            if ($w === null && $n >= 30 && $n <= 140)  { $w = $n; }
        }
    }
    return ['h' => $h, 'w' => $w];
}

function chatbotParseMeasurements(string $text): ?array {
    $hw = chatbotExtractHW($text);
    if (!$hw['h'] || !$hw['w']) return null;
    return ['height' => $hw['h'], 'weight' => $hw['w']];
}

function chatbotParseFitPreference(string $n): ?string {
    if (preg_match('/\b(thich rong|mac rong|oversize|rong rai|thoai mai)\b/', $n)) return 'loose';
    if (preg_match('/\b(thich om|om sat|slim|om body|mac om|vua sat)\b/', $n)) return 'tight';
    return null;
}

function chatbotComputeSize(float $heightCm, float $weightKg, ?string $fit = null): array {
    $bmi = $weightKg / (($heightCm / 100) ** 2);
    if ($weightKg < 50)      $size = 'S';
    elseif ($weightKg < 60)  $size = 'M';
    elseif ($weightKg < 70)  $size = 'L';
    elseif ($weightKg < 80)  $size = 'XL';
    else                     $size = '2XL';

    if ($heightCm >= 175 && $size !== '2XL' && $bmi >= 18.5) $size = chatbotShiftSize($size, 1);
    if ($heightCm < 155 && $size !== 'S' && $bmi < 25)       $size = chatbotShiftSize($size, -1);
    if ($fit === 'loose') $size = chatbotShiftSize($size, 1);
    if ($fit === 'tight') $size = chatbotShiftSize($size, -1);

    if ($bmi < 18.5)      $note = 'Dáng người thon gọn — nếu thích mặc ôm vừa có thể chọn size nhỏ hơn.';
    elseif ($bmi < 24.9)  $note = 'Vóc dáng cân đối — size này sẽ vừa vặn.';
    elseif ($bmi < 29.9)  $note = 'Nên chọn size này để mặc thoải mái, dễ vận động.';
    else                  $note = 'Nên chọn size này hoặc nhắn shop tư vấn thêm để vừa vặn nhất.';

    return ['size' => $size, 'bmi' => round($bmi, 1), 'height' => $heightCm, 'weight' => $weightKg, 'note' => $note, 'fit' => $fit];
}

function chatbotFormatSizeResponse(array $calc, bool $askProducts = true): string {
    $size = $calc['size'];
    $tight = chatbotShiftSize($size, -1);
    $loose = chatbotShiftSize($size, 1);
    $alt = '';
    if ($tight !== $size && $loose !== $size) $alt = "🔀 Thích **ôm sát** → **{$tight}** · Thích **thoải mái** → **{$loose}**\n\n";
    elseif ($tight !== $size) $alt = "🔀 Thích ôm sát hơn → **{$tight}**\n\n";
    elseif ($loose !== $size) $alt = "🔀 Thích rộng hơn → **{$loose}**\n\n";

    $fitTxt = $calc['fit'] === 'loose' ? ' _(đã tính theo ý bạn: mặc rộng)_' : ($calc['fit'] === 'tight' ? ' _(đã tính theo ý bạn: mặc ôm)_' : '');
    return "📏 **Kết quả tư vấn size:**\n\n"
         . "📊 Cao **{$calc['height']}cm** · Nặng **{$calc['weight']}kg** · BMI **{$calc['bmi']}**\n\n"
         . "✅ **Size phù hợp: {$size}**{$fitTxt}\n\n"
         . "💡 {$calc['note']}\n\n"
         . $alt
         . ($askProducts ? "Bạn muốn mình gợi ý **sản phẩm size {$size}** không ạ?" : "Mình gợi ý vài mẫu **size {$size}** đang còn hàng 👇");
}

/* ============================================================
   🔍 PHÂN TÍCH THỰC THỂ (ENTITY EXTRACTION)
   ============================================================ */

/** [regex không dấu, regex có dấu|null, từ khóa DB (chuỗi hoặc mảng)] */
function chatbotTypeDictionary(): array {
    return [
        ['ao thun|ao phong|t ?shirt|tee', null, 'thun'],
        ['so mi', null, 'sơ mi'],
        ['ao khoac|ao gio|jacket|bomber|blazer', 'khoác', 'khoác'],
        ['hoodie|ao ni', null, 'hoodie'],
        ['ao len|cardigan|sweater', null, 'len'],
        ['polo', null, 'polo'],
        ['quan jean|jeans?|quan bo', null, 'jean'],
        ['quan tay|quan au', null, 'tây'],
        ['quan short|quan dui|shorts?', null, 'short'],
        ['jogger|quan the thao|quan ni', null, 'jogger'],
        ['kaki|khaki', null, 'kaki'],
        ['chan vay', null, 'chân váy'],
        ['vay (?:xoe|cong so|ngan|dai|lien|maxi|hoa)', 'váy', 'váy'],
        ['dam (?:du|cong so|xoe|body|maxi|hoa|di tiec)|dress', 'đầm', 'đầm'],
        ['giay|sneakers?', null, 'giày'],
        ['doi dep|dep (?:nam|nu|sandal|quai)|sandal', 'dép', 'dép'],
        ['tui (?:xach|deo|tote)|tote', 'túi', 'túi'],
        ['balo|ba lo', null, 'balo'],
        [null, 'mũ|nón', ['mũ', 'nón']],
        ['that lung|day nit|belt', 'thắt lưng', 'thắt lưng'],
        ['phu kien', null, 'phụ kiện'],
    ];
}

function chatbotColorDictionary(): array {
    // [từ không dấu, từ có dấu, an toàn khi đứng một mình?]
    return [
        ['xanh', 'xanh', true], ['den', 'đen', true], ['trang', 'trắng', true], ['xam', 'xám', true],
        ['do', 'đỏ', true], ['vang', 'vàng', true], ['hong', 'hồng', true], ['nau', 'nâu', true],
        ['tim', 'tím', true], ['cam', 'cam', false], ['be', 'be', false], ['kem', 'kem', false],
        ['ghi', 'ghi', false], ['navy', 'navy', true],
    ];
}

function chatbotParsePrice(string $n): array {
    $out = ['min' => null, 'max' => null];
    $amt = '(\d+(?:[.,]\d+)*)\s*(k|nghin|ngan|tr|trieu|cu|d|vnd|dong)?';

    $money = function (string $num, ?string $unit): ?int {
        $unit = $unit ?? '';
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $num)) $v = (float)preg_replace('/[.,]/', '', $num);
        else {
            $v = (float)str_replace(',', '.', $num);
            if (in_array($unit, ['k', 'nghin', 'ngan'], true)) $v *= 1000;
            elseif (in_array($unit, ['tr', 'trieu', 'cu'], true)) $v *= 1000000;
            elseif ($v < 1000) $v *= 1000;            // "dưới 300" → 300k
        }
        $v = (int)round($v);
        return ($v >= 10000 && $v <= 20000000) ? $v : null;
    };

    // 1tr2 → 1.200.000
    $n = preg_replace_callback('/(\d+)\s*(?:tr|trieu|cu)\s*(\d)\b/', fn($m) => ($m[1] * 1000000 + $m[2] * 100000) . 'd', $n);

    if (preg_match('/\btu\s*' . $amt . '\s*(?:den|toi|-)\s*' . $amt . '/', $n, $m)) {
        $u1 = $m[2] !== '' ? $m[2] : $m[4];
        $a = $money($m[1], $u1); $b = $money($m[3], $m[4] ?: null);
        if ($a && $b) { $out['min'] = min($a, $b); $out['max'] = max($a, $b); return $out; }
    }
    if (preg_match('/\b(\d+(?:[.,]\d+)*)\s*-\s*' . $amt . '/', $n, $m) && ($m[3] ?? '') !== '') {
        $a = $money($m[1], $m[3]); $b = $money($m[2], $m[3]);
        if ($a && $b) { $out['min'] = min($a, $b); $out['max'] = max($a, $b); return $out; }
    }
    if (preg_match('/\b(?:duoi|toi da|khong qua|re hon|nho hon|ngan sach|budget|<)\s*' . $amt . '/', $n, $m)) {
        if ($v = $money($m[1], $m[2] ?? null)) { $out['max'] = $v; return $out; }
    }
    if (preg_match('/\b(?:tren|toi thieu|lon hon|hon|>)\s*' . $amt . '/', $n, $m)) {
        if ($v = $money($m[1], $m[2] ?? null)) { $out['min'] = $v; return $out; }
    }
    if (preg_match('/\b(?:tam|khoang|loanh quanh|trong tam|gia)\s*' . $amt . '/', $n, $m)) {
        if ($v = $money($m[1], $m[2] ?? null)) { $out['min'] = (int)($v * 0.8); $out['max'] = (int)($v * 1.2); return $out; }
    }
    if (preg_match('/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(k|nghin|ngan|tr|trieu|cu|d)\b/', $n, $m)) {
        if ($v = $money($m[1], $m[2])) { $out['min'] = (int)($v * 0.75); $out['max'] = (int)($v * 1.25); }
    }
    return $out;
}

function chatbotExtractEntities(string $raw, string $n): array {
    $r = mb_strtolower($raw, 'UTF-8');
    $e = ['gender' => null, 'types' => [], 'color' => null, 'min_price' => null, 'max_price' => null,
          'size' => null, 'sort' => null, 'on_sale' => false];

    // --- Giới tính
    $male   = (bool)preg_match('/(?<![\p{L}])(nam|men|boy|đàn ông|con trai)(?![\p{L}])/u', $r);
    $female = (bool)preg_match('/(?<![\p{L}])(nữ|nu|women|woman|girl|phụ nữ|con gái|bạn gái)(?![\p{L}])|váy|đầm/u', $r);
    if ($male && !$female) $e['gender'] = 'male';
    elseif ($female && !$male) $e['gender'] = 'female';

    // --- Loại sản phẩm
    $found = [];
    foreach (chatbotTypeDictionary() as [$normRe, $rawRe, $kw]) {
        $hit = ($normRe && preg_match('/\b(?:' . $normRe . ')\b/u', $n))
            || ($rawRe && preg_match('/(?<![\p{L}])(?:' . $rawRe . ')(?![\p{L}])/u', $r));
        if ($hit) foreach ((array)$kw as $k) $found[$k] = true;
    }
    if (!$found) {
        if (preg_match('/(?<![\p{L}])quần(?![\p{L}])/u', $r)) $found['quần'] = true;
        if (preg_match('/(?<![\p{L}])áo(?![\p{L}])/u', $r) || preg_match('/\bao\b/', $n)) $found['áo'] = true;
    }
    $e['types'] = array_keys($found);

    // --- Màu sắc
    foreach (chatbotColorDictionary() as [$nw, $aw, $safe]) {
        $hit = preg_match('/\bmau\s+' . $nw . '\b/', $n)
            || ($safe ? preg_match('/(?<![\p{L}])' . $aw . '(?![\p{L}])/u', $r) : preg_match('/màu\s+' . $aw . '(?![\p{L}])/u', $r));
        if ($hit) { $e['color'] = $aw; break; }
    }
    if (!$e['color']) {
        $en = ['black' => 'đen', 'white' => 'trắng', 'grey' => 'xám', 'gray' => 'xám', 'red' => 'đỏ', 'blue' => 'xanh',
               'green' => 'xanh', 'pink' => 'hồng', 'beige' => 'be', 'brown' => 'nâu'];
        foreach ($en as $k => $v) if (preg_match('/\b' . $k . '\b/', $n)) { $e['color'] = $v; break; }
    }

    // --- Size
    if (preg_match('/(?<![\p{L}0-9])(?:size|sz|cỡ)\s*(2xl|xxl|xl|l|m|s)(?![\p{L}0-9])/u', $r, $m)
        || preg_match('/(?<![a-z0-9])(2xl|xxl|xl)(?![a-z0-9])/', $n, $m)) {
        $s = strtoupper($m[1]);
        $e['size'] = $s === 'XXL' ? '2XL' : $s;
    }

    // --- Giá
    $p = chatbotParsePrice($n);
    $e['min_price'] = $p['min']; $e['max_price'] = $p['max'];

    // --- Sắp xếp / khuyến mãi
    if (preg_match('/\b(re nhat|gia thap|gia re|tiet kiem nhat|re re)\b/', $n)) $e['sort'] = 'price_asc';
    elseif (preg_match('/\b(dat nhat|cao cap|gia cao|sang nhat|xin nhat)\b/', $n)) $e['sort'] = 'price_desc';
    elseif (preg_match('/\b(moi nhat|hang moi|moi ve|new arrival)\b/', $n)) $e['sort'] = 'newest';
    elseif (preg_match('/\b(ban chay|hot nhat|hot trend|pho bien|nhieu nguoi mua|best ?seller)\b/', $n)) $e['sort'] = 'best_seller';
    if (preg_match('/\b(giam gia|dang sale|hang sale|do sale|sale off|dang giam)\b/', $n) && !preg_match('/giam can/', $n)) {
        $e['on_sale'] = true;
        if (!$e['sort']) $e['sort'] = 'discount';
    }
    return $e;
}

function chatbotHasProductEntities(array $e): bool {
    return !empty($e['types']) || !empty($e['color']) || !empty($e['min_price']) || !empty($e['max_price'])
        || !empty($e['size']) || !empty($e['sort']) || !empty($e['on_sale']);
}

function chatbotHasSearchVerb(string $n): bool {
    return (bool)preg_match('/\b(tim|kiem|goi y|tu van|cho xem|xem|mua|muon mua|can mua|shop co|co ban|recommend|suggest|show|loai nao|mau nao)\b/', $n);
}

function chatbotEntitiesToFilter(array $e): array {
    $f = [];
    if (!empty($e['types']))     $f['keywords']  = $e['types'];
    if (!empty($e['gender']))    $f['gender']    = $e['gender'];
    if (!empty($e['color']))     $f['color']     = $e['color'];
    if (!empty($e['min_price'])) $f['min_price'] = $e['min_price'];
    if (!empty($e['max_price'])) $f['max_price'] = $e['max_price'];
    if (!empty($e['size']))      $f['size']      = $e['size'];
    if (!empty($e['sort']))      $f['sort']      = $e['sort'];
    if (!empty($e['on_sale']))   $f['on_sale']   = true;
    return $f;
}

function chatbotDescribeCriteria(array $e): string {
    $p = [];
    if (!empty($e['types']))  $p[] = implode('/', $e['types']);
    if (!empty($e['gender'])) $p[] = chatbotGenderLabel($e['gender']);
    if (!empty($e['color']))  $p[] = 'màu ' . $e['color'];
    if (!empty($e['min_price']) && !empty($e['max_price'])) $p[] = chatbotVnd($e['min_price']) . ' – ' . chatbotVnd($e['max_price']);
    elseif (!empty($e['max_price'])) $p[] = 'dưới ' . chatbotVnd($e['max_price']);
    elseif (!empty($e['min_price'])) $p[] = 'trên ' . chatbotVnd($e['min_price']);
    if (!empty($e['size']))   $p[] = 'size ' . $e['size'];
    if (!empty($e['on_sale'])) $p[] = 'đang giảm giá';
    $labels = ['price_asc' => 'giá rẻ nhất', 'price_desc' => 'giá cao nhất', 'newest' => 'mới nhất', 'best_seller' => 'bán chạy'];
    if (!empty($e['sort']) && isset($labels[$e['sort']])) $p[] = $labels[$e['sort']];
    return implode(' · ', $p);
}

/* ============================================================
   💾 SESSION CONTEXT
   ============================================================ */
function chatbotSaveProductContext(array $products): void {
    chatbotSession();
    $ids = [];
    foreach ($products as $p) if (!empty($p['id'])) $ids[] = (int)$p['id'];
    if ($ids) {
        $_SESSION['chat_last_products']    = $ids;
        $_SESSION['chat_last_products_at'] = time();
        if (count($ids) === 1) chatbotSetFocus($ids[0]); else unset($_SESSION['chat_focus']);
    }
}

function chatbotLoadProductContext(): array {
    chatbotSession();
    $ids = $_SESSION['chat_last_products'] ?? [];
    $at  = $_SESSION['chat_last_products_at'] ?? 0;
    if (time() - $at > 600) {
        unset($_SESSION['chat_last_products'], $_SESSION['chat_last_products_at']);
        return [];
    }
    return $ids;
}

function chatbotSetFocus(int $id): void { chatbotSession(); $_SESSION['chat_focus'] = ['id' => $id, 'at' => time()]; }

function chatbotGetFocusId(): ?int {
    chatbotSession();
    $f = $_SESSION['chat_focus'] ?? null;
    if ($f && time() - ($f['at'] ?? 0) < 900) return (int)$f['id'];
    unset($_SESSION['chat_focus']);
    return null;
}

function chatbotSetPending(string $type, array $data = []): void {
    chatbotSession();
    $_SESSION['chat_pending'] = ['type' => $type, 'data' => $data, 'at' => time()];
}
function chatbotGetPending(): ?array {
    chatbotSession();
    $p = $_SESSION['chat_pending'] ?? null;
    if ($p && time() - ($p['at'] ?? 0) < 300) return $p;
    unset($_SESSION['chat_pending']);
    return null;
}
function chatbotClearPending(): void { chatbotSession(); unset($_SESSION['chat_pending']); }

function chatbotRemember(string $user, string $bot): void {
    chatbotSession();
    $h = $_SESSION['chat_history'] ?? [];
    $h[] = ['role' => 'user', 'content' => mb_substr($user, 0, 300)];
    $h[] = ['role' => 'assistant', 'content' => mb_substr(strip_tags($bot), 0, 400)];
    $_SESSION['chat_history'] = array_slice($h, -16);
}

/* ============================================================
   📦 TRUY VẤN SẢN PHẨM
   ============================================================ */
function chatbotGetProductSizes(PDO $pdo, int $productId): array {
    try {
        $stmt = $pdo->prepare("SELECT size, stock FROM product_variants WHERE product_id = ? ORDER BY FIELD(size, 'S', 'M', 'L', 'XL', '2XL')");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function chatbotGetProductById(PDO $pdo, int $productId): ?array {
    if ($productId <= 0) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.base_price AS price, p.sale_price, p.thumbnail AS image_url, p.description,
                   c.name AS category_name,
                   CASE WHEN p.sale_price IS NOT NULL THEN ROUND((p.base_price - p.sale_price) / p.base_price * 100) ELSE 0 END AS discount_percent
            FROM products p LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return null;
        $product['sizes']       = chatbotGetProductSizes($pdo, $productId);
        $product['total_stock'] = array_sum(array_column($product['sizes'], 'stock'));
        $product['gender']      = chatbotDetectGenderFromName($product['name'], 'unisex');
        return $product;
    } catch (Exception $e) { return null; }
}

/**
 * Bộ lọc sản phẩm. Khóa hỗ trợ:
 *  keywords[] (khớp tên/danh mục, OR) · any_keywords[] (tên/mô tả/danh mục) · keyword (cũ)
 *  gender · color · size · min_price · max_price (theo GIÁ SAU GIẢM) · on_sale · in_stock
 *  exclude_ids[] · sort(best_seller|price_asc|price_desc|newest|discount) · limit
 */
function chatbotGetProductsByFilter(PDO $pdo, array $f = []): array {
    try {
        $where = ['1=1']; $params = [];

        $groups = [];
        if (!empty($f['keywords']))     $groups[] = [(array)$f['keywords'], false];
        if (!empty($f['any_keywords'])) $groups[] = [(array)$f['any_keywords'], true];
        if (!empty($f['keyword']))      $groups[] = [[$f['keyword']], true];
        foreach ($groups as [$list, $withDesc]) {
            $or = [];
            foreach ($list as $kw) {
                $like = '%' . mb_strtolower(trim($kw)) . '%';
                if ($withDesc) { $or[] = '(LOWER(p.name) LIKE ? OR LOWER(p.description) LIKE ? OR LOWER(c.name) LIKE ?)'; array_push($params, $like, $like, $like); }
                else           { $or[] = '(LOWER(p.name) LIKE ? OR LOWER(c.name) LIKE ?)'; array_push($params, $like, $like); }
            }
            if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
        }

        if (!empty($f['color'])) {
            $like = '%' . mb_strtolower($f['color']) . '%';
            $where[] = '(LOWER(p.name) LIKE ? OR LOWER(p.description) LIKE ?)';
            array_push($params, $like, $like);
        }
        if (!empty($f['gender']) && in_array($f['gender'], ['male', 'female'], true)) {
            if ($f['gender'] === 'male') {
                $where[] = "((LOWER(p.name) LIKE '%nam%' OR LOWER(p.name) LIKE '% men%' OR LOWER(p.name) LIKE '%unisex%') AND LOWER(p.name) NOT LIKE '%nữ%' AND LOWER(p.name) NOT LIKE '%women%')";
            } else {
                $where[] = "(LOWER(p.name) LIKE '%nữ%' OR LOWER(p.name) LIKE '%váy%' OR LOWER(p.name) LIKE '%đầm%' OR LOWER(p.name) LIKE '%women%' OR LOWER(p.name) LIKE '%unisex%')";
            }
        }
        $eff = 'COALESCE(p.sale_price, p.base_price)';
        if (!empty($f['min_price'])) { $where[] = "$eff >= ?"; $params[] = (int)$f['min_price']; }
        if (!empty($f['max_price'])) { $where[] = "$eff <= ?"; $params[] = (int)$f['max_price']; }
        if (!empty($f['on_sale']))   $where[] = 'p.sale_price IS NOT NULL';
        if (!empty($f['size']) && in_array($f['size'], chatbotSizeOrder(), true)) {
            $where[] = 'EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.size = ? AND pv.stock > 0)';
            $params[] = $f['size'];
        } elseif (!empty($f['in_stock'])) {
            $where[] = 'EXISTS (SELECT 1 FROM product_variants ps WHERE ps.product_id = p.id AND ps.stock > 0)';
        }
        if (!empty($f['exclude_ids'])) {
            $ids = array_map('intval', (array)$f['exclude_ids']);
            $where[] = 'p.id NOT IN (' . implode(',', $ids) . ')';
        }

        $sortKey = $f['sort'] ?? 'default';
        $sortMap = [
            'best_seller' => 'p.id DESC',
            'price_asc'   => "$eff ASC",
            'price_desc'  => "$eff DESC",
            'newest'      => 'p.id DESC',
            'discount'    => '(CASE WHEN p.sale_price IS NOT NULL THEN (p.base_price - p.sale_price) / p.base_price ELSE 0 END) DESC, p.id DESC',
            'default'     => 'p.id DESC',
        ];
        $limit = max(1, min(50, (int)($f['limit'] ?? 4)));

        $build = function (string $order) use ($where, $limit): string {
            return "SELECT p.id, p.name, p.base_price AS price, p.sale_price, p.thumbnail AS image_url,
                    CASE WHEN p.sale_price IS NOT NULL THEN ROUND((p.base_price - p.sale_price) / p.base_price * 100) ELSE 0 END AS discount_percent,
                    (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = p.id) AS total_stock
                FROM products p LEFT JOIN categories c ON p.category_id = c.id
                WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit";
        };

        $rows = null;
        if ($sortKey === 'best_seller') {   // [CHỈNH] giả định bảng order_items(product_id, quantity)
            try {
                $stmt = $pdo->prepare($build('(SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.product_id = p.id) DESC, p.id DESC'));
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $rows = null; }
        }
        if ($rows === null) {
            $stmt = $pdo->prepare($build($sortMap[$sortKey] ?? $sortMap['default']));
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as &$r) $r['gender'] = chatbotDetectGenderFromName($r['name'], 'unisex');
        unset($r);
        return $rows;
    } catch (Exception $e) { return []; }
}

/** Tìm thông minh: thử đủ tiêu chí → nới lỏng dần (màu → giá → size → sale) */
function chatbotSmartSearch(PDO $pdo, array $e, int $limit = 4, array $extra = []): array {
    $steps = [
        [['color'], 'màu sắc'],
        [['min_price', 'max_price'], 'khoảng giá'],
        [['size'], 'size'],
        [['on_sale'], 'khuyến mãi'],
    ];
    $cur = $e; $dropped = [];
    for ($i = 0; $i <= count($steps); $i++) {
        if ($i > 0) {
            [$fields, $label] = $steps[$i - 1];
            $has = false;
            foreach ($fields as $fl) if (!empty($cur[$fl])) $has = true;
            if (!$has) continue;
            foreach ($fields as $fl) $cur[$fl] = ($fl === 'on_sale') ? false : null;
            $dropped[] = $label;
        }
        $rows = chatbotGetProductsByFilter($pdo, chatbotEntitiesToFilter($cur) + $extra + ['limit' => $limit, 'in_stock' => true]);
        if ($rows) return ['products' => $rows, 'dropped' => $dropped, 'criteria' => $cur];
    }
    $rows = chatbotGetProductsByFilter($pdo, chatbotEntitiesToFilter($cur) + $extra + ['limit' => $limit]);
    return ['products' => $rows, 'dropped' => $dropped, 'criteria' => $cur];
}

function chatbotGetDiscountedProducts(PDO $pdo, int $limit = 4): array { return chatbotGetProductsByFilter($pdo, ['on_sale' => true, 'sort' => 'discount', 'limit' => $limit]); }
function chatbotGetNewestProducts(PDO $pdo, int $limit = 4): array { return chatbotGetProductsByFilter($pdo, ['sort' => 'newest', 'limit' => $limit]); }

function chatbotGetCategories(PDO $pdo): array {
    try {
        return $pdo->query("SELECT c.id, c.name, c.slug, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count FROM categories c ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function chatbotGetShopStats(PDO $pdo): array {
    try {
        $s = [];
        $s['total_products']    = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $s['products_in_stock'] = (int)$pdo->query("SELECT COUNT(DISTINCT product_id) FROM product_variants WHERE stock > 0")->fetchColumn();
        $s['products_on_sale']  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sale_price IS NOT NULL")->fetchColumn();
        $row = $pdo->query("SELECT MIN(base_price) AS min_p, MAX(base_price) AS max_p FROM products")->fetch(PDO::FETCH_ASSOC);
        $s['price_min'] = (float)($row['min_p'] ?? 0);
        $s['price_max'] = (float)($row['max_p'] ?? 0);
        return $s;
    } catch (Exception $e) { return ['total_products' => 0, 'products_in_stock' => 0, 'products_on_sale' => 0, 'price_min' => 0, 'price_max' => 0]; }
}

/* ---------- Tồn kho ---------- */
function chatbotCheckStock(PDO $pdo, string $keyword, ?string $size): ?array {
    try {
        $like = '%' . mb_strtolower(trim($keyword)) . '%';
        $stmt = $pdo->prepare("SELECT id, name, base_price AS price, sale_price, thumbnail AS image_url FROM products WHERE LOWER(name) LIKE ? OR LOWER(description) LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$like, $like]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return null;
        return ['product' => $product, 'sizes' => chatbotGetProductSizes($pdo, (int)$product['id'])];
    } catch (Exception $e) { return null; }
}

function chatbotFormatStockResponse(array $data, ?string $askedSize): string {
    $product = $data['product']; $sizes = $data['sizes']; $name = $product['name'];
    $sizeMap = [];
    foreach ($sizes as $s) $sizeMap[strtoupper($s['size'] ?? '')] = (int)($s['stock'] ?? 0);

    if ($askedSize) {
        $stock = $sizeMap[$askedSize] ?? -1;
        if ($stock > 0) return "✅ **{$name}** — Size **{$askedSize}** vẫn **còn hàng** ({$stock} cái)!\n\n👉 Nhấn vào sản phẩm bên dưới để đặt ngay nhé!";
        if ($stock === 0) {
            $avail = array_keys(array_filter($sizeMap, fn($s) => $s > 0));
            return "❌ **{$name}** — Size **{$askedSize}** hiện đã **hết hàng**.\n" . ($avail ? "💡 Hiện còn size: **" . implode(', ', $avail) . "**" : "😔 Toàn bộ size đã hết.");
        }
        $avail = array_keys(array_filter($sizeMap, fn($s) => $s > 0));
        return "⚠️ **{$name}** không có size **{$askedSize}** ạ." . ($avail ? "\n💡 Các size đang có: **" . implode(', ', $avail) . "**" : '');
    }
    if (!$sizes) return "⚠️ Sản phẩm **{$name}** chưa có thông tin size.";
    $out = "📦 **{$name}** — Tình trạng:\n";
    foreach ($sizes as $s) {
        $out .= ($s['stock'] > 0) ? "✅ Size **{$s['size']}** — còn **{$s['stock']} cái**\n" : "❌ Size **{$s['size']}** — hết hàng\n";
    }
    return $out;
}

function chatbotIsStockQuery(string $n): bool {
    if (preg_match('/\b(con trai|con gai|cho con|con nit|em be)\b/', $n)) return false;
    return (bool)preg_match('/\b(con|het)\b.*\b(khong|hang|size|chua|nua|k)\b|\b(het hang|ton kho|co san|available|in stock|con hang)\b/', $n);
}

function chatbotStandaloneSize(string $n): ?string {
    if (preg_match('/(?<![a-z0-9])(2xl|xxl|xl|l|m|s)(?![a-z0-9])/', $n, $m)) {
        $s = strtoupper($m[1]);
        return $s === 'XXL' ? '2XL' : $s;
    }
    return null;
}

/** Giữ lại để tương thích code cũ */
function chatbotDetectStockQuery(string $message): ?array {
    $msg = mb_strtolower(trim($message));
    if (!preg_match('/(còn|hết|tồn|available)/iu', $msg)) return null;
    $size = chatbotStandaloneSize(chatbotNormalize($message));
    $cleaned = preg_replace(['/\b(còn|hết|tồn|không|ko|k|ạ|vậy|thì|cho|mình|hỏi|shop|ơi|và|có|không\?)\b/iu', '/(?:size|cỡ|sz)\s*(2xl|xl|l|m|s)/iu', '/\b(2xl|xl|l|m|s)\b/iu', '/[?.,!;:]/u'], ' ', $msg);
    $keyword = trim(preg_replace('/\s+/', ' ', $cleaned));
    if (mb_strlen($keyword) < 3) return null;
    return ['keyword' => $keyword, 'size' => $size];
}

/* ---------- Chi tiết / so sánh sản phẩm ---------- */
function chatbotCleanDescription(?string $html, int $max = 350): string {
    $t = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t;
}

function chatbotFormatProductDetail(array $product): string {
    $name = $product['name'] ?? 'Sản phẩm';
    $final = chatbotFinalPrice($product);
    $discount = (int)($product['discount_percent'] ?? 0);
    $out = "📦 **{$name}**\n\n💰 **Giá:** " . chatbotVnd($final);
    if ($discount > 0) $out .= " _(giảm {$discount}%, giá gốc " . chatbotVnd($product['price']) . ")_";
    $out .= "\n";

    $desc = chatbotCleanDescription($product['description'] ?? '', 260);
    if ($desc !== '') $out .= "\n📝 {$desc}\n";

    $sizes = $product['sizes'] ?? [];
    if ($sizes) {
        $out .= "\n📏 **Size & tồn kho:**\n";
        foreach ($sizes as $s) {
            $out .= ($s['stock'] > 0) ? "✅ Size **{$s['size']}** — {$s['stock']} cái\n" : "❌ Size **{$s['size']}** — Hết hàng\n";
        }
        $out .= "\n👉 Bạn muốn đặt size nào?";
    } else {
        $out .= "\n⚠️ Sản phẩm chưa có thông tin size.";
    }
    return $out;
}

function chatbotFormatProductAspect(array $p, string $aspect, ?string $size = null): string {
    $name = $p['name'];
    switch ($aspect) {
        case 'price':
            $d = (int)($p['discount_percent'] ?? 0);
            $o = "💰 **{$name}** hiện có giá **" . chatbotVnd(chatbotFinalPrice($p)) . "**";
            if ($d > 0) $o .= " (giảm **{$d}%**, giá gốc " . chatbotVnd($p['price']) . ")";
            $o .= ((float)chatbotFinalPrice($p) >= CHATBOT_FREESHIP) ? "\n🚚 Đơn này được **miễn phí ship**!" : "\n🚚 Đơn từ " . chatbotVnd(CHATBOT_FREESHIP) . " được miễn phí ship.";
            return $o;
        case 'sizes':
            return chatbotFormatStockResponse(['product' => $p, 'sizes' => $p['sizes'] ?? []], $size);
        case 'material':
            $d = chatbotCleanDescription($p['description'] ?? '', 400);
            return $d !== ''
                ? "🧵 **{$name}**\n\n{$d}\n\n💡 Cần biết thêm về form/độ dày, bạn cứ hỏi mình nhé!"
                : "🧵 Mô tả chất liệu của **{$name}** chưa được cập nhật. Bạn gọi **" . CHATBOT_HOTLINE . "** để shop tư vấn kỹ hơn nhé!";
        case 'color':
            $d = mb_strtolower(chatbotCleanDescription($p['description'] ?? '', 800));
            $hits = [];
            foreach (chatbotColorDictionary() as [, $aw, ]) if (mb_strpos($d . ' ' . mb_strtolower($name), $aw) !== false) $hits[] = $aw;
            return $hits
                ? "🎨 **{$name}** có các màu: **" . implode(', ', array_unique($hits)) . "**.\n👉 Xem ảnh & chọn màu ở trang chi tiết nhé!"
                : "🎨 Bạn xem các tùy chọn màu & hình thực tế ở trang chi tiết của **{$name}** nhé. Cần mình tìm mẫu tương tự màu khác không?";
        case 'buy':
            return "🛒 Bạn bấm vào sản phẩm bên dưới → chọn **size** → **Thêm vào giỏ** là xong nhé!\n🚚 Đơn từ " . chatbotVnd(CHATBOT_FREESHIP) . " được miễn phí ship.";
        default:
            return chatbotFormatProductDetail($p);
    }
}

function chatbotFormatComparison(array $products): string {
    $cell = fn($s) => str_replace('|', '/', (string)$s);
    $head = '| |'; $sep = '|---|'; $rows = ['Giá' => '|**Giá**|', 'Giảm giá' => '|**Giảm giá**|', 'Size còn hàng' => '|**Size còn hàng**|'];
    foreach ($products as $i => $p) {
        $head .= ' ' . ($i + 1) . '. ' . $cell($p['name']) . ' |'; $sep .= '---|';
        $avail = [];
        foreach ($p['sizes'] ?? [] as $s) if ((int)$s['stock'] > 0) $avail[] = $s['size'];
        $d = (int)($p['discount_percent'] ?? 0);
        $rows['Giá']           .= chatbotVnd(chatbotFinalPrice($p)) . '|';
        $rows['Giảm giá']      .= ($d > 0 ? "-{$d}%" : '—') . '|';
        $rows['Size còn hàng'] .= ($avail ? implode(', ', $avail) : 'Hết hàng') . '|';
    }
    return "⚖️ **So sánh nhanh:**\n\n{$head}\n{$sep}\n" . implode("\n", $rows) . "\n\nBạn nghiêng về mẫu nào? Cho mình **chiều cao + cân nặng** để mình chọn size luôn nhé!";
}

function chatbotLoadContextProducts(PDO $pdo, int $max = 4): array {
    $out = [];
    foreach (array_slice(chatbotLoadProductContext(), 0, $max) as $id) {
        $p = chatbotGetProductById($pdo, (int)$id);
        if ($p) $out[] = $p;
    }
    return $out;
}

/* ---------- Tham chiếu "sản phẩm số 2", "cái này" ---------- */
function chatbotDetectProductReference(string $message): ?int {
    $n = chatbotNormalize($message);
    $ord = ['nhat' => 0, 'dau tien' => 0, 'dau' => 0, '1' => 0, 'mot' => 0, 'hai' => 1, 'nhi' => 1, '2' => 1, 'ba' => 2, '3' => 2, 'tu' => 3, 'bon' => 3, '4' => 3, 'nam' => 4, '5' => 4];
    $w = '([0-9]+|nhat|nhi|ba|tu|bon|nam|mot|hai|dau tien)';
    $patterns = [
        '/\b(?:san pham|cai|mon|mau|item|sp)\s+(?:thu\s+|so\s+)?' . $w . '\b/',
        '/^(?:thu|so)\s+' . $w . '\b/',
        '/\btim hieu\s+(?:ve\s+)?(?:san pham\s+|cai\s+|mon\s+)?(?:thu\s+|so\s+)?' . $w . '\b/',
        '/\bxem\s+(?:chi tiet\s+)?(?:san pham\s+|cai\s+|mon\s+)?(?:thu\s+|so\s+)?' . $w . '\b/',
        '/\bve\s+(?:san pham\s+)?(?:so\s+|thu\s+)?([0-9]+)\b/',
    ];
    foreach ($patterns as $pt) {
        if (preg_match($pt, $n, $m) && isset($ord[trim($m[1])])) return $ord[trim($m[1])];
    }
    return null;
}

/* ============================================================
   🎉 KHUYẾN MÃI
   ============================================================ */
function chatbotGetActivePromotions(PDO $pdo): array {
    $promotions = [];
    $today = new DateTime(); $year = (int)date('Y');
    try {
        $events = $pdo->query("SELECT * FROM discount_events WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as $e) {
            $start = new DateTime(sprintf('%d-%02d-%02d', $year, (int)$e['start_month'], (int)$e['start_day']));
            $end   = new DateTime(sprintf('%d-%02d-%02d', $year, (int)$e['end_month'], (int)$e['end_day']));
            if ($end < $start) $end->modify('+1 year');                       // sự kiện vắt qua năm mới
            if ($today < $start) { $ps = (clone $start)->modify('-1 year'); $pe = (clone $end)->modify('-1 year'); if ($today >= $ps && $today <= $pe) { $start = $ps; $end = $pe; } }
            if ($end < $today) { $start->modify('+1 year'); $end->modify('+1 year'); }
            $promotions[] = [
                'type' => 'event', 'name' => $e['name'], 'discount' => (int)$e['discount_percent'],
                'gender' => $e['target_gender'] ?? 'all', 'start' => $start->format('d/m'), 'end' => $end->format('d/m'),
                'is_active' => ($today >= $start && $today <= $end), 'days_until' => max(0, (int)$today->diff($start)->format('%r%a')),
            ];
        }
        $sale = $pdo->query("SELECT COUNT(*) AS total, MAX((base_price - sale_price)/base_price*100) AS max_discount FROM products WHERE sale_price IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
        if ((int)$sale['total'] > 0) $promotions[] = ['type' => 'products', 'count' => (int)$sale['total'], 'max_discount' => (int)$sale['max_discount']];

        $vStmt = $pdo->query("SELECT code, value AS discount_percent, max_discount AS max_discount_amount, min_order AS min_order_amount FROM vouchers WHERE is_active = 1 AND starts_at <= NOW() AND ends_at >= NOW() LIMIT 5");
        if ($vStmt) {
            $v = $vStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($v) $promotions[] = ['type' => 'vouchers', 'list' => $v];
        }
    } catch (Exception $e) {}
    return $promotions;
}

function chatbotFormatPromotionResponse(PDO $pdo): string {
    $promotions = chatbotGetActivePromotions($pdo);
    $out = "🎉 **Khuyến mãi THE ATELIER:**\n\n";
    $any = false; $upcoming = '';
    foreach ($promotions as $p) {
        if ($p['type'] === 'event' && $p['is_active']) { $out .= "🔥 **{$p['name']}** — giảm **{$p['discount']}%** (đến {$p['end']})\n"; $any = true; }
        elseif ($p['type'] === 'event' && $p['days_until'] <= 60) { $upcoming .= "- **{$p['name']}** — giảm {$p['discount']}% ({$p['start']} → {$p['end']})\n"; }
        elseif ($p['type'] === 'products') { $out .= "🏷️ **{$p['count']} sản phẩm** đang giảm giá (cao nhất **{$p['max_discount']}%**)\n"; $any = true; }
        elseif ($p['type'] === 'vouchers') {
            $out .= "\n🎁 **Mã giảm giá đang chạy:**\n";
            foreach ($p['list'] as $v) {
                $line = "- Mã **{$v['code']}** — giảm {$v['discount_percent']}%";
                if (!empty($v['min_order_amount'])) $line .= ", đơn từ " . chatbotVnd($v['min_order_amount']);
                $out .= $line . "\n";
            }
            $any = true;
        }
    }
    if ($upcoming !== '') { $out .= "\n⏰ **Sắp diễn ra:**\n{$upcoming}"; $any = true; }
    if (!$any) $out .= "Hiện shop chưa có chương trình nào đang chạy ạ 😊\n";
    $out .= "\n📅 **Ưu đãi hằng năm:** 8/3 giảm 30% SP Nữ · 19/11 giảm 30% SP Nam · Voucher sinh nhật giảm 25% 🎂";
    return $out;
}

/* ============================================================
   🌦️ DỊP / MÙA (dữ liệu hóa)
   ============================================================ */
function chatbotOccasions(): array {
    return [
        'summer' => ['re' => 'mua he|do he|di he|trong he|nang nong|troi nong|oi buc|mat me|thoang mat',
                     'kw' => ['thun', 'sơ mi', 'polo', 'cotton', 'thoáng', 'short', 'linen'],
                     'msg' => "☀️ **Đồ mùa hè** — mát mẻ, thoáng khí 👇", 'tip' => 'Ưu tiên chất liệu cotton/linen, màu sáng để đỡ nóng.'],
        'winter' => ['re' => 'mua dong|do dong|troi lanh|ret|gio lanh|ao am|giu am',
                     'kw' => ['khoác', 'len', 'dày', 'ấm', 'gió', 'hoodie'],
                     'msg' => "❄️ **Đồ mùa đông** — ấm áp, phong cách 👇", 'tip' => 'Mặc nhiều lớp (áo thun + len/hoodie + khoác) vừa ấm vừa dễ cởi bớt.'],
        'sport'  => ['re' => 'the thao|gym|yoga|chay bo|tap luyen|da bong|tap the duc',
                     'kw' => ['thể thao', 'gym', 'jogger', 'short', 'thoáng'],
                     'msg' => "🏃 **Đồ thể thao** — thoải mái, năng động 👇", 'tip' => 'Chọn đồ thoáng, co giãn, thấm hút tốt.'],
        'casual' => ['re' => 'di choi|dao pho|di cafe|ca phe|cuoi tuan|casual|nang dong|di cho',
                     'kw' => ['thun', 'jean', 'short', 'casual', 'năng động'],
                     'msg' => "👕 **Đồ đi chơi** — trẻ trung 👇", 'tip' => 'Áo thun + quần jean/short là combo dễ mặc, khó sai.'],
        'office' => ['re' => 'di lam|cong so|van phong|phong van|office|lich su|thanh lich',
                     'kw' => ['sơ mi', 'tây', 'lịch sự', 'thanh lịch'],
                     'msg' => "👔 **Đồ đi làm** — lịch sự, chỉn chu 👇", 'tip' => 'Sơ mi + quần tây/kaki tông trung tính là công thức an toàn.'],
        'party'  => ['re' => 'di tiec|du tiec|party|dam cuoi|tiec cuoi|du le|tot nghiep|su kien|gala|sang trong',
                     'kw' => ['sơ mi', 'tây', 'váy', 'đầm', 'khoác'],
                     'msg' => "🥂 **Đồ đi tiệc** — sang trọng, nổi bật 👇", 'tip' => 'Chọn form đứng dáng, màu trầm hoặc một điểm nhấn nổi bật.'],
        'date'   => ['re' => 'hen ho|di date|buoi hen|di choi voi nguoi yeu|ra mat',
                     'kw' => ['sơ mi', 'polo', 'váy', 'đầm', 'jean'],
                     'msg' => "💘 **Đồ đi hẹn hò** — gọn gàng, có gu 👇", 'tip' => 'Sạch sẽ, vừa vặn, màu sáng/trung tính luôn ghi điểm.'],
        'beach'  => ['re' => 'di bien|du lich|nghi mat|nghi duong|picnic|da ngoai|di xa',
                     'kw' => ['short', 'thun', 'linen', 'váy', 'đầm', 'mũ', 'nón'],
                     'msg' => "🏖️ **Đồ đi biển/du lịch** — nhẹ, thoáng, dễ phối 👇", 'tip' => 'Mang đồ dễ phối với nhau, ưu tiên chất liệu nhanh khô.'],
        'school' => ['re' => 'di hoc|sinh vien|hoc sinh|len truong|di thi',
                     'kw' => ['thun', 'polo', 'jean', 'hoodie', 'balo'],
                     'msg' => "🎒 **Đồ đi học** — năng động, thoải mái 👇", 'tip' => 'Ưu tiên đồ dễ giặt, bền, mặc cả ngày không bí.'],
        'tet'    => ['re' => 'tet|nam moi|le hoi|di chua|di le|xuan',
                     'kw' => ['sơ mi', 'váy', 'đầm', 'len', 'khoác'],
                     'msg' => "🧧 **Đồ Tết** — tươi tắn, may mắn 👇", 'tip' => 'Tông đỏ, vàng, be hoặc màu sáng sẽ rất hợp không khí Tết.'],
        'rainy'  => ['re' => 'mua mua|troi mua|ao mua|di mua',
                     'kw' => ['khoác', 'gió', 'hoodie'],
                     'msg' => "🌧️ **Đồ ngày mưa** — gọn, nhanh khô 👇", 'tip' => 'Ưu tiên áo khoác nhẹ chống gió, quần nhanh khô.'],
        'home'   => ['re' => 'o nha|do ngu|mac nha|do bo|pijama|pyjama',
                     'kw' => ['thun', 'short', 'jogger'],
                     'msg' => "🏠 **Đồ mặc nhà** — mềm, thoải mái 👇", 'tip' => 'Chọn cotton mềm, form rộng vừa phải cho dễ chịu.'],
    ];
}

function chatbotDetectOccasion(string $n): ?string {
    foreach (chatbotOccasions() as $k => $o) if (preg_match('/\b(?:' . $o['re'] . ')\b/', $n)) return $k;
    return null;
}

function chatbotSearchByOccasion(PDO $pdo, string $occasion, int $limit = 4, array $extra = []): array {
    $occ = chatbotOccasions()[$occasion] ?? null;
    if (!$occ) return [];
    $rows = chatbotGetProductsByFilter($pdo, ['any_keywords' => $occ['kw'], 'limit' => $limit, 'in_stock' => true] + $extra);
    return $rows ?: chatbotGetProductsByFilter($pdo, ['any_keywords' => $occ['kw'], 'limit' => $limit] + $extra);
}

function chatbotGetOccasionMessage(string $occasion): string {
    return chatbotOccasions()[$occasion]['msg'] ?? "Gợi ý cho bạn 👇";
}

/* ============================================================
   📚 KNOWLEDGE BASE (FAQ) — thêm câu hỏi mới chỉ cần thêm 1 mục
   ------------------------------------------------------------
   'kw'     : cụm từ KHÔNG DẤU. Tiền tố "!" = từ khóa mạnh (điểm cao).
   'answer' : câu trả lời (markdown nhẹ)  |  'handler' : xử lý động
   'yield'  : true → nhường cho tìm sản phẩm nếu câu có loại SP/màu/giá...
   'cards'  : true → kèm thẻ SP theo thực thể  |  mảng filter → kèm thẻ SP cố định
   ============================================================ */
function chatbotGetKnowledge(): array {
    $hot = CHATBOT_HOTLINE; $mail = CHATBOT_EMAIL;
    $free = chatbotVnd(CHATBOT_FREESHIP); $fee = chatbotVnd(CHATBOT_SHIP_FEE); $days = CHATBOT_RETURN_DAYS;

    return [
        'complaint' => ['kw' => ['!hang loi', '!giao sai', '!bi rach', '!bung chi', '!thieu hang', '!giao cham', '!khong hai long', '!that vong', '!khieu nai', '!sai mau', '!sai size', '!nhan nham', 'dut chi', 'bi hu', 'te qua', 'giao thieu'],
            'answer' => "😔 Mình rất tiếc về trải nghiệm này và xin lỗi bạn ạ!\n\n✅ Nếu lỗi từ shop (lỗi hàng, giao sai/thiếu) → **đổi mới hoặc hoàn 100%** trong **{$days} ngày**, sản phẩm còn nguyên tag.\n\n📸 Bạn gửi giúp **mã đơn + hình ảnh sản phẩm** qua:\n- Hotline **{$hot}** (8h - 22h)\n- Email **{$mail}**\n\nShop sẽ xử lý ngay cho bạn ạ 🙏"],
        'human_agent' => ['kw' => ['!nhan vien', '!tu van vien', '!nguoi that', '!gap nhan vien', '!tong dai', '!quan ly', 'noi chuyen voi nguoi', 'ho tro truc tiep'],
            'answer' => "👩‍💼 Bạn muốn trao đổi trực tiếp với nhân viên?\n\n📞 Hotline: **{$hot}** (8h - 22h)\n📧 Email: **{$mail}**\n📍 Hoặc ghé showroom (hỏi mình \"địa chỉ shop\").\n\nTrong lúc chờ, mình vẫn hỗ trợ size, sản phẩm, tồn kho được nhé!"],
        'return' => ['kw' => ['!hoan tra', '!hoan hang', '!hoan tien', '!doi tra', '!doi hang', '!doi size', '!doi mau', '!doi san pham', '!tra hang', '!tra lai', '!chinh sach doi', 'bao hanh', 'doi co'],
            'answer' => "↩️ **Chính sách đổi trả — THE ATELIER:**\n\n✅ **Điều kiện:** Trong **{$days} ngày**, còn nguyên tag, chưa qua sử dụng.\n- Lỗi từ shop → đổi mới / hoàn **100%**\n- Không vừa size → đổi size **miễn phí lần đầu**\n\nBạn muốn đổi/trả sản phẩm nào ạ?"],
        'refund_time' => ['kw' => ['!bao lau hoan tien', '!khi nao hoan tien', '!hoan tien bao lau', '!bao lau duoc hoan', 'nhan lai tien'],
            'answer' => "💸 Thời gian hoàn tiền phụ thuộc **phương thức thanh toán** của đơn hàng. Bạn gọi **{$hot}** hoặc email **{$mail}** kèm **mã đơn** để shop kiểm tra chính xác nhé!"],
        'cancel_order' => ['kw' => ['!huy don', '!huy order', '!huy dat hang', 'cancel don', 'cancel order', 'khong muon mua nua', 'muon huy'],
            'answer' => "❌ **Hủy đơn hàng:**\n\n✅ Có thể hủy MIỄN PHÍ khi đơn ở trạng thái **Chờ xử lý**.\n❌ Không thể hủy khi đơn đã **Đang giao** hoặc **Đã giao**.\n\n📌 Vào **Tài khoản** → **Đơn hàng của tôi** để hủy nhé!"],
        'change_info' => ['kw' => ['!doi dia chi', '!sua dia chi', '!thay doi dia chi', '!nhap sai dia chi', '!nhap nham dia chi', 'doi so dien thoai', 'sua thong tin', 'doi nguoi nhan'],
            'answer' => "✏️ Muốn đổi **địa chỉ / SĐT nhận hàng**?\n\n- Đơn đang **Chờ xử lý**: bạn gọi ngay hotline **{$hot}** để shop chỉnh giúp (hoặc hủy & đặt lại).\n- Đơn **Đang giao**: liên hệ hotline sớm để shop phối hợp bên vận chuyển.\n\nBạn nhớ cung cấp **mã đơn** nhé!"],
        'order_tracking' => ['kw' => ['!kiem tra don', '!tra cuu don', '!theo doi don', '!don hang cua toi', '!don cua toi', '!tinh trang don', '!trang thai don', '!ma van don', '!van don', 'don da dat', 'xem don', 'toi dat hang roi'], 'handler' => '__ORDER__'],
        'ship' => ['kw' => ['!phi ship', '!phi van chuyen', '!phi giao', '!freeship', '!van chuyen', '!giao hang', 'free ship', 'mien phi ship', 'ship bao nhieu', 'bao lau nhan', 'bao lau giao', 'khi nao nhan', 'nhan hang', 'phi giao hang'],
            'answer' => "🚚 **Chính sách vận chuyển:**\n\n- **Miễn phí ship** đơn từ **{$free}**\n- Đơn dưới mức này: phí **{$fee}** toàn quốc\n- **Thời gian giao:** Nội thành 1-2 ngày, Tỉnh 2-4 ngày."],
        'international' => ['kw' => ['!ship nuoc ngoai', '!giao quoc te', '!ship quoc te', '!nuoc ngoai', 'ship my', 'ship han'],
            'answer' => "🌏 Hiện shop giao hàng **toàn quốc**. Với đơn **quốc tế**, bạn vui lòng liên hệ **{$hot}** hoặc **{$mail}** để được báo phương án nhé!"],
        'payment' => ['kw' => ['!thanh toan', '!phuong thuc', '!cod', '!chuyen khoan', 'the tin dung', 'the ghi no', 'tra tien', 'otp', 'pin'],
            'answer' => "💳 **Phương thức thanh toán:**\n\n1️⃣ **COD** (xác nhận PIN 6 số)\n2️⃣ **Chuyển khoản** (PIN + OTP)\n3️⃣ **Thẻ tín dụng** (PIN + OTP)\n\n🔒 Bảo mật 2 lớp xác minh qua **PIN** + **OTP email**."],
        'installment' => ['kw' => ['!tra gop', '!tra sau', '!tra dan', 'tra truoc mot phan'],
            'answer' => "🏦 Hiện shop hỗ trợ **COD, chuyển khoản, thẻ tín dụng**. Về **trả góp**, bạn liên hệ **{$hot}** để được tư vấn thêm nhé!"],
        'how_to_order' => ['kw' => ['!cach dat hang', '!dat hang nhu the nao', '!cach mua', '!mua hang nhu the nao', '!huong dan dat', '!dat hang online', 'mua online', 'lam sao dat', 'lam sao mua', 'dat nhu the nao'],
            'answer' => "🛍️ **Đặt hàng chỉ 4 bước:**\n\n1️⃣ Chọn sản phẩm & **size**\n2️⃣ Nhấn **Thêm vào giỏ**\n3️⃣ Vào **Giỏ hàng** → nhập địa chỉ, áp **mã giảm giá** (nếu có)\n4️⃣ Chọn **thanh toán** & xác nhận bằng **PIN (+ OTP email)**\n\nBạn đang phân vân chọn mẫu hay size? Mình hỗ trợ ngay!"],
        'voucher_how' => ['kw' => ['!ma giam gia o dau', '!nhap ma', '!cach dung ma', '!ap dung ma', '!ap ma', '!ma het han', '!khong ap duoc ma', 'dung voucher', 'su dung voucher', 'nhap voucher', 'ma khong dung duoc'],
            'answer' => "🎟️ **Cách dùng mã giảm giá:**\n\n- Nhập mã ở bước **Giỏ hàng / Thanh toán**\n- Mỗi mã có **điều kiện** (đơn tối thiểu, hạn dùng, mức giảm tối đa)\n- Mã báo lỗi? Kiểm tra lại **hạn dùng & giá trị đơn tối thiểu**\n\nHỏi mình \"**đang có chương trình gì**\" để xem mã đang chạy nhé!"],
        'birthday' => ['kw' => ['!voucher sinh nhat', '!uu dai sinh nhat', '!ma sinh nhat', '!giam sinh nhat'],
            'answer' => "🎂 **Voucher sinh nhật** giảm **25%** dành cho khách hàng THE ATELIER! Bạn kiểm tra trong **Tài khoản** / email, hoặc gọi **{$hot}** để được hướng dẫn nhận nhé!"],
        'promotion' => ['kw' => ['!khuyen mai', '!giam gia', '!voucher', '!ma giam', '!uu dai', '!sale', '!coupon', 'dang sale', 'dang giam', 'sap sale', 'khi nao sale', 'co sale khong', 'chuong trinh'],
            'handler' => '__PROMOTIONS__', 'yield' => true],
        'price_match' => ['kw' => ['!bot gia', '!tra gia', '!thuong luong', '!giam them', '!mac qua', 'dat qua'],
            'answer' => "💬 Giá tại THE ATELIER là **giá niêm yết**, mình chưa thể giảm thêm ngoài chương trình ạ. Bạn có thể:\n- Săn **khuyến mãi / voucher** đang chạy\n- Mua đơn từ **{$free}** để **miễn phí ship**\n\nHỏi mình \"**đang có chương trình gì**\" nhé!"],
        'price_advice' => ['kw' => ['!tiet kiem', '!nen mua gi', '!mua nhieu', 'combo', 'mua set', 'set do'],
            'answer' => "💰 **Mẹo mua tiết kiệm:**\n- Đơn từ **{$free}** → **Freeship**\n- Săn voucher dịp sale (8/3, 19/11, sinh nhật)\n- Ưu tiên các mẫu đang **giảm giá** (xem bên dưới 👇)", 'cards' => ['on_sale' => true, 'sort' => 'discount'], 'yield' => true],
        'size_guide' => ['kw' => ['!bang size', '!huong dan chon size', '!chon size', 'size', 'kich thuoc', 'kich co', 'so do', 'co ao'],
            'answer' => "📏 **Hướng dẫn chọn size THE ATELIER:**\n\n| Cân nặng | Chiều cao | Size |\n|---|---|---|\n| 45-52kg | 1m50-1m58 | **S** |\n| 53-60kg | 1m55-1m65 | **M** |\n| 61-68kg | 1m60-1m72 | **L** |\n| 69-78kg | 1m65-1m78 | **XL** |\n| 79-90kg | 1m70-1m85 | **2XL** |\n\nBạn nhắn **cân nặng + chiều cao** (VD: \"1m65 55kg\"), thêm \"thích mặc **rộng**/**ôm**\" nếu có, mình tư vấn chính xác nhé!", 'yield' => true],
        'fit' => ['kw' => ['!form', '!co rong khong', '!co chat khong', '!co om khong', '!oversize', '!slim fit', '!regular fit', '!co bi nho', '!co bi to', 'rong hay chat', 'mac co vua', 'co vua khong'],
            'answer' => "👕 Form dáng có thể khác nhau tùy mẫu. Cách chắc chắn nhất là bạn nhắn **chiều cao + cân nặng** (VD: \"1m70 65kg\"), nói thêm bạn thích mặc **ôm** hay **rộng**, mình sẽ chọn size sát nhất. Nếu mặc không vừa, shop **đổi size miễn phí lần đầu** trong {$days} ngày ạ!", 'cards' => true],
        'care' => ['kw' => ['!giat', '!bao quan', '!ra mau', '!phai mau', '!xu long', '!bi xu', '!co rut', '!bi co', 'ui', 'phoi', 'say', 'giat may', 'giat tay', 'nhan giat', 'huong dan giat'],
            'answer' => "🧺 **Mẹo giặt & bảo quản quần áo:**\n\n- Lộn trái áo, giặt **nước lạnh/ấm nhẹ**, vòng giặt nhẹ\n- Giặt riêng đồ **tối màu** với đồ sáng (nhất là 2-3 lần đầu)\n- Tránh **chất tẩy mạnh**, hạn chế vắt/sấy nhiệt cao\n- Phơi **nơi thoáng**, tránh nắng gắt trực tiếp\n- Ủi **nhiệt độ vừa** theo hướng dẫn trên **nhãn mác**\n\n💡 Mỗi sản phẩm có nhãn hướng dẫn riêng — bạn làm theo nhãn là chuẩn nhất ạ!", 'cards' => true],
        'fabric' => ['kw' => ['!chat lieu', '!vai gi', '!cotton', '!co gian', '!thoang khong', '!mat khong', '!nong khong', '!day khong', '!mong khong', '!polyester', '!linen', '!denim', 'vai'],
            'answer' => "🧵 Chất liệu mỗi sản phẩm được ghi ở phần **mô tả** trong trang chi tiết. Bạn cho mình biết **tên/loại sản phẩm** (hoặc nói \"**sản phẩm số 1**\") để mình xem chất liệu cụ thể nhé!", 'cards' => true],
        'color_options' => ['kw' => ['!co mau khac', '!mau nao', '!co nhung mau', '!bang mau', '!con mau', 'mau sac'],
            'answer' => "🎨 Màu sắc & ảnh thực tế được hiển thị ở **trang chi tiết** từng sản phẩm. Bạn cho mình biết **loại sản phẩm + màu mong muốn** (VD: \"áo thun nam màu đen\") để mình tìm giúp nhé!", 'cards' => true],
        'invoice' => ['kw' => ['!hoa don', '!xuat hoa don', 'vat', 'hoa don do'],
            'answer' => "🧾 Để **xuất hóa đơn VAT**, bạn vui lòng liên hệ hotline **{$hot}** hoặc email **{$mail}** kèm **mã đơn + thông tin công ty** nhé!"],
        'account' => ['kw' => ['!dang ky', '!dang nhap', '!quen mat khau', '!doi mat khau', '!quen pin', '!doi pin', '!tao tai khoan', 'tai khoan', 'dang xuat'],
            'answer' => "👤 Bạn quản lý tài khoản & đơn hàng ở mục **Tài khoản** trên website. Nếu **quên mật khẩu/PIN** hoặc không đăng nhập được, bạn liên hệ **{$hot}** / **{$mail}** để được hỗ trợ khôi phục nhé!"],
        'authentic' => ['kw' => ['!hang that', '!chinh hang', '!hang gia', '!hang chat luong', '!nguon goc', 'co uy tin', 'lua dao'],
            'answer' => "🛡️ THE ATELIER có **showroom thật** và chính sách **đổi trả {$days} ngày**: sản phẩm lỗi/không đúng mô tả được **đổi mới hoặc hoàn 100%**. Bạn có thể ghé showroom xem hàng trực tiếp (hỏi mình \"địa chỉ shop\") 💛"],
        'gift_wrap' => ['kw' => ['!goi qua', '!hop qua', '!tui qua', '!thiep chuc', 'goi qua tang', 'loi chuc'],
            'answer' => "🎀 Về **gói quà / thiệp chúc**, bạn vui lòng ghi chú khi đặt hàng hoặc gọi **{$hot}** để shop xác nhận dịch vụ hiện có nhé!"],
        'restock' => ['kw' => ['!khi nao co hang', '!bao gio ve hang', '!ve hang', '!restock', '!nhap hang', 'hang ve'],
            'answer' => "📦 Với mẫu đang **hết hàng**, bạn gọi **{$hot}** để shop báo lịch về hàng (nếu có) nhé. Mình cũng có thể gợi ý **mẫu tương tự còn hàng** — bạn cho mình tên/loại sản phẩm nhé!"],
        'preorder' => ['kw' => ['!dat truoc', '!pre order', '!preorder', '!giu hang'],
            'answer' => "📌 Về **đặt trước / giữ hàng**, bạn liên hệ **{$hot}** để shop hỗ trợ theo từng sản phẩm nhé!"],
        'try_on' => ['kw' => ['!mac thu', '!thu do', '!thu truc tiep', '!xem hang truc tiep', '!den shop xem', '!den cua hang'],
            'answer' => "👗 Bạn ghé **showroom** để mặc thử trực tiếp nhé:\n📍 123 Đường Thời Trang, P. Bến Nghé, Quận 1, TP.HCM\n🕐 T2-T6: 9:00-21:30 · T7: 9:00-22:00 · CN: 9:30-21:00"],
        'address' => ['kw' => ['!shop o dau', '!cua hang o dau', '!showroom', '!dia chi shop', '!dia chi cua hang', '!dia chi showroom', 'chi duong', 'ban do', 'dia chi'],
            'answer' => "📍 **Showroom THE ATELIER:**\n123 Đường Thời Trang, Phường Bến Nghé, Quận 1, TP.HCM\n\n🚇 Gần ga Metro Bến Thành.\n📞 Gọi **{$hot}** để được tư vấn nhé!"],
        'opening_hours' => ['kw' => ['!gio mo cua', '!gio dong cua', '!gio lam viec', 'mo cua luc', 'may gio mo', 'may gio dong', 'bao gio mo', 'co mo cua', 'thu bay co', 'chu nhat co'],
            'answer' => "🕐 **Giờ mở cửa:**\n- T2 - T6: 9:00 - 21:30\n- T7: 9:00 - 22:00\n- CN: 9:30 - 21:00\n\n💡 Website đặt hàng **24/7**!"],
        'contact' => ['kw' => ['!hotline', '!so dien thoai', '!lien he', 'email', 'zalo', 'facebook', 'fanpage', 'inbox'],
            'answer' => "📞 **Liên hệ:**\n- Hotline: **{$hot}** (8h - 22h)\n- Email: **{$mail}**\n\nBạn chat trực tiếp với mình 24/7 nhé! 💬"],
        'about_shop' => ['kw' => ['!the atelier la gi', '!gioi thieu', '!thuong hieu', '!ve the atelier', 've shop'],
            'answer' => "✨ **THE ATELIER** là cửa hàng thời trang **Nam · Nữ · Unisex** với showroom tại Quận 1, TP.HCM và website đặt hàng 24/7. Shop có **đổi trả {$days} ngày**, **freeship từ {$free}**.\n\nBạn muốn xem gì trước ạ — đồ nam, đồ nữ hay hàng đang giảm giá?"],
        'categories' => ['kw' => ['!danh muc', '!loai san pham', '!mat hang', '!shop ban gi', '!shop co nhung gi', '!ban nhung gi', '!co nhung gi'], 'handler' => '__CATEGORIES__'],
        'kids' => ['kw' => ['!tre em', '!em be', '!thieu nhi', '!cho be', '!con nit', 'do tre con'],
            'answer' => "👶 Rất tiếc, hiện tại **THE ATELIER chưa có dòng sản phẩm cho trẻ em** ạ 😔\nBạn có thể tham khảo **size nhỏ nhất (S)** hoặc đồ **Unisex** nhé!"],
        'stock_general' => ['kw' => ['!hang co san', '!co san hang', '!ton kho'],
            'answer' => "📦 Bạn cho mình **tên/loại sản phẩm + size** để mình kiểm tra tồn kho nhé. VD: \"**áo thun nam còn size M không**\"."],
        'bot_identity' => ['kw' => ['!ban la ai', '!ban la bot', '!ban la nguoi that', '!chatbot', 'ai tra loi', 'ten ban la gi', 'ten gi'],
            'answer' => "🤖 Mình là **Atelier Assistant** — trợ lý ảo của THE ATELIER. Mình giúp bạn **tư vấn size, tìm sản phẩm, kiểm tra tồn kho, khuyến mãi, đổi trả**… Cần gặp nhân viên thì gọi **{$hot}** nhé!"],
        'smalltalk' => ['kw' => ['!khoe khong', '!an com chua', '!buon qua', '!chan qua', 'ban gioi qua', 'ban tuyet voi'],
            'answer' => "😊 Cảm ơn bạn! Mình luôn sẵn sàng hỗ trợ. Hôm nay bạn muốn tìm đồ cho **dịp nào** (đi làm, đi chơi, đi tiệc...) hay cần mình **tư vấn size** ạ?"],
    ];
}

function chatbotMatchKnowledge(string $n, ?array $only = null): ?array {
    $best = null; $bestScore = 0;
    foreach (chatbotGetKnowledge() as $id => $item) {
        if ($only !== null && !in_array($id, $only, true)) continue;
        $score = 0;
        foreach ($item['kw'] as $kw) {
            $w = 1;
            if ($kw !== '' && $kw[0] === '!') { $w = 3; $kw = substr($kw, 1); }
            if (preg_match('/(?<![a-z0-9])' . preg_quote($kw, '/') . '(?![a-z0-9])/', $n)) $score += $w + substr_count($kw, ' ');
        }
        if ($score > $bestScore) { $bestScore = $score; $best = ['id' => $id, 'item' => $item, 'score' => $score]; }
    }
    return $best;
}

/* ============================================================
   🛠️ HANDLER TƯ VẤN / TRA ĐƠN
   ============================================================ */
function chatbotHandleOrderTracking(PDO $pdo, string $n): string {
    chatbotSession();
    $uid = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);   // [CHỈNH] key session đăng nhập của bạn
    if (!$uid) {
        return "🔐 Để xem tình trạng đơn hàng, bạn vui lòng **đăng nhập** rồi vào **Tài khoản → Đơn hàng của tôi** nhé!\n\nHoặc gọi **" . CHATBOT_HOTLINE . "** (cung cấp **mã đơn + SĐT đặt hàng**) để được hỗ trợ.";
    }
    $code = null;
    if (preg_match('/(?:don(?: hang)?|ma don|order|dh|#)\s*#?\s*(\d{1,10})\b/', $n, $m)) $code = (int)$m[1];

    $label = function ($s) {
        $s = mb_strtolower((string)$s);
        $map = ['pending' => '⏳ Chờ xử lý', 'processing' => '📦 Đang chuẩn bị', 'confirmed' => '📦 Đã xác nhận', 'shipping' => '🚚 Đang giao',
                'delivering' => '🚚 Đang giao', 'delivered' => '✅ Đã giao', 'completed' => '✅ Hoàn tất', 'cancelled' => '❌ Đã hủy', 'canceled' => '❌ Đã hủy'];
        return $map[$s] ?? ($s !== '' ? $s : 'Không rõ');
    };
    try {   // [CHỈNH] giả định bảng orders(id, user_id, status, created_at, total_amount|total)
        if ($code) { $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1"); $st->execute([$code, $uid]); }
        else       { $st = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 3"); $st->execute([$uid]); }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return $code ? "🔎 Mình không thấy đơn **#{$code}** trong tài khoản của bạn. Bạn kiểm tra lại mã đơn hoặc vào **Tài khoản → Đơn hàng của tôi** nhé!"
                         : "📭 Tài khoản của bạn chưa có đơn hàng nào ạ. Bạn cần mình gợi ý sản phẩm không?";
        }
        $out = $code ? "📦 **Đơn hàng #{$code}:**\n\n" : "📦 **Đơn hàng gần đây của bạn:**\n\n";
        foreach ($rows as $r) {
            $total = $r['total_amount'] ?? ($r['total'] ?? ($r['grand_total'] ?? null));
            $out .= "- **#{$r['id']}** — " . $label($r['status'] ?? '')
                  . ($total !== null ? " — " . chatbotVnd($total) : '')
                  . (!empty($r['created_at']) ? " — " . date('d/m/Y', strtotime($r['created_at'])) : '') . "\n";
        }
        return $out . "\n📌 Xem chi tiết hơn ở **Tài khoản → Đơn hàng của tôi**.";
    } catch (Exception $e) {
        return "📌 Bạn vào **Tài khoản → Đơn hàng của tôi** để xem tình trạng đơn nhé, hoặc gọi **" . CHATBOT_HOTLINE . "** để được hỗ trợ.";
    }
}

function chatbotHandleGift(PDO $pdo, string $raw, string $n, array $e): ?array {
    if (!preg_match('/\b(qua tang|mua qua|tang qua|chon qua|goi y qua|qua cho|qua sinh nhat|qua valentine|tang (?:ban|vo|chong|me|ba|bo|anh|chi|em|sep|nguoi yeu|dong nghiep|co|thay))\b/', $n)) return null;
    $r = mb_strtolower($raw);
    $gender = $e['gender']; $who = '';
    $female = '/(?<![\p{L}])(bạn gái|vợ|mẹ|má|chị|em gái|bà|cô|bồ|người yêu nữ)(?![\p{L}])/u';
    $male   = '/(?<![\p{L}])(bạn trai|chồng|ba|bố|cha|anh|em trai|ông|thầy)(?![\p{L}])/u';
    if (!$gender) {
        if (preg_match($female, $r, $m)) { $gender = 'female'; $who = $m[1]; }
        elseif (preg_match($male, $r, $m)) { $gender = 'male'; $who = $m[1]; }
    }
    $msg = "🎁 **Gợi ý chọn quà" . ($who ? " tặng {$who}" : '') . ":**\n\n"
         . "- Chọn **màu trung tính** (đen, trắng, be, xám) — dễ phối, khó sai\n"
         . "- Ưu tiên món **cơ bản, dễ mặc**: sơ mi, áo thun, áo khoác nhẹ\n"
         . "- Không chắc size? Chọn size phổ biến — shop **đổi size miễn phí lần đầu** trong " . CHATBOT_RETURN_DAYS . " ngày\n"
         . "- Cho mình **ngân sách** (VD: \"dưới 500k\") để lọc chuẩn hơn\n\n";
    $ent = $e; $ent['gender'] = $gender; $ent['types'] = $e['types']; if (!$ent['sort']) $ent['sort'] = 'best_seller';
    $res = chatbotSmartSearch($pdo, $ent, 4);
    $products = $res['products'];
    $msg .= $products ? "Mình gợi ý vài mẫu bên dưới 👇" : "Bạn cho mình biết thêm người nhận thích phong cách nào nhé!";
    return ['message' => $msg, 'products' => $products, 'intent' => 'gift'];
}

function chatbotHandleOutfit(PDO $pdo, string $n, array $e): ?array {
    if (empty($e['types']) || !preg_match('/\b(phoi do|phoi voi|phoi nhu the nao|phoi sao|mix|mac voi|ket hop voi|di voi|hop voi|outfit|mac chung)\b/', $n)) return null;
    $pair = [
        'sơ mi' => ['Sơ mi hợp **quần tây/kaki** khi đi làm; **quần jean + sneaker** khi đi chơi.', ['tây', 'jean']],
        'thun'  => ['Áo thun hợp **quần jean/short**; thêm **áo khoác nhẹ** hoặc sơ mi mặc ngoài cho có layer.', ['jean', 'short']],
        'polo'  => ['Polo lịch sự khi đi cùng **quần kaki/tây**; năng động hơn với **short/jean**.', ['kaki', 'tây']],
        'khoác' => ['Áo khoác phối **áo thun trơn + quần jean** là công thức dễ mặc; chọn tông cùng gam cho gọn.', ['thun', 'jean']],
        'hoodie'=> ['Hoodie hợp **jogger/jean** cho phong cách street, đi cùng sneaker.', ['jogger', 'jean']],
        'jean'  => ['Jean hợp **áo thun, sơ mi, polo**; đổi áo là đổi phong cách từ năng động đến lịch sự.', ['thun', 'sơ mi']],
        'tây'   => ['Quần tây hợp **sơ mi/polo** tông trung tính; thêm blazer nếu cần lịch sự.', ['sơ mi', 'polo']],
        'short' => ['Short hợp **áo thun/polo**, thêm dép hoặc sneaker là đủ cho ngày hè.', ['thun', 'polo']],
        'kaki'  => ['Kaki hợp **sơ mi/polo/áo thun**, tông be-nâu-xanh navy rất dễ phối.', ['sơ mi', 'polo']],
        'váy'   => ['Váy hợp **sơ mi/áo thun ôm** + áo khoác mỏng; chọn giày theo dịp (sneaker/sandal/giày cao).', ['sơ mi', 'khoác']],
        'đầm'   => ['Đầm thêm **áo khoác mỏng** khi đi làm/trời lạnh; phụ kiện tối giản cho thanh lịch.', ['khoác']],
        'len'   => ['Áo len hợp **jean/quần tây**; mặc sơ mi bên trong để thêm nét lịch sự.', ['jean', 'tây']],
        'jogger'=> ['Jogger hợp **áo thun/hoodie** + sneaker — thoải mái mà vẫn ra dáng.', ['thun', 'hoodie']],
    ];
    foreach ($e['types'] as $t) {
        if (!isset($pair[$t])) continue;
        [$tip, $with] = $pair[$t];
        $f = ['any_keywords' => $with, 'limit' => 4, 'in_stock' => true];
        if ($e['gender']) $f['gender'] = $e['gender'];
        $products = chatbotGetProductsByFilter($pdo, $f);
        return ['message' => "👔 **Gợi ý phối đồ:**\n\n{$tip}\n\n" . ($products ? "Mình gợi ý vài món **dễ phối cùng** bên dưới 👇" : ''), 'products' => $products, 'intent' => 'outfit'];
    }
    return null;
}

function chatbotHandleBodyAdvice(string $n): ?string {
    if (!preg_match('/\b(mac|chon|phoi|hop|nen|che|giau|do gi)\b/', $n)) return null;
    $tips = [
        '/\b(map|day dan|to con|tam than to|hoi day)\b/' => "Chọn đồ **vừa vặn, chất liệu đứng form**, màu trầm hoặc tông đơn sắc; tránh quá rộng (gây khối) hay quá bó. Áo dáng suôn + quần ống đứng rất hợp.",
        '/\b(gay|nho con|om yeu|manh khanh)\b/' => "Thử **mặc nhiều lớp** (áo thun + sơ mi/khoác mở), họa tiết kẻ ngang hoặc màu sáng giúp dáng đầy đặn hơn; quần ống đứng/straight cân đối tổng thể.",
        '/\b(thap|lun|nho nguoi|cao [01]m[0-5])\b/' => "Ưu tiên **quần/váy lưng cao**, set đồ **cùng tông** để tạo cảm giác dài chân; chọn áo độ dài vừa phải, tránh quá rộng/quá dài.",
        '/\b(cao lon|cao qua|cao ong)\b/' => "Bạn có lợi thế dáng! Thử **layer nhiều lớp**, áo dáng dài/oversize, quần ống rộng hoặc kẻ ngang để cân đối.",
        '/\b(bung|bung to|bung bia)\b/' => "Chọn **áo dáng suôn, chất liệu đứng form**, màu tối ở phần thân; sơ mi/áo khoác mở bên ngoài giúp tổng thể gọn hơn.",
        '/\b(vai rong|vai to)\b/' => "Chọn **cổ áo thoáng (V/polo)**, áo vừa vai, tránh đệm vai; quần ống đứng giúp cân đối phần trên-dưới.",
    ];
    foreach ($tips as $re => $tip) {
        if (preg_match($re, $n)) return "🧍 **Gợi ý chọn đồ theo dáng:**\n\n{$tip}\n\n📏 Bạn cho mình **chiều cao + cân nặng** (VD: \"1m65 55kg\") để mình chọn **size** chuẩn nhất nhé!";
    }
    return null;
}

/* ============================================================
   🤖 GROQ AI (có RAG)
   ============================================================ */
function chatbotAiAllowed(): bool {
    chatbotSession();
    $w = $_SESSION['chat_ai_window'] ?? ['start' => time(), 'count' => 0];
    if (time() - $w['start'] > 3600) $w = ['start' => time(), 'count' => 0];
    if ($w['count'] >= CHATBOT_AI_LIMIT_PER_HOUR) { $_SESSION['chat_ai_window'] = $w; return false; }
    $w['count']++;
    $_SESSION['chat_ai_window'] = $w;
    return true;
}

function chatbotBuildEnhancedSystemPrompt(PDO $pdo, array $ctx = []): string {
    $stats = chatbotGetShopStats($pdo);
    $categories = chatbotGetCategories($pdo);
    $catList = '';
    foreach ($categories as $c) $catList .= "- {$c['name']} ({$c['product_count']} sp)\n";

    $prodList = '';
    foreach (($ctx['products'] ?? []) as $p) {
        $sizes = [];
        foreach (chatbotGetProductSizes($pdo, (int)$p['id']) as $s) if ((int)$s['stock'] > 0) $sizes[] = $s['size'];
        $prodList .= "- {$p['name']} | " . chatbotVnd(chatbotFinalPrice($p))
                   . ((int)($p['discount_percent'] ?? 0) > 0 ? " (giảm {$p['discount_percent']}%)" : '')
                   . " | size còn: " . ($sizes ? implode(',', $sizes) : 'hết hàng') . "\n";
    }
    $measure = '';
    if (!empty($_SESSION['chat_measure'])) {
        $m = $_SESSION['chat_measure'];
        $measure = "\n## Khách đã cho số đo: cao {$m['height']}cm, nặng {$m['weight']}kg → size gợi ý {$m['size']}.";
    }

    return "Bạn là \"Atelier Assistant\" — nhân viên tư vấn thời trang của THE ATELIER (cửa hàng Nam/Nữ/Unisex).
## Phong cách: thân thiện, ngắn gọn (tối đa ~120 từ), xưng \"mình\" gọi \"bạn\", emoji nhẹ, trả lời bằng tiếng Việt, markdown đơn giản (**đậm**, gạch đầu dòng).
## Chính sách (CHỈ dùng đúng các thông tin này):
- Miễn phí ship đơn từ " . chatbotVnd(CHATBOT_FREESHIP) . "; dưới đó phí " . chatbotVnd(CHATBOT_SHIP_FEE) . ". Giao nội thành 1-2 ngày, tỉnh 2-4 ngày.
- Đổi trả " . CHATBOT_RETURN_DAYS . " ngày, còn tag, chưa qua sử dụng; lỗi từ shop đổi mới/hoàn 100%; đổi size miễn phí lần đầu.
- Thanh toán: COD (PIN), chuyển khoản/thẻ (PIN + OTP email).
- Hotline " . CHATBOT_HOTLINE . " (8h-22h), email " . CHATBOT_EMAIL . ". Showroom: 123 Đường Thời Trang, P. Bến Nghé, Q.1, TP.HCM.
- Bảng size: S 45-52kg, M 53-60kg, L 61-68kg, XL 69-78kg, 2XL 79-90kg.
## Cửa hàng: {$stats['total_products']} sản phẩm, {$stats['products_on_sale']} đang giảm giá.
## Danh mục:\n{$catList}
## Sản phẩm liên quan tới câu hỏi (dữ liệu thật):\n" . ($prodList ?: "(không có)\n") . $measure . "
## QUY TẮC BẮT BUỘC:
- Chỉ nêu giá/tồn kho/chất liệu có trong dữ liệu trên. KHÔNG bịa tên sản phẩm, giá, mã giảm giá, chính sách.
- Không biết hoặc ngoài phạm vi (tình trạng đơn cụ thể, hóa đơn, khiếu nại...) → nói thật và hướng dẫn gọi hotline.
- Không hứa giảm giá ngoài chương trình. Không bàn chuyện ngoài thời trang/mua sắm; nhẹ nhàng đưa về chủ đề shop.
- Câu hỏi mơ hồ → hỏi lại 1 câu ngắn để làm rõ.";
}

function chatbotCallGroq(string $userMessage, array $history, string $systemPrompt): ?string {
    if (!defined('GROQ_API_KEY') || empty(GROQ_API_KEY)) return null;

    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        if (!empty($h['role']) && !empty($h['content'])) $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $payload = json_encode([
        'model'       => defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.3-70b-versatile',
        'messages'    => $messages,
        'temperature' => 0.4,
        'max_tokens'  => 450,
    ], JSON_UNESCAPED_UNICODE);

    $endpoint = defined('GROQ_ENDPOINT') ? GROQ_ENDPOINT : 'https://api.groq.com/openai/v1/chat/completions';
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $response) {
            $data = json_decode($response, true);
            $txt = $data['choices'][0]['message']['content'] ?? null;
            return $txt !== null ? trim(strip_tags($txt)) : null;
        }
        if ($code !== 429 && $code < 500) break;
        usleep(400000);
    }
    return null;
}

function chatbotLogUnanswered(string $message): void {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir) || !is_writable($dir)) return;
    @file_put_contents($dir . '/chatbot_unanswered.log', date('c') . "\t" . str_replace(["\r", "\n", "\t"], ' ', $message) . "\n", FILE_APPEND | LOCK_EX);
}

/* ============================================================
   💡 GỢI Ý NHANH (nút bấm cho giao diện)
   ============================================================ */
function chatbotSuggestions(array $result): array {
    $intent = $result['intent'] ?? '';
    $np = count($result['products'] ?? []);
    if ($intent === 'size')   return ['Còn size này không?', 'Đang có khuyến mãi gì', 'Chính sách đổi trả'];
    if ($np > 1)              return ['Sản phẩm số 1', 'So sánh các mẫu này', 'Cái nào rẻ nhất?', 'Còn size M không?'];
    if ($np === 1)            return ['Giá bao nhiêu?', 'Còn size nào?', 'Chất liệu thế nào?', 'Phối đồ với món này'];
    if ($intent === 'greeting' || $intent === 'unknown') return ['📏 Tư vấn size', '🎉 Đang có khuyến mãi gì', '👕 Áo thun nam', '🎁 Gợi ý quà tặng'];
    return ['📏 Tư vấn size', '🛍️ Xem hàng mới', '↩️ Chính sách đổi trả'];
}

/* ============================================================
   🎯 MAIN ENTRY
   ============================================================ */
function chatbotReply(PDO $pdo, string $message): array {
    chatbotSession();
    $message = trim(preg_replace('/\s+/u', ' ', strip_tags($message)));
    if (mb_strlen($message) > 500) $message = mb_substr($message, 0, 500);

    try {
        $result = chatbotReplyCore($pdo, $message);
    } catch (Throwable $ex) {
        error_log('[chatbot] ' . $ex->getMessage());
        $result = ['message' => "Xin lỗi, hệ thống đang bận một chút 😅 Bạn thử lại hoặc gọi hotline **" . CHATBOT_HOTLINE . "** nhé!", 'products' => [], 'source' => 'error', 'intent' => 'error'];
    }
    if (empty($result['suggestions'])) $result['suggestions'] = chatbotSuggestions($result);
    if ($message !== '') chatbotRemember($message, $result['message']);
    return $result;
}

function chatbotReplyCore(PDO $pdo, string $message): array {
    $result = ['message' => '', 'products' => [], 'source' => 'rule', 'intent' => 'unknown'];
    $raw = $message;
    $n = chatbotNormalize($message);
    if ($n === '') { $result['message'] = "Bạn nhắn nội dung để mình hỗ trợ nhé 😊"; return $result; }
    $e = chatbotExtractEntities($raw, $n);
    $words = chatbotWordCount($n);
    $hasEntities = chatbotHasProductEntities($e);

    $setProducts = function (array $rows) use (&$result) {
        $result['products'] = chatbotMapProductsToCards($rows);
        chatbotSaveProductContext($result['products']);
    };

    /* 0. Câu trả lời "có/ok" cho câu hỏi trước đó */
    $pending = chatbotGetPending();
    if ($pending && preg_match('/^(co|co a|co nhe|ok|oke|vang|vang a|u|uh|ua|duoc|duoc a|yes|dong y|goi y di|xem di|cho xem|cho minh xem|xem thu)$/', $n)) {
        chatbotClearPending();
        if ($pending['type'] === 'size_products') {
            $size = $pending['data']['size'];
            $rows = chatbotGetProductsByFilter($pdo, ['size' => $size, 'limit' => 4, 'sort' => 'newest']);
            if ($rows) { $setProducts($rows); $result['message'] = "Đây là các mẫu **size {$size}** đang còn hàng 👇"; $result['intent'] = 'products'; return $result; }
            $result['message'] = "Tiếc quá, hiện chưa có mẫu nào còn **size {$size}** ạ 😔 Bạn thử size lân cận nhé!";
            return $result;
        }
    } elseif ($pending) {
        chatbotClearPending();
    }

    /* 1. Xã giao ngắn */
    if ($words <= 4 && preg_match('/^(xin chao|chao|hi|hello|hey|helo|hii+|alo|good morning)\b/', $n)) {
        $result['intent'] = 'greeting';
        $result['message'] = "Chào bạn! 👋 Mình là **Atelier Assistant**.\n\nMình giúp được bạn:\n- 📏 Tư vấn **size** — cho mình chiều cao + cân nặng (VD: \"1m65 55kg\")\n- 🛍️ **Tìm sản phẩm** theo loại, màu, giá, dịp (VD: \"áo sơ mi nam dưới 500k\")\n- 📦 **Kiểm tra tồn kho** — VD: \"áo thun nam còn M không\"\n- 🎁 **Gợi ý quà tặng**, **phối đồ**\n- 🎉 **Khuyến mãi**, 🚚 **ship**, ↩️ **đổi trả**, 📦 **đơn hàng**\n\nBạn cần gì ạ?";
        return $result;
    }
    if ($words <= 6 && !$hasEntities && preg_match('/\b(cam on|ok|duoc roi|tuyet voi|hay qua|tot qua|da hieu)\b/', $n) && !preg_match('/\b(khong|bao nhieu|nao)\b/', $n)) {
        $result['intent'] = 'thanks';
        $result['message'] = "Dạ không có gì ạ! 😊 Cần gì thêm bạn cứ nhắn mình nhé. Chúc bạn mua sắm vui vẻ! 🛍️";
        return $result;
    }
    if ($words <= 6 && preg_match('/\b(tam biet|bye|goodbye|hen gap lai|thoi nhe)\b/', $n)) {
        $result['intent'] = 'goodbye';
        $result['message'] = "Tạm biệt bạn! 👋 Hẹn gặp lại tại **THE ATELIER** nhé! 💛";
        return $result;
    }

    /* 2. Tư vấn size theo số đo (kể cả khi khách gửi từng phần) */
    $hw = chatbotExtractHW($raw);
    $looksMeasure = ($hw['h'] && $hw['w']) && !preg_match('/\b(con|het|ton kho)\b/', $n)
        && (preg_match('/(kg|ky|cm|cao|nang|\d\s*m\s*\d|^\s*\d{2,3}\s+\d{2,3}\s*$)/u', mb_strtolower($raw)) || $words <= 3);
    if ($looksMeasure) {
        $fit = chatbotParseFitPreference($n);
        $calc = chatbotComputeSize($hw['h'], $hw['w'], $fit);
        chatbotSession();
        $_SESSION['chat_measure'] = ['height' => $calc['height'], 'weight' => $calc['weight'], 'size' => $calc['size']];
        unset($_SESSION['chat_measure_partial']);
        $f = ['size' => $calc['size'], 'limit' => 3, 'sort' => 'newest'] + ($e['gender'] ? ['gender' => $e['gender']] : []);
        $rows = chatbotGetProductsByFilter($pdo, $f);
        $result['intent'] = 'size'; $result['source'] = 'rule_size';
        $result['message'] = chatbotFormatSizeResponse($calc, empty($rows));
        if ($rows) $setProducts($rows); else chatbotSetPending('size_products', ['size' => $calc['size']]);
        return $result;
    }
    $strict = chatbotExtractHW($raw, true);
    if ($words <= 6 && !$hasEntities && ($strict['h'] || $strict['w']) && !($strict['h'] && $strict['w'])) {
        $part = $_SESSION['chat_measure_partial'] ?? [];
        if (!empty($part) && time() - ($part['at'] ?? 0) < 600) { $strict['h'] = $strict['h'] ?: ($part['h'] ?? null); $strict['w'] = $strict['w'] ?: ($part['w'] ?? null); }
        if ($strict['h'] && $strict['w']) {
            $calc = chatbotComputeSize($strict['h'], $strict['w'], chatbotParseFitPreference($n));
            $_SESSION['chat_measure'] = ['height' => $calc['height'], 'weight' => $calc['weight'], 'size' => $calc['size']];
            unset($_SESSION['chat_measure_partial']);
            $rows = chatbotGetProductsByFilter($pdo, ['size' => $calc['size'], 'limit' => 3, 'sort' => 'newest']);
            $result['intent'] = 'size'; $result['source'] = 'rule_size';
            $result['message'] = chatbotFormatSizeResponse($calc, empty($rows));
            if ($rows) $setProducts($rows);
            return $result;
        }
        $_SESSION['chat_measure_partial'] = ['h' => $strict['h'], 'w' => $strict['w'], 'at' => time()];
        $result['intent'] = 'size';
        $result['message'] = $strict['w'] ? "Mình ghi nhận cân nặng **{$strict['w']}kg** ✅ Bạn cho mình thêm **chiều cao** (VD: 1m65) để tư vấn size nhé!"
                                          : "Mình ghi nhận chiều cao **{$strict['h']}cm** ✅ Bạn cho mình thêm **cân nặng** (VD: 55kg) để tư vấn size nhé!";
        return $result;
    }

    /* 3. Khiếu nại / gặp nhân viên (ưu tiên cao) */
    $urgent = chatbotMatchKnowledge($n, ['complaint', 'human_agent']);
    if ($urgent && $urgent['score'] >= 3) { $result['intent'] = $urgent['id']; $result['message'] = $urgent['item']['answer']; return $result; }

    /* 4. Tra cứu đơn hàng */
    if (preg_match('/\b(?:don(?: hang)?|ma don|order|dh)\s*#?\s*\d{1,10}\b/', $n) || (($k = chatbotMatchKnowledge($n, ['order_tracking'])) && $k['score'] >= 3)) {
        $result['intent'] = 'order'; $result['message'] = chatbotHandleOrderTracking($pdo, $n); return $result;
    }

    /* 5. Kiểm tra tồn kho */
    if (chatbotIsStockQuery($n)) {
        $size = $e['size'] ?? chatbotStandaloneSize($n);
        if (!empty($e['types']) || $e['color']) {                       // cấp danh mục: "áo thun nam còn M không"
            $ent = $e; $ent['size'] = $size; $ent['sort'] = $e['sort'] ?: 'newest';
            $rows = chatbotGetProductsByFilter($pdo, chatbotEntitiesToFilter($ent) + ['limit' => 4]);
            if ($rows) {
                $setProducts($rows);
                $result['intent'] = 'stock';
                $result['message'] = $size ? "✅ Còn **size {$size}** ở **" . count($rows) . " mẫu** (" . chatbotDescribeCriteria($e) . ") 👇" : "📦 Các mẫu đang **còn hàng** (" . chatbotDescribeCriteria($e) . ") 👇";
                return $result;
            }
            if ($size) {
                $alt = chatbotGetProductsByFilter($pdo, chatbotEntitiesToFilter(array_merge($e, ['size' => null])) + ['limit' => 3, 'in_stock' => true]);
                $result['intent'] = 'stock';
                $result['message'] = "❌ Hiện **chưa có mẫu nào còn size {$size}** theo yêu cầu của bạn." . ($alt ? "\n💡 Mình gợi ý các mẫu tương tự còn size khác 👇" : '');
                if ($alt) $setProducts($alt);
                return $result;
            }
        } else {
            $stockQ = chatbotDetectStockQuery($message);
            $data = $stockQ ? chatbotCheckStock($pdo, $stockQ['keyword'], $size) : null;
            if (!$data && ($fid = chatbotGetFocusId())) {
                $p = chatbotGetProductById($pdo, $fid);
                if ($p) $data = ['product' => $p, 'sizes' => $p['sizes']];
            }
            if ($data) {
                $result['intent'] = 'stock';
                $result['message'] = chatbotFormatStockResponse($data, $size);
                $setProducts([$data['product']]);
                return $result;
            }
            if ($stockQ && mb_strlen($stockQ['keyword']) >= 3 && !preg_match('/^(size|co|hang)$/', $stockQ['keyword'])) {
                $result['message'] = "Mình không tìm thấy sản phẩm \"**" . chatbotSafeText($stockQ['keyword']) . "**\" trong shop 🤔 Bạn thử nói tên/loại khác (VD: \"áo thun nam\") nhé!";
                return $result;
            }
            $result['message'] = "Bạn đang hỏi tồn kho **sản phẩm nào** ạ? Cho mình tên/loại + size nhé. VD: \"**áo sơ mi nam còn size L không**\".";
            return $result;
        }
    }

    /* 6. Tham chiếu sản phẩm đã gợi ý: số thứ tự / so sánh / rẻ nhất */
    $lastIds = chatbotLoadProductContext();
    if ($lastIds) {
        if (preg_match('/\b(so sanh|nen chon cai nao|chon cai nao|cai nao tot|khac nhau)\b/', $n)) {
            $ctxP = chatbotLoadContextProducts($pdo, 4);
            if (count($ctxP) >= 2) { $result['intent'] = 'compare'; $result['message'] = chatbotFormatComparison($ctxP); return $result; }
        }
        if (empty($e['types']) && preg_match('/\b(re nhat|dat nhat|giam nhieu nhat|moi nhat)\b/', $n)) {
            $ctxP = chatbotLoadContextProducts($pdo, 4);
            if (count($ctxP) >= 2) {
                usort($ctxP, fn($a, $b) => chatbotFinalPrice($a) <=> chatbotFinalPrice($b));
                if (preg_match('/dat nhat/', $n)) $ctxP = array_reverse($ctxP);
                elseif (preg_match('/giam nhieu nhat/', $n)) usort($ctxP, fn($a, $b) => (int)$b['discount_percent'] <=> (int)$a['discount_percent']);
                $pick = $ctxP[0];
                $result['intent'] = 'detail';
                $result['message'] = "👉 Trong các mẫu vừa gợi ý, **" . $pick['name'] . "** là lựa chọn bạn hỏi (" . chatbotVnd(chatbotFinalPrice($pick)) . ").\n\n" . chatbotFormatProductDetail($pick);
                $setProducts([$pick]);
                return $result;
            }
        }
    }
    $idx = chatbotDetectProductReference($message);
    if ($idx !== null) {
        if ($lastIds && isset($lastIds[$idx])) {
            $p = chatbotGetProductById($pdo, (int)$lastIds[$idx]);
            if ($p) { $result['intent'] = 'detail'; $result['message'] = chatbotFormatProductDetail($p); $setProducts([$p]); return $result; }
        }
        $result['message'] = "Bạn vui lòng tìm sản phẩm trước khi hỏi chi tiết nhé! VD: \"áo thun nam\" 😊";
        return $result;
    }

    /* 7. Câu hỏi nối tiếp về "sản phẩm này" (giá / size / chất liệu / màu / mua) */
    $aspect = null;
    if (preg_match('/\b(gia|bao nhieu tien|bao nhieu)\b/', $n)) $aspect = 'price';
    elseif (preg_match('/\b(chat lieu|vai|cotton|co gian|thoang|mong|day|nong)\b/', $n)) $aspect = 'material';
    elseif (preg_match('/\b(mau|color)\b/', $n)) $aspect = 'color';
    elseif (preg_match('/\b(size|co san|con hang|ton kho)\b/', $n)) $aspect = 'sizes';
    elseif (preg_match('/\b(chi tiet|thong tin|mo ta)\b/', $n)) $aspect = 'detail';
    elseif (preg_match('/\b(dat mua|them vao gio|mua cai nay|mua mon nay|chot|lay cai nay)\b/', $n)) $aspect = 'buy';
    $hasRef = (bool)preg_match('/\b(nay|do|kia|ay|vua roi|vua xem|tren)\b/', $n);
    if ($aspect && ($hasRef || ($words <= 5 && empty($e['types']) && !$e['gender']))) {
        $fid = chatbotGetFocusId() ?? (count($lastIds) === 1 ? (int)$lastIds[0] : null);
        if ($fid) {
            $p = chatbotGetProductById($pdo, $fid);
            if ($p) {
                $result['intent'] = 'detail';
                $result['message'] = chatbotFormatProductAspect($p, $aspect, $e['size'] ?? chatbotStandaloneSize($n));
                $setProducts([$p]);
                return $result;
            }
        } elseif (count($lastIds) > 1 && $hasRef) {
            $result['message'] = "Bạn đang hỏi **sản phẩm nào** ạ? Nói giúp mình \"**sản phẩm số 1**\", \"**số 2**\"… nhé 😊";
            return $result;
        }
    }

    /* 8. Tư vấn: quà tặng / phối đồ / dáng người */
    if ($gift = chatbotHandleGift($pdo, $raw, $n, $e)) {
        $setProducts($gift['products']); $result['message'] = $gift['message']; $result['intent'] = 'gift'; return $result;
    }
    if ($outfit = chatbotHandleOutfit($pdo, $n, $e)) {
        $setProducts($outfit['products']); $result['message'] = $outfit['message']; $result['intent'] = 'outfit'; return $result;
    }
    if ($tip = chatbotHandleBodyAdvice($n)) { $result['intent'] = 'body'; $result['message'] = $tip; return $result; }

    /* 9. Knowledge Base (FAQ) */
    $kb = chatbotMatchKnowledge($n);
    if ($kb && !(($kb['item']['yield'] ?? false) && $hasEntities)) {
        $item = $kb['item'];
        $result['intent'] = $kb['id'];
        $handler = $item['handler'] ?? null;
        if ($handler === '__PROMOTIONS__') {
            $result['message'] = chatbotFormatPromotionResponse($pdo);
            $rows = chatbotGetDiscountedProducts($pdo, 4);
            if ($rows) $setProducts($rows);
            return $result;
        }
        if ($handler === '__ORDER__')      { $result['message'] = chatbotHandleOrderTracking($pdo, $n); return $result; }
        if ($handler === '__CATEGORIES__') {
            $cats = chatbotGetCategories($pdo);
            if ($cats) {
                $lines = '';
                foreach ($cats as $c) $lines .= "- **{$c['name']}** ({$c['product_count']} sản phẩm)\n";
                $result['message'] = "THE ATELIER hiện có các danh mục sau:\n\n{$lines}\nBạn muốn xem danh mục nào ạ?";
                return $result;
            }
        } else {
            $result['message'] = $item['answer'];
            $cards = $item['cards'] ?? null;
            if (is_array($cards)) {
                $rows = chatbotGetProductsByFilter($pdo, $cards + ['limit' => 4, 'in_stock' => true]);
                if ($rows) $setProducts($rows);
            } elseif ($cards === true && ($hasEntities || $e['gender'])) {
                $res = chatbotSmartSearch($pdo, $e, 3);
                if ($res['products']) $setProducts($res['products']);
            }
            return $result;
        }
    }

    /* 10. Gợi ý theo dịp / mùa */
    if ($occ = chatbotDetectOccasion($n)) {
        $extra = [];
        foreach (['gender', 'min_price', 'max_price', 'size'] as $k) if (!empty($e[$k])) $extra[$k] = $e[$k];
        if (!empty($e['on_sale'])) $extra['on_sale'] = true;
        if (!empty($e['sort'])) $extra['sort'] = $e['sort'];
        $rows = chatbotSearchByOccasion($pdo, $occ, 4, $extra);
        $result['intent'] = 'occasion';
        if ($rows) {
            $setProducts($rows);
            $result['message'] = chatbotGetOccasionMessage($occ) . "\n\n💡 " . chatbotOccasions()[$occ]['tip'];
        } else {
            $result['message'] = chatbotOccasions()[$occ]['tip'] . "\n\nMình chưa tìm thấy mẫu phù hợp ngay lúc này. Bạn nói rõ hơn loại đồ/ngân sách để mình tìm giúp nhé!";
        }
        return $result;
    }

    /* 11. Tìm sản phẩm theo thực thể (loại · giới tính · màu · giá · size · sắp xếp) */
    if ($hasEntities || ($e['gender'] && ($words <= 5 || chatbotHasSearchVerb($n))) || (chatbotHasSearchVerb($n) && $words <= 6)) {
        $ent = $e;
        $res = chatbotSmartSearch($pdo, $ent, 4);
        if ($res['products']) {
            $setProducts($res['products']);
            $crit = chatbotDescribeCriteria($res['criteria']);
            $result['intent'] = 'products';
            $result['message'] = $res['dropped']
                ? "Mình chưa có mẫu khớp đủ tiêu chí (**" . implode(', ', $res['dropped']) . "**) nên gợi ý các mẫu **gần nhất**" . ($crit ? " ({$crit})" : '') . " 👇"
                : "Mình tìm được **" . count($res['products']) . " sản phẩm**" . ($crit ? " ({$crit})" : '') . " 👇";
            $result['message'] .= "\n\n💬 Hỏi mình \"**sản phẩm số 1**\", \"**so sánh**\" hoặc \"**còn size M không**\" nhé!";
            return $result;
        }
        if ($hasEntities) {
            $result['intent'] = 'products';
            $result['message'] = "Mình chưa tìm thấy sản phẩm khớp \"**" . chatbotSafeText(chatbotDescribeCriteria($e) ?: $raw, 60) . "**\" 🤔\nBạn thử đổi **loại/màu/mức giá**, hoặc hỏi \"**hàng mới**\", \"**đang giảm giá**\" nhé!";
            return $result;
        }
    }

    /* 12. Groq AI (có RAG) */
    if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY) && chatbotAiAllowed()) {
        $ctxRows = [];
        $f = chatbotEntitiesToFilter($e);
        $ctxRows = chatbotGetProductsByFilter($pdo, ($f ?: ['sort' => 'newest']) + ['limit' => 5]);
        if (!$ctxRows) $ctxRows = chatbotGetProductsByFilter($pdo, ['sort' => 'newest', 'limit' => 5]);
        $history = $_SESSION['chat_history'] ?? [];
        $aiReply = chatbotCallGroq($message, array_slice($history, -12), chatbotBuildEnhancedSystemPrompt($pdo, ['products' => $ctxRows]));
        if ($aiReply !== null && $aiReply !== '') {
            $result['message'] = $aiReply; $result['source'] = 'ai'; $result['intent'] = 'ai';
            $mentioned = [];                                              // chỉ kèm thẻ SP mà AI thực sự nhắc tới
            foreach ($ctxRows as $p) if (mb_stripos($aiReply, $p['name']) !== false) $mentioned[] = $p;
            if ($mentioned) $setProducts(array_slice($mentioned, 0, 3));
            return $result;
        }
    }

    /* 13. Fallback cuối */
    chatbotLogUnanswered($message);
    $result['message'] = "Xin lỗi, mình chưa hiểu rõ ý bạn 😅\n\nBạn thử hỏi theo mẫu:\n- \"**áo thun nam màu đen dưới 300k**\"\n- \"**1m65 55kg mặc size gì**\"\n- \"**mặc gì đi tiệc**\" · \"**quà tặng bạn gái**\"\n- \"**đổi trả**\" · \"**phí ship**\"\n\nHoặc gọi hotline **" . CHATBOT_HOTLINE . "** để được hỗ trợ nhé!";
    return $result;
}