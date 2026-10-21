<?php
require_once 'includes/config.php';

/* ============================================================
   📝 NỘI DUNG CHÍNH SÁCH — SỬA Ở ĐÂY
   ============================================================ */
$POLICY = [
    'updated_at'     => '07/10/2026',
    'return_days'    => 7,          // số ngày được đổi trả
    'process_hours'  => 24,         // giờ xử lý yêu cầu
    'refund_days'    => '1-3',      // ngày hoàn tiền qua bank

    /* Hero */
    'hero_title'    => 'Chính sách đổi trả',
    'hero_subtitle' => 'Đổi trả dễ dàng — Hoàn tiền nhanh chóng — Không cần lo lắng',
    'hero_desc'     => 'Tại THE ATELIER, chúng tôi hiểu rằng không phải lúc nào sản phẩm cũng vừa vặn hoàn hảo. Chính sách đổi trả linh hoạt của chúng tôi giúp bạn yên tâm mua sắm.',

    /* Điều kiện được đổi trả */
    'eligibility' => [
        'Sản phẩm còn nguyên tem mác, chưa qua sử dụng, chưa giặt ủi',
        'Còn hóa đơn mua hàng hoặc mã đơn hàng trong hệ thống',
        'Yêu cầu trong vòng 7 ngày kể từ khi nhận hàng thành công',
        'Sản phẩm bị lỗi do nhà sản xuất hoặc giao sai sản phẩm',
        'Sản phẩm không vừa size (đổi sang size khác cùng mẫu)',
        'Sản phẩm khác biệt rõ rệt so với hình ảnh mô tả',
    ],

    /* Điều kiện KHÔNG được đổi trả */
    'not_eligible' => [
        'Sản phẩm đã qua sử dụng, đã giặt ủi, hoặc có mùi lạ',
        'Sản phẩm đã cắt tag, chỉnh sửa, hoặc hư hỏng do người dùng',
        'Sản phẩm mua trong chương trình xả hàng / thanh lý (có ghi chú)',
        'Đồ lót, phụ kiện cá nhân (vì lý do vệ sinh)',
        'Yêu cầu sau thời hạn 7 ngày kể từ khi nhận hàng',
        'Sản phẩm đã được đổi trả 1 lần trước đó (không hỗ trợ đổi nhiều lần)',
    ],

    /* Quy trình 4 bước */
    'steps' => [
        [
            'num'   => '01',
            'title' => 'Gửi yêu cầu',
            'desc'  => 'Đăng nhập → vào <b>Tài khoản</b> → mục <b>Lịch sử mua hàng</b> → chọn đơn đã giao → bấm nút <b>"Yêu cầu trả hàng / Hoàn tiền"</b>.',
        ],
        [
            'num'   => '02',
            'title' => 'Xác nhận PIN',
            'desc'  => 'Nhập <b>mã PIN 6 số</b> (dùng chung với ví Atelier Pay) để xác thực yêu cầu. Đây là lớp bảo mật để tránh yêu cầu giả mạo.',
        ],
        [
            'num'   => '03',
            'title' => 'Chờ duyệt',
            'desc'  => 'Bộ phận CSKH xem xét ảnh minh chứng và lý do trong vòng <b>24 giờ làm việc</b>. Bạn sẽ nhận thông báo qua email ngay khi có kết quả.',
        ],
        [
            'num'   => '04',
            'title' => 'Nhận hoàn tiền',
            'desc'  => 'Sau khi duyệt, tiền được hoàn về <b>ví Atelier Pay</b> (trong 24h) hoặc <b>chuyển khoản</b> (1-3 ngày làm việc).',
        ],
    ],

    /* Phương thức hoàn tiền */
    'refund_methods' => [
        [
            'icon'  => '💰',
            'name'  => 'Ví Atelier Pay',
            'time'  => 'Trong vòng 24 giờ',
            'desc'  => 'Cộng trực tiếp vào ví điện tử của bạn. Có thể dùng ngay để mua đơn tiếp theo.',
            'best'  => true,
        ],
        [
            'icon'  => '🏦',
            'name'  => 'Chuyển khoản ngân hàng',
            'time'  => '1-3 ngày làm việc',
            'desc'  => 'Chuyển về tài khoản đã liên kết. Yêu cầu bạn đã liên kết bank trong ví Atelier Pay.',
            'best'  => false,
        ],
    ],

    /* Lưu ý quan trọng */
    'notes' => [
        'Mọi yêu cầu trả hàng đều cần xác nhận <b>mã PIN 6 số</b> để bảo mật.',
        'Sản phẩm gửi về phải đóng gói cẩn thận — khuyến khích dùng lại hộp/túi ban đầu.',
        '<b>Chi phí vận chuyển</b>: Miễn phí nếu lỗi từ shop, khách chịu phí nếu đổi ý (30.000đ/lần).',
        'Sau 2 lần yêu cầu bị từ chối do không hợp lệ, tài khoản có thể bị hạn chế tính năng trả hàng.',
        'THE ATELIER có quyền từ chối các yêu cầu có dấu hiệu lạm dụng hoặc gian lận.',
    ],

    /* FAQ */
    'faq' => [
        [
            'q' => 'Tôi có thể đổi size thay vì trả hàng không?',
            'a' => 'Có. Bạn vẫn làm theo quy trình <b>Yêu cầu trả hàng</b>, nhưng ghi rõ trong phần lý do là <i>"Muốn đổi sang size khác"</i>. Shop sẽ hoàn tiền và bạn có thể đặt đơn mới với size phù hợp — hoặc shop hỗ trợ đổi trực tiếp tùy tình trạng kho.',
        ],
        [
            'q' => 'Đơn hàng COD có được trả hàng không?',
            'a' => 'Có. Đơn COD sau khi nhận hàng vẫn được trả trong vòng 7 ngày. Tiền hoàn sẽ chuyển vào ví Atelier Pay hoặc chuyển khoản — tùy bạn chọn.',
        ],
        [
            'q' => 'Tôi có phải chịu phí ship trả hàng không?',
            'a' => 'Nếu lỗi từ phía shop (giao sai, hàng lỗi, khác mô tả) → <b>shop chịu 100% phí</b>. Nếu bạn đổi ý hoặc đặt nhầm → bạn chịu phí ship trả hàng (khoảng 30.000đ).',
        ],
        [
            'q' => 'Bao lâu tôi nhận được tiền hoàn?',
            'a' => 'Sau khi admin duyệt yêu cầu: hoàn vào <b>ví Atelier Pay</b> trong 24h, hoặc <b>chuyển khoản</b> trong 1-3 ngày làm việc (tùy ngân hàng).',
        ],
        [
            'q' => 'Tôi cần cung cấp ảnh không?',
            'a' => 'Có — ảnh là <b>bắt buộc</b> để shop đánh giá tình trạng sản phẩm. Bạn nên chụp 1-5 ảnh rõ nét: toàn bộ sản phẩm, tem mác, và lỗi (nếu có). Ảnh càng rõ, xử lý càng nhanh.',
        ],
        [
            'q' => 'Sản phẩm tôi mua lâu rồi có trả được không?',
            'a' => 'Rất tiếc, chỉ áp dụng cho đơn trong vòng <b>7 ngày</b> kể từ khi giao thành công. Quá thời hạn này shop không thể hỗ trợ trả hàng.',
        ],
    ],

    'contact' => [
        'hotline' => '1900 123 456',
        'email'   => 'support@theatelier.vn',
        'hours'   => '8:00 - 21:00 · Thứ 2 - Chủ nhật',
        'address' => '123 Phố Cổ, Hoàn Kiếm, Hà Nội',
    ],
];

$isLogged = isLoggedIn();
$ctaUrl  = $isLogged ? 'account.php' : 'login.php';
$ctaText = $isLogged ? '🔄 Yêu cầu trả hàng ngay' : '🔐 Đăng nhập để yêu cầu trả hàng';
?>
<?php include 'includes/header.php'; ?>

<style>
    /* ============================================================
       THE ATELIER — RETURN POLICY PAGE
       ============================================================ */
    .rp-page {
        background: #f2ece0;
        color: #1c1b19;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        line-height: 1.65;
    }

    .rp-wrap {
        max-width: 1000px;
        margin: 0 auto;
        padding: 0 20px;
    }

    /* ============ HERO ============ */
    .rp-hero {
        padding: 70px 0 50px;
        text-align: center;
        position: relative;
    }
    .rp-hero__label {
        font-family: 'IBM Plex Mono', ui-monospace, monospace;
        font-size: 0.72rem;
        letter-spacing: 0.35em;
        text-transform: uppercase;
        color: #8a8377;
        margin-bottom: 20px;
        display: inline-block;
    }
    .rp-hero__label::before,
    .rp-hero__label::after {
        content: '';
        display: inline-block;
        width: 40px;
        height: 1px;
        background: #8a8377;
        vertical-align: middle;
        margin: 0 14px;
    }
    .rp-hero h1 {
        font-family: 'Fraunces', Georgia, serif;
        font-size: clamp(2.5rem, 5vw, 3.75rem);
        font-weight: 400;
        margin: 0 0 20px;
        color: #1c1b19;
        letter-spacing: -0.02em;
        line-height: 1.05;
    }
    .rp-hero h1 em {
        font-style: italic;
        color: #a8461f;
        font-weight: 500;
    }
    .rp-hero__sub {
        font-family: 'Fraunces', Georgia, serif;
        font-style: italic;
        font-size: clamp(1rem, 1.6vw, 1.15rem);
        color: #37423a;
        max-width: 620px;
        margin: 0 auto 16px;
        line-height: 1.55;
    }
    .rp-hero__desc {
        font-size: 0.95rem;
        color: #6b7280;
        max-width: 560px;
        margin: 0 auto;
        line-height: 1.7;
    }
    .rp-hero__updated {
        display: inline-block;
        margin-top: 26px;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.72rem;
        color: #8a8377;
        padding: 6px 14px;
        background: #fffdf9;
        border: 1px solid #e8e0d0;
        border-radius: 20px;
    }

    /* ============ STATS BAR ============ */
    .rp-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        margin: 30px 0 60px;
        background: #fffdf9;
        border: 1px solid #e8e0d0;
        border-radius: 12px;
        overflow: hidden;
    }
    .rp-stat {
        padding: 26px 20px;
        text-align: center;
        border-right: 1px solid #e8e0d0;
        transition: background 0.2s;
    }
    .rp-stat:last-child { border-right: none; }
    .rp-stat:hover { background: #faf6ee; }
    .rp-stat__num {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 2.2rem;
        font-weight: 500;
        color: #a8461f;
        line-height: 1;
        margin-bottom: 6px;
        letter-spacing: -0.02em;
    }
    .rp-stat__num small {
        font-size: 1rem;
        color: #8a8377;
        font-style: italic;
    }
    .rp-stat__label {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.68rem;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: #8a8377;
    }

    /* ============ SECTION ============ */
    .rp-section {
        margin-bottom: 70px;
    }
    .rp-section__head {
        margin-bottom: 32px;
        display: flex;
        align-items: baseline;
        gap: 18px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e8e0d0;
    }
    .rp-section__num {
        font-family: 'Fraunces', Georgia, serif;
        font-style: italic;
        font-size: 2.5rem;
        color: #a8461f;
        line-height: 1;
        font-weight: 400;
    }
    .rp-section__title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: clamp(1.4rem, 2.5vw, 1.85rem);
        font-weight: 400;
        margin: 0;
        color: #1c1b19;
        letter-spacing: -0.01em;
    }

    /* ============ 2-COLUMN ELIGIBILITY ============ */
    .rp-cols {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    .rp-panel {
        background: #fffdf9;
        border: 1px solid #e8e0d0;
        border-radius: 12px;
        padding: 28px 28px 24px;
        position: relative;
        overflow: hidden;
    }
    .rp-panel::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
    }
    .rp-panel--yes::before { background: #166534; }
    .rp-panel--no::before  { background: #b91c1c; }
    .rp-panel__header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 18px;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }
    .rp-panel--yes .rp-panel__header { color: #166534; }
    .rp-panel--no  .rp-panel__header { color: #b91c1c; }
    .rp-panel__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        font-size: 0.85rem;
        font-weight: 700;
    }
    .rp-panel--yes .rp-panel__icon { background: #dcfce7; color: #166534; }
    .rp-panel--no  .rp-panel__icon { background: #fee2e2; color: #b91c1c; }

    .rp-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .rp-list li {
        position: relative;
        padding: 9px 0 9px 26px;
        font-size: 0.9rem;
        color: #37423a;
        line-height: 1.55;
        border-bottom: 1px dashed #f0e9dc;
    }
    .rp-list li:last-child { border-bottom: none; }
    .rp-list li::before {
        content: '';
        position: absolute;
        left: 0;
        top: 17px;
        width: 14px;
        height: 1px;
        background: #8a8377;
    }
    .rp-panel--yes .rp-list li::before { background: #166534; }
    .rp-panel--no  .rp-list li::before { background: #b91c1c; }

    /* ============ STEPS TIMELINE ============ */
    .rp-steps {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        position: relative;
    }
    .rp-step {
        background: #fffdf9;
        border: 1px solid #e8e0d0;
        border-radius: 12px;
        padding: 26px 22px;
        position: relative;
        transition: transform 0.25s, box-shadow 0.25s, border-color 0.25s;
    }
    .rp-step:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(28,27,25,0.08);
        border-color: #d4c9b3;
    }
    .rp-step__num {
        font-family: 'Fraunces', Georgia, serif;
        font-style: italic;
        font-size: 2.6rem;
        font-weight: 400;
        color: #a8461f;
        line-height: 1;
        margin-bottom: 12px;
        letter-spacing: -0.02em;
    }
    .rp-step__title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0 0 10px;
        color: #1c1b19;
    }
    .rp-step__desc {
        font-size: 0.85rem;
        color: #4b5563;
        line-height: 1.6;
        margin: 0;
    }
    .rp-step__desc b { color: #a8461f; font-weight: 600; }

    /* ============ REFUND METHODS ============ */
    .rp-methods {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    .rp-method {
        background: #fffdf9;
        border: 2px solid #e8e0d0;
        border-radius: 12px;
        padding: 26px 28px;
        position: relative;
        transition: border-color 0.2s, transform 0.2s;
    }
    .rp-method:hover { transform: translateY(-3px); }
    .rp-method--best {
        border-color: #a8461f;
        background: linear-gradient(135deg, #fffaf5 0%, #fffdf9 60%);
    }
    .rp-method__best {
        position: absolute;
        top: -12px;
        right: 20px;
        background: #a8461f;
        color: #fff;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        padding: 5px 12px;
        border-radius: 20px;
    }
    .rp-method__head {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 14px;
    }
    .rp-method__icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: #faf6ee;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }
    .rp-method__name {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.15rem;
        font-weight: 600;
        margin: 0;
        color: #1c1b19;
    }
    .rp-method__time {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.72rem;
        color: #a8461f;
        margin-top: 2px;
        letter-spacing: 0.04em;
    }
    .rp-method__desc {
        font-size: 0.88rem;
        color: #4b5563;
        line-height: 1.6;
        margin: 0;
    }

    /* ============ NOTES ============ */
    .rp-notes {
        background: #fffdf9;
        border: 1px solid #e8e0d0;
        border-left: 4px solid #b98a1d;
        border-radius: 12px;
        padding: 28px 32px;
    }
    .rp-notes__head {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #b98a1d;
    }
    .rp-notes ul {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .rp-notes li {
        position: relative;
        padding: 8px 0 8px 26px;
        font-size: 0.9rem;
        color: #37423a;
        line-height: 1.6;
    }
    .rp-notes li::before {
        content: '→';
        position: absolute;
        left: 0;
        top: 8px;
        color: #b98a1d;
        font-weight: 700;
    }
    .rp-notes b { color: #1c1b19; }

    /* ============ FAQ ============ */
    .rp-faq {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .rp-faq details {
        background: #fffdf9;
        border: 1px solid #e8e0d0;
        border-radius: 12px;
        overflow: hidden;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .rp-faq details:hover { border-color: #d4c9b3; }
    .rp-faq details[open] {
        border-color: #a8461f;
        box-shadow: 0 6px 20px rgba(168,70,31,0.06);
    }
    .rp-faq summary {
        cursor: pointer;
        list-style: none;
        padding: 20px 26px;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.02rem;
        font-weight: 500;
        color: #1c1b19;
        display: flex;
        align-items: center;
        gap: 14px;
        position: relative;
        transition: color 0.2s;
    }
    .rp-faq summary::-webkit-details-marker { display: none; }
    .rp-faq summary::before {
        content: '?';
        font-family: 'Fraunces', Georgia, serif;
        font-style: italic;
        font-size: 1.3rem;
        color: #a8461f;
        flex-shrink: 0;
        width: 24px;
        text-align: center;
    }
    .rp-faq summary::after {
        content: '+';
        margin-left: auto;
        font-family: 'Inter', sans-serif;
        font-size: 1.5rem;
        font-weight: 300;
        color: #8a8377;
        transition: transform 0.25s;
        flex-shrink: 0;
    }
    .rp-faq details[open] summary::after {
        content: '−';
        color: #a8461f;
    }
    .rp-faq details[open] summary { color: #a8461f; }
    .rp-faq__answer {
        padding: 0 26px 22px 60px;
        font-size: 0.9rem;
        color: #4b5563;
        line-height: 1.75;
    }
    .rp-faq__answer b { color: #1c1b19; }
    .rp-faq__answer i { color: #a8461f; }

    /* ============ CONTACT ============ */
    .rp-contact {
        background: #1c1b19;
        color: #fffdf9;
        border-radius: 16px;
        padding: 44px 48px;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 32px;
        align-items: center;
        margin-bottom: 30px;
    }
    .rp-contact__title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.6rem;
        font-weight: 400;
        margin: 0 0 8px;
        letter-spacing: -0.01em;
    }
    .rp-contact__title em {
        font-style: italic;
        color: #b98a1d;
    }
    .rp-contact__sub {
        font-size: 0.9rem;
        color: rgba(255,253,249,0.75);
        margin: 0 0 18px;
        line-height: 1.6;
    }
    .rp-contact__info {
        display: flex;
        gap: 24px;
        flex-wrap: wrap;
    }
    .rp-contact__item {
        font-size: 0.85rem;
        color: rgba(255,253,249,0.85);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .rp-contact__item b { color: #b98a1d; }

    /* ============ CTA ============ */
    .rp-cta {
        text-align: center;
        padding: 40px 0 80px;
    }
    .rp-cta__btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 16px 40px;
        background: #a8461f;
        color: #fffdf9;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.25s;
        box-shadow: 0 4px 16px rgba(168,70,31,0.25);
    }
    .rp-cta__btn:hover {
        background: #8f3a18;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(168,70,31,0.35);
    }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 900px) {
        .rp-stats { grid-template-columns: repeat(2, 1fr); }
        .rp-stat:nth-child(2) { border-right: none; }
        .rp-stat:nth-child(1),
        .rp-stat:nth-child(2) { border-bottom: 1px solid #e8e0d0; }
        .rp-steps { grid-template-columns: repeat(2, 1fr); }
        .rp-cols, .rp-methods { grid-template-columns: 1fr; }
        .rp-contact { grid-template-columns: 1fr; padding: 32px 28px; }
    }
    @media (max-width: 600px) {
        .rp-hero { padding: 40px 0 30px; }
        .rp-section { margin-bottom: 50px; }
        .rp-steps { grid-template-columns: 1fr; }
        .rp-faq summary { padding: 16px 20px; font-size: 0.95rem; }
        .rp-faq__answer { padding: 0 20px 18px 48px; font-size: 0.85rem; }
        .rp-panel, .rp-method, .rp-notes { padding: 22px 22px 18px; }
        .rp-notes { padding-left: 24px; }
    }
</style>

<main class="rp-page">

    <!-- ============ HERO ============ -->
    <section class="rp-hero">
        <div class="rp-wrap">
            <span class="rp-hero__label">THE ATELIER · Chính sách</span>
            <h1><?php
                // Cho phép highlight từ cuối bằng italic
                $words = explode(' ', $POLICY['hero_title']);
                $last  = array_pop($words);
                echo htmlspecialchars(implode(' ', $words)) . ' <em>' . htmlspecialchars($last) . '</em>';
            ?></h1>
            <p class="rp-hero__sub"><?php echo htmlspecialchars($POLICY['hero_subtitle']); ?></p>
            <p class="rp-hero__desc"><?php echo htmlspecialchars($POLICY['hero_desc']); ?></p>
            <span class="rp-hero__updated">Cập nhật lần cuối: <?php echo htmlspecialchars($POLICY['updated_at']); ?></span>
        </div>
    </section>

    <!-- ============ STATS ============ -->
    <div class="rp-wrap">
        <div class="rp-stats">
            <div class="rp-stat">
                <div class="rp-stat__num"><?php echo (int)$POLICY['return_days']; ?><small> ngày</small></div>
                <div class="rp-stat__label">Thời hạn đổi trả</div>
            </div>
            <div class="rp-stat">
                <div class="rp-stat__num"><?php echo (int)$POLICY['process_hours']; ?><small> giờ</small></div>
                <div class="rp-stat__label">Xử lý yêu cầu</div>
            </div>
            <div class="rp-stat">
                <div class="rp-stat__num"><?php echo htmlspecialchars($POLICY['refund_days']); ?><small> ngày</small></div>
                <div class="rp-stat__label">Hoàn tiền qua bank</div>
            </div>
            <div class="rp-stat">
                <div class="rp-stat__num">0<small>đ</small></div>
                <div class="rp-stat__label">Phí khi lỗi từ shop</div>
            </div>
        </div>
    </div>

    <!-- ============ SECTION 1: ĐIỀU KIỆN ============ -->
    <section class="rp-section">
        <div class="rp-wrap">
            <div class="rp-section__head">
                <span class="rp-section__num">01</span>
                <h2 class="rp-section__title">Điều kiện đổi trả</h2>
            </div>

            <div class="rp-cols">
                <div class="rp-panel rp-panel--yes">
                    <div class="rp-panel__header">
                        <span class="rp-panel__icon">✓</span>
                        Được đổi trả
                    </div>
                    <ul class="rp-list">
                        <?php foreach ($POLICY['eligibility'] as $item): ?>
                            <li><?php echo htmlspecialchars($item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="rp-panel rp-panel--no">
                    <div class="rp-panel__header">
                        <span class="rp-panel__icon">✕</span>
                        Không áp dụng
                    </div>
                    <ul class="rp-list">
                        <?php foreach ($POLICY['not_eligible'] as $item): ?>
                            <li><?php echo htmlspecialchars($item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ SECTION 2: QUY TRÌNH ============ -->
    <section class="rp-section">
        <div class="rp-wrap">
            <div class="rp-section__head">
                <span class="rp-section__num">02</span>
                <h2 class="rp-section__title">Quy trình 4 bước</h2>
            </div>

            <div class="rp-steps">
                <?php foreach ($POLICY['steps'] as $step): ?>
                    <div class="rp-step">
                        <div class="rp-step__num"><?php echo htmlspecialchars($step['num']); ?></div>
                        <h3 class="rp-step__title"><?php echo htmlspecialchars($step['title']); ?></h3>
                        <p class="rp-step__desc"><?php echo $step['desc']; /* cho phép HTML */ ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ SECTION 3: PHƯƠNG THỨC HOÀN TIỀN ============ -->
    <section class="rp-section">
        <div class="rp-wrap">
            <div class="rp-section__head">
                <span class="rp-section__num">03</span>
                <h2 class="rp-section__title">Phương thức hoàn tiền</h2>
            </div>

            <div class="rp-methods">
                <?php foreach ($POLICY['refund_methods'] as $m): ?>
                    <div class="rp-method <?php echo !empty($m['best']) ? 'rp-method--best' : ''; ?>">
                        <?php if (!empty($m['best'])): ?>
                            <span class="rp-method__best">Khuyến nghị</span>
                        <?php endif; ?>
                        <div class="rp-method__head">
                            <div class="rp-method__icon"><?php echo $m['icon']; ?></div>
                            <div>
                                <h3 class="rp-method__name"><?php echo htmlspecialchars($m['name']); ?></h3>
                                <div class="rp-method__time">⏱ <?php echo htmlspecialchars($m['time']); ?></div>
                            </div>
                        </div>
                        <p class="rp-method__desc"><?php echo htmlspecialchars($m['desc']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ SECTION 4: LƯU Ý ============ -->
    <section class="rp-section">
        <div class="rp-wrap">
            <div class="rp-section__head">
                <span class="rp-section__num">04</span>
                <h2 class="rp-section__title">Lưu ý quan trọng</h2>
            </div>

            <div class="rp-notes">
                <div class="rp-notes__head">⚠️ Vui lòng đọc kỹ trước khi yêu cầu</div>
                <ul>
                    <?php foreach ($POLICY['notes'] as $n): ?>
                        <li><?php echo $n; /* cho phép HTML */ ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <!-- ============ SECTION 5: FAQ ============ -->
    <section class="rp-section">
        <div class="rp-wrap">
            <div class="rp-section__head">
                <span class="rp-section__num">05</span>
                <h2 class="rp-section__title">Câu hỏi thường gặp</h2>
            </div>

            <div class="rp-faq">
                <?php foreach ($POLICY['faq'] as $i => $item): ?>
                    <details <?php echo $i === 0 ? 'open' : ''; ?>>
                        <summary><?php echo htmlspecialchars($item['q']); ?></summary>
                        <div class="rp-faq__answer"><?php echo $item['a']; /* cho phép HTML */ ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ CONTACT ============ -->
    <div class="rp-wrap">
        <div class="rp-contact">
            <div>
                <h3 class="rp-contact__title">Vẫn còn <em>thắc mắc</em>?</h3>
                <p class="rp-contact__sub">Đội ngũ CSKH của THE ATELIER luôn sẵn sàng hỗ trợ bạn 24/7.</p>
                <div class="rp-contact__info">
                    <div class="rp-contact__item">📞 Hotline: <b><?php echo htmlspecialchars($POLICY['contact']['hotline']); ?></b></div>
                    <div class="rp-contact__item">📧 Email: <b><?php echo htmlspecialchars($POLICY['contact']['email']); ?></b></div>
                    <div class="rp-contact__item">🕐 <?php echo htmlspecialchars($POLICY['contact']['hours']); ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <a href="contact.php" style="display:inline-flex;align-items:center;gap:8px;padding:14px 28px;background:transparent;color:#fffdf9;border:1px solid rgba(255,253,249,0.3);border-radius:6px;font-family:'IBM Plex Mono',monospace;font-size:0.78rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;text-decoration:none;transition:all 0.2s;"
                   onmouseover="this.style.background='rgba(255,253,249,0.1)';this.style.borderColor='rgba(255,253,249,0.5)';"
                   onmouseout="this.style.background='transparent';this.style.borderColor='rgba(255,253,249,0.3)';">
                    Liên hệ ngay →
                </a>
            </div>
        </div>
    </div>

    <!-- ============ CTA ============ -->
    <div class="rp-cta">
        <a href="<?php echo htmlspecialchars($ctaUrl); ?>" class="rp-cta__btn">
            <?php echo htmlspecialchars($ctaText); ?>
        </a>
    </div>

</main>

<?php include 'includes/footer.php'; ?>