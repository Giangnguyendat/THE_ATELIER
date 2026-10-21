<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    header('Location: login.php?redirect=payment.php');
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: account.php');
    exit;
}

// Lấy đơn + bank đã liên kết
$stmt = $pdo->prepare("
    SELECT o.*, 
           lb.bank_bin, lb.bank_name AS lb_bank_name, lb.account_no, lb.account_name, lb.is_default
    FROM orders o
    LEFT JOIN linked_banks lb ON lb.id = o.linked_bank_id
    WHERE o.id = ? AND o.user_id = ?
    LIMIT 1
");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Không tìm thấy đơn hàng.'];
    header('Location: account.php');
    exit;
}
if (in_array($order['payment_status'], ['paid', 'refund_pending'], true)) {
    $_SESSION['flash'] = ['type' => 'info', 'msg' => 'Đơn hàng này đã được thanh toán rồi.'];
    header('Location: account.php');
    exit;
}
if ($order['status'] === 'cancelled') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Đơn hàng đã hủy — không thể thanh toán.'];
    header('Location: account.php');
    exit;
}
if ($order['payment_method'] !== 'bank') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Phương thức thanh toán không hỗ trợ.'];
    header('Location: account.php');
    exit;
}

$total = (float)$order['total_amount'];

// Bank info
$banks = require 'includes/banks.php';
$bankBin = $order['bank_bin'] ?? '';
$bankInfo = $banks[$bankBin] ?? [
    'name' => $order['lb_bank_name'] ?? 'Ngân hàng',
    'logo' => '',
    'color' => '#1c1b19',
];

// Mask account number
$acct = $order['account_no'] ?? '';
$len  = mb_strlen($acct, 'UTF-8');
if ($len > 4) {
    $masked = str_repeat('•', $len - 4) . mb_substr($acct, -4, null, 'UTF-8');
} else {
    $masked = $acct;
}
$chars  = preg_split('//u', $masked, -1, PREG_SPLIT_NO_EMPTY);
$groups = array_chunk($chars, 4);
$acctMask = implode(' ', array_map(fn($g) => implode('', $g), $groups));

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$hasPin = !empty($pdo->query("SELECT payment_pin FROM users WHERE id = " . (int)$userId)->fetchColumn());
?>
<?php include 'includes/header.php'; ?>

<style>
.pay-page-wrap {
    background: #faf7f1;
    min-height: 100vh;
    padding: 40px 20px 60px;
}
.pay-container { max-width: 880px; margin: 0 auto; }

.pay-page-head { text-align: center; margin-bottom: 36px; }
.pay-page-head .eyebrow {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .72rem;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: #a8461f;
    margin-bottom: 8px;
    display: block;
}
.pay-page-head h1 {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 2.4rem;
    font-style: italic;
    font-weight: 400;
    color: #1c1b19;
    margin: 0 0 8px;
    letter-spacing: -0.02em;
}
.pay-page-head p { color: #8a8377; font-size: .95rem; margin: 0; }

.pay-card {
    background: #fffdf9;
    border: 1px solid #e5dfd2;
    border-radius: 14px;
    padding: 26px 28px;
    margin-bottom: 20px;
}
.pay-card__title {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.15rem;
    color: #1c1b19;
    margin: 0 0 18px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

/* Bank card preview */
.bank-display {
    position: relative;
    background: linear-gradient(135deg, #1c1b19 0%, #38342c 100%);
    color: #f2ece0;
    border-radius: 14px;
    padding: 22px 24px;
    overflow: hidden;
    aspect-ratio: 1.65 / 1;
    max-height: 200px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 12px 28px rgba(28, 27, 25, 0.28);
    isolation: isolate;
}
.bank-display__bg {
    position: absolute;
    inset: 0;
    z-index: -2;
}
.bank-display__bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 85% 15%, rgba(255,255,255,0.14), transparent 45%),
        radial-gradient(circle at 15% 85%, rgba(0,0,0,0.25), transparent 55%);
}
.bank-display__logo-big {
    position: absolute;
    top: 50%; right: -30px;
    transform: translateY(-50%);
    width: 55%;
    max-width: 200px;
    opacity: 0.22;
    filter: brightness(0) invert(1) drop-shadow(0 2px 8px rgba(0,0,0,0.3));
    pointer-events: none;
    z-index: -1;
}
.bank-display__brand {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
    z-index: 1;
}
.bank-display__brand img {
    height: 22px;
    filter: brightness(0) invert(1) drop-shadow(0 1px 3px rgba(0,0,0,0.25));
}
.bank-display__brand span {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.1rem;
    font-weight: 500;
    text-shadow: 0 1px 4px rgba(0,0,0,0.3);
}
.bank-display__tag {
    position: absolute;
    top: 20px; right: 22px;
    padding: 4px 10px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .62rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    background: #b98a1d;
    color: #1c1b19;
    border-radius: 999px;
    font-weight: 700;
    z-index: 2;
}
.bank-display__chip {
    width: 40px; height: 30px;
    background: linear-gradient(135deg, #e8d8a0 0%, #b98a1d 100%);
    border-radius: 6px;
    position: relative;
    margin-bottom: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    z-index: 1;
}
.bank-display__chip::before,
.bank-display__chip::after {
    content: '';
    position: absolute;
    left: 7px; right: 7px;
    height: 1px;
    background: rgba(0,0,0,0.28);
}
.bank-display__chip::before { top: 10px; }
.bank-display__chip::after  { bottom: 10px; }
.bank-display__number {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.12rem;
    letter-spacing: 0.14em;
    margin-bottom: 10px;
    z-index: 1;
    position: relative;
    text-shadow: 0 1px 4px rgba(0,0,0,0.35);
    word-spacing: 0.4em;
}
.bank-display__holder {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .7rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.85;
    z-index: 1;
    position: relative;
    text-shadow: 0 1px 3px rgba(0,0,0,0.35);
}

/* Order summary */
.order-line {
    display: flex;
    justify-content: space-between;
    padding: 9px 0;
    font-size: .92rem;
    color: #4b5563;
    border-bottom: 1px dashed #eee;
}
.order-line:last-of-type { border-bottom: none; }
.order-line strong { color: #1c1b19; }

.order-total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding-top: 16px;
    margin-top: 10px;
    border-top: 2px solid #e5dfd2;
}
.order-total span {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #8a8377;
    font-family: 'IBM Plex Mono', monospace;
}
.order-total b {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.9rem;
    color: #a8461f;
    font-weight: 500;
}

.btn-pay-submit {
    width: 100%;
    padding: 16px;
    font-size: 1rem;
    font-weight: 700;
    font-family: 'Inter', sans-serif;
    letter-spacing: 0.02em;
    background: #a8461f;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all .18s;
    box-shadow: 0 4px 14px rgba(168, 70, 31, 0.28);
    margin-top: 8px;
}
.btn-pay-submit:hover:not(:disabled) {
    background: #8f3a18;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(168, 70, 31, 0.38);
}
.btn-pay-submit:disabled { opacity: .5; cursor: not-allowed; }

.btn-back {
    display: block;
    text-align: center;
    margin-top: 14px;
    color: #8a8377;
    font-size: .88rem;
    text-decoration: none;
}
.btn-back:hover { color: #a8461f; }

.pay-alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: .88rem;
    margin-bottom: 16px;
    display: none;
}
.pay-alert.show { display: block; }
.pay-alert.error {
    background: #fef2f2;
    color: #991b1b;
    border-left: 4px solid #b91c1c;
}

.pay-note {
    background: #faf7f1;
    border: 1px solid #e5dfd2;
    border-radius: 10px;
    padding: 12px 16px;
    margin-top: 14px;
    font-size: .82rem;
    color: #8a8377;
    font-style: italic;
    display: flex;
    gap: 8px;
    align-items: flex-start;
}
.pay-note svg { flex-shrink: 0; margin-top: 2px; }

/* MODAL */
.pay-modal {
    position: fixed; inset: 0;
    background: rgba(28, 27, 25, 0.65);
    backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.pay-modal.show { display: flex; animation: payFade .2s; }
@keyframes payFade { from { opacity: 0; } to { opacity: 1; } }

.pay-modal__box {
    background: #fffdf9;
    border-radius: 18px;
    width: 100%;
    max-width: 440px;
    padding: 34px 34px 30px;
    position: relative;
    box-shadow: 0 30px 80px rgba(28, 27, 25, 0.5);
    animation: paySlide .28s cubic-bezier(0.4, 0, 0.2, 1);
    text-align: center;
}
@keyframes paySlide {
    from { opacity: 0; transform: translateY(20px) scale(.96); }
    to   { opacity: 1; transform: none; }
}
.pay-modal__close {
    position: absolute;
    top: 14px; right: 14px;
    width: 34px; height: 34px;
    border-radius: 50%;
    border: none;
    background: #f2ece0;
    color: #1c1b19;
    font-size: 1.3rem;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
}
.pay-modal__close:hover { background: #e8e0d0; transform: rotate(90deg); }

.pay-modal__icon {
    font-size: 2.6rem;
    margin-bottom: 14px;
}
.pay-modal__title {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.55rem;
    font-style: italic;
    color: #1c1b19;
    margin: 0 0 8px;
    font-weight: 500;
}
.pay-modal__sub {
    font-size: .88rem;
    color: #8a8377;
    margin: 0 0 22px;
    line-height: 1.5;
}
.pay-modal__sub strong { color: #1c1b19; font-family: 'IBM Plex Mono', monospace; }

/* OTP boxes */
.pay-otp-inputs {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-bottom: 18px;
}
.pay-otp-inputs input {
    width: 46px; height: 56px;
    text-align: center;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.4rem;
    font-weight: 700;
    background: #faf7f1;
    border: 1.5px solid #e5dfd2;
    border-radius: 10px;
    outline: none;
    transition: all .15s;
    color: #1c1b19;
}
.pay-otp-inputs input:focus {
    border-color: #a8461f;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
}
.pay-otp-inputs input.filled {
    border-color: #a8461f;
    background: #fff8f4;
}
.pay-otp-inputs input.error {
    border-color: #b91c1c;
    background: #fef2f2;
    animation: payShake .35s;
}
@keyframes payShake {
    0%,100% { transform: translateX(0); }
    25%     { transform: translateX(-5px); }
    75%     { transform: translateX(5px); }
}

.pay-modal .btn-otp-confirm {
    width: 100%;
    padding: 15px;
    font-size: .95rem;
    font-weight: 700;
    background: #a8461f;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all .18s;
}
.pay-modal .btn-otp-confirm:hover:not(:disabled) { background: #8f3a18; }
.pay-modal .btn-otp-confirm:disabled { opacity: .5; cursor: not-allowed; }

.pay-modal__err {
    color: #b91c1c;
    font-size: .82rem;
    margin: 12px 0 0;
    min-height: 1.1em;
    font-family: 'IBM Plex Mono', monospace;
}

/* Processing */
.pay-processing {
    padding: 20px 0 10px;
}
.pay-spinner {
    width: 76px;
    height: 76px;
    margin: 0 auto 24px;
    border-radius: 50%;
    border: 4px solid #f2ece0;
    border-top-color: #a8461f;
    border-right-color: #b98a1d;
    animation: paySpin 0.9s linear infinite;
    position: relative;
}
.pay-spinner::after {
    content: '';
    position: absolute;
    inset: 10px;
    border-radius: 50%;
    border: 3px solid transparent;
    border-bottom-color: rgba(168, 70, 31, 0.35);
    animation: paySpin 1.4s linear infinite reverse;
}
@keyframes paySpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.pay-processing h3 {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.35rem;
    font-style: italic;
    color: #1c1b19;
    margin: 0 0 8px;
    font-weight: 500;
}
.pay-processing p { color: #8a8377; font-size: .86rem; margin: 0; }

/* Success */
.pay-success-icon {
    width: 88px; height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.6rem;
    font-weight: 700;
    margin: 8px auto 22px;
    box-shadow:
        0 0 0 8px rgba(16, 185, 129, 0.1),
        0 8px 20px rgba(16, 185, 129, 0.2);
    animation: payPop .4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes payPop {
    from { transform: scale(0); }
    to   { transform: scale(1); }
}
.pay-success h2 {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.6rem;
    font-style: italic;
    color: #1c1b19;
    margin: 0 0 10px;
    font-weight: 500;
}
.pay-success p { color: #57534e; font-size: .9rem; line-height: 1.6; margin: 0 0 24px; }
.pay-success .amount { color: #a8461f; font-family: 'IBM Plex Mono', monospace; font-weight: 700; font-size: 1.1rem; }

@media (max-width: 640px) {
    .pay-card { padding: 20px; }
    .pay-otp-inputs input { width: 42px; height: 50px; font-size: 1.2rem; }
}
</style>

<main class="pay-page-wrap">
    <div class="pay-container">

        <div class="pay-page-head">
            <span class="eyebrow">Thanh toán đơn hàng</span>
            <h1>Đơn #<?php echo (int)$order['id']; ?></h1>
            <p>Xác nhận thanh toán để đơn hàng được xử lý</p>
        </div>

        <div class="pay-alert error" id="payAlert"></div>

        <div style="display:grid; grid-template-columns:1fr; gap:20px; max-width:640px; margin:0 auto;">

            <!-- BANK DISPLAY -->
            <div class="pay-card">
                <h2 class="pay-card__title">🏦 Tài khoản thanh toán</h2>

                <div class="bank-display">
                    <div class="bank-display__bg" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($bankInfo['color']); ?> 0%, <?php
                        $c = ltrim($bankInfo['color'], '#');
                        if (strlen($c) === 3) $c = $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
                        $num = hexdec($c);
                        $r = max(0, min(255, (($num >> 16) & 0xFF) - 30));
                        $g = max(0, min(255, (($num >> 8) & 0xFF) - 30));
                        $b = max(0, min(255, ($num & 0xFF) - 30));
                        printf('#%02x%02x%02x', $r, $g, $b);
                    ?> 100%);"></div>

                    <?php if (!empty($bankInfo['logo'])): ?>
                        <img src="<?php echo htmlspecialchars($bankInfo['logo']); ?>"
                             class="bank-display__logo-big" onerror="this.style.display='none'">
                    <?php endif; ?>

                    <?php if (!empty($order['is_default'])): ?>
                        <span class="bank-display__tag">Mặc định</span>
                    <?php endif; ?>

                    <div class="bank-display__brand">
                        <?php if (!empty($bankInfo['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($bankInfo['logo']); ?>" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($bankInfo['name']); ?></span>
                    </div>

                    <div>
                        <div class="bank-display__chip"></div>
                        <div class="bank-display__number"><?php echo htmlspecialchars($acctMask); ?></div>
                        <div class="bank-display__holder"><?php echo htmlspecialchars($order['account_name'] ?? ''); ?></div>
                    </div>
                </div>

                <div class="pay-note">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                    <span>Số tiền sẽ được <b>trừ trực tiếp</b> từ tài khoản ngân hàng này sau khi bạn xác nhận PIN và OTP.</span>
                </div>
            </div>

            <!-- ORDER SUMMARY -->
            <div class="pay-card">
                <h2 class="pay-card__title">📋 Chi tiết đơn hàng</h2>

                <div class="order-line">
                    <span>Mã đơn</span>
                    <strong>#<?php echo (int)$order['id']; ?></strong>
                </div>
                <div class="order-line">
                    <span>Ngày đặt</span>
                    <strong><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></strong>
                </div>
                <div class="order-line">
                    <span>Người nhận</span>
                    <strong><?php echo htmlspecialchars($order['full_name']); ?></strong>
                </div>
                <div class="order-line">
                    <span>SĐT</span>
                    <strong><?php echo htmlspecialchars($order['phone']); ?></strong>
                </div>

                <div class="order-total">
                    <span>Cần thanh toán</span>
                    <b><?php echo number_format($total, 0, ',', '.'); ?>đ</b>
                </div>

                <button type="button" class="btn-pay-submit" id="btnConfirmPay" style="margin-top:20px;">
                    💳 Xác nhận thanh toán
                </button>

                <a href="account.php" class="btn-back">← Quay lại đơn hàng</a>
            </div>

        </div>
    </div>
</main>

<!-- MODAL: PIN -->
<div class="pay-modal" id="pinModal">
    <div class="pay-modal__box">
        <button type="button" class="pay-modal__close" onclick="closePin()">×</button>
        <div class="pay-modal__icon" id="pinIcon">🔐</div>
        <h2 class="pay-modal__title" id="pinTitle">Xác minh mã PIN</h2>
        <p class="pay-modal__sub" id="pinSub">Nhập mã PIN 6 số để xác nhận thanh toán</p>

        <div id="pinVerifyBlock">
            <div class="pay-otp-inputs" id="pinInputsVerify">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
            </div>
        </div>

        <div id="pinSetupBlock" hidden>
            <p style="text-align:center;font-size:.78rem;color:#8a8377;margin:0 0 8px;font-family:'IBM Plex Mono',monospace;">
                Nhập PIN 6 số
            </p>
            <div class="pay-otp-inputs" id="pinInputsSetup">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
            </div>
            <p style="text-align:center;font-size:.78rem;color:#8a8377;margin:12px 0 8px;font-family:'IBM Plex Mono',monospace;">
                Nhập lại PIN
            </p>
            <div class="pay-otp-inputs" id="pinInputsConfirm">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
                <input type="text" maxlength="1" inputmode="numeric">
            </div>
        </div>

        <button type="button" class="btn-otp-confirm" id="btnPinConfirm" disabled>XÁC NHẬN PIN</button>
        <p class="pay-modal__err" id="pinErr"></p>
    </div>
</div>

<!-- MODAL: OTP -->
<div class="pay-modal" id="otpModal">
    <div class="pay-modal__box">
        <button type="button" class="pay-modal__close" onclick="closeOtp()">×</button>
        <div class="pay-modal__icon">📧</div>
        <h2 class="pay-modal__title">Xác minh OTP</h2>
        <p class="pay-modal__sub">
            Mã OTP đã gửi tới email<br>
            <strong id="otpEmail">*******</strong>
        </p>

        <div class="pay-otp-inputs" id="otpInputs">
            <input type="text" maxlength="1" inputmode="numeric">
            <input type="text" maxlength="1" inputmode="numeric">
            <input type="text" maxlength="1" inputmode="numeric">
            <input type="text" maxlength="1" inputmode="numeric">
            <input type="text" maxlength="1" inputmode="numeric">
            <input type="text" maxlength="1" inputmode="numeric">
        </div>

        <button type="button" class="btn-otp-confirm" id="btnOtpConfirm" disabled>XÁC NHẬN OTP</button>
        <p class="pay-modal__err" id="otpErr"></p>
        <button type="button" id="btnResendOtp" style="background:none;border:none;color:#a8461f;text-decoration:underline;font-size:.82rem;margin-top:10px;cursor:pointer;padding:0;" disabled>
            Gửi lại (60s)
        </button>
    </div>
</div>

<!-- MODAL: PROCESSING + SUCCESS -->
<div class="pay-modal" id="resultModal">
    <div class="pay-modal__box">
        <!-- Processing -->
        <div id="procBlock" class="pay-processing">
            <div class="pay-spinner"></div>
            <h3 id="procTitle">Đang xử lý thanh toán...</h3>
            <p id="procSub">Vui lòng không đóng cửa sổ này</p>
        </div>

        <!-- Success -->
        <div id="successBlock" class="pay-success" style="display:none;">
            <div class="pay-success-icon">✓</div>
            <h2>Thanh toán thành công!</h2>
            <p>
                Đã trừ <span class="amount"><?php echo number_format($total, 0, ',', '.'); ?>đ</span> từ tài khoản<br>
                <b><?php echo htmlspecialchars($bankInfo['name']); ?> •••• <?php echo substr($order['account_no'] ?? '0000', -4); ?></b><br>
                <span style="font-size:.82rem;color:#8a8377;margin-top:8px;display:inline-block;">
                    Email xác nhận đã được gửi tới hộp thư của bạn
                </span>
            </p>
            <button type="button" class="btn-otp-confirm" onclick="location.href='account.php'">
                Về trang đơn hàng
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF = <?php echo json_encode($csrf); ?>;
    var ORDER_ID = <?php echo (int)$order['id']; ?>;
    var TOTAL = <?php echo json_encode($total); ?>;
    var HAS_PIN = <?php echo $hasPin ? 'true' : 'false'; ?>;

    /* ===== MODAL REFS ===== */
    var pinModal = document.getElementById('pinModal');
    var otpModal = document.getElementById('otpModal');
    var resultModal = document.getElementById('resultModal');
    var procBlock = document.getElementById('procBlock');
    var successBlock = document.getElementById('successBlock');

    var pinIcon = document.getElementById('pinIcon');
    var pinTitle = document.getElementById('pinTitle');
    var pinSub = document.getElementById('pinSub');
    var pinVerifyBlock = document.getElementById('pinVerifyBlock');
    var pinSetupBlock = document.getElementById('pinSetupBlock');
    var pinInputsVerify = document.querySelectorAll('#pinInputsVerify input');
    var pinInputsSetup = document.querySelectorAll('#pinInputsSetup input');
    var pinInputsConfirm = document.querySelectorAll('#pinInputsConfirm input');
    var btnPinConfirm = document.getElementById('btnPinConfirm');
    var pinErr = document.getElementById('pinErr');

    var otpInputs = document.querySelectorAll('#otpInputs input');
    var otpEmailEl = document.getElementById('otpEmail');
    var btnOtpConfirm = document.getElementById('btnOtpConfirm');
    var otpErr = document.getElementById('otpErr');
    var btnResendOtp = document.getElementById('btnResendOtp');

    var pinMode = 'verify';
    var pinVerified = false;

    /* ===== HELPERS ===== */
    function fmt(n) {
        return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ';
    }

    /* ===== ATTACH INPUT HANDLERS ===== */
    function attachOtpHandlers(inputs, nextGroup) {
        inputs.forEach(function (input, idx) {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '');
                this.classList.toggle('filled', !!this.value);
                if (this.value) {
                    if (idx < inputs.length - 1) inputs[idx + 1].focus();
                    else if (nextGroup) nextGroup[0].focus();
                }
                checkPinReady();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !this.value && idx > 0) inputs[idx - 1].focus();
            });
            input.addEventListener('paste', function (e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                if (!pasted) return;
                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].value = pasted[i] || '';
                    inputs[i].classList.toggle('filled', !!pasted[i]);
                }
                inputs[Math.min(pasted.length, inputs.length - 1)].focus();
                checkPinReady();
            });
        });
    }

    attachOtpHandlers(pinInputsVerify);
    attachOtpHandlers(pinInputsSetup, pinInputsConfirm);
    attachOtpHandlers(pinInputsConfirm);

    attachOtpHandlers(otpInputs);

    function getVal(inputs) {
        return Array.from(inputs).map(function (i) { return i.value; }).join('');
    }
    function clearInputs(inputs) {
        inputs.forEach(function (i) { i.value = ''; i.classList.remove('filled', 'error'); });
    }

    function checkPinReady() {
        if (pinMode === 'verify') {
            btnPinConfirm.disabled = (getVal(pinInputsVerify).length !== 6);
        } else {
            btnPinConfirm.disabled = !(getVal(pinInputsSetup).length === 6 && getVal(pinInputsConfirm).length === 6);
        }
    }

    function checkOtpReady() {
        btnOtpConfirm.disabled = (getVal(otpInputs).length !== 6);
    }

    otpInputs.forEach(function (input) {
        input.addEventListener('input', checkOtpReady);
    });

    /* ===== OPEN/CLOSE MODAL ===== */
    function openPinModal() {
        pinErr.textContent = '';
        clearInputs(pinInputsVerify);
        clearInputs(pinInputsSetup);
        clearInputs(pinInputsConfirm);

        // Check if user has PIN
        fetch('ajax/pin_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=status'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var hasPin = data.has_pin === true;
            pinMode = hasPin ? 'verify' : 'setup';

            if (pinMode === 'verify') {
                pinIcon.textContent = '🔐';
                pinTitle.textContent = 'Xác minh mã PIN';
                pinSub.textContent = 'Nhập mã PIN 6 số để xác nhận thanh toán';
                pinVerifyBlock.hidden = false;
                pinSetupBlock.hidden = true;
                setTimeout(function () { pinInputsVerify[0].focus(); }, 300);
            } else {
                pinIcon.textContent = '🆕';
                pinTitle.textContent = 'Thiết lập mã PIN';
                pinSub.textContent = 'Lần đầu thanh toán. Vui lòng tạo mã PIN 6 số.';
                pinVerifyBlock.hidden = true;
                pinSetupBlock.hidden = false;
                setTimeout(function () { pinInputsSetup[0].focus(); }, 300);
            }
            checkPinReady();
        })
        .catch(function () {
            pinErr.textContent = 'Không thể kiểm tra PIN.';
        });

        pinModal.classList.add('show');
    }

    window.closePin = function () { pinModal.classList.remove('show'); };
    window.closeOtp = function () { otpModal.classList.remove('show'); };

    /* ===== MAIN BUTTON ===== */
    document.getElementById('btnConfirmPay').addEventListener('click', function () {
        if (!HAS_PIN) {
            alert('Vui lòng thiết lập mã PIN trước (vào trang Tài khoản).');
            return;
        }
        openPinModal();
    });

    /* ===== PIN CONFIRM ===== */
    btnPinConfirm.addEventListener('click', function () {
        var pin, pinConfirm = '', action;

        if (pinMode === 'verify') {
            pin = getVal(pinInputsVerify);
            action = 'verify';
        } else {
            pin = getVal(pinInputsSetup);
            pinConfirm = getVal(pinInputsConfirm);
            action = 'set';
        }

        btnPinConfirm.disabled = true;
        btnPinConfirm.textContent = 'Đang xử lý...';

        var body = 'action=' + action + '&pin=' + encodeURIComponent(pin);
        if (pinConfirm) body += '&pin_confirm=' + encodeURIComponent(pinConfirm);

        fetch('ajax/pin_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                pinVerified = true;
                closePin();
                sendOtp();
            } else {
                pinErr.textContent = data.msg || 'PIN không đúng.';
                (pinMode === 'verify' ? pinInputsVerify : pinInputsSetup).forEach(function (i) {
                    i.classList.add('error');
                    setTimeout(function () { i.classList.remove('error'); }, 400);
                });
                clearInputs(pinInputsVerify);
                clearInputs(pinInputsSetup);
                clearInputs(pinInputsConfirm);
                btnPinConfirm.disabled = true;
                btnPinConfirm.textContent = 'XÁC NHẬN PIN';
                setTimeout(function () {
                    if (pinMode === 'verify') pinInputsVerify[0].focus();
                    else pinInputsSetup[0].focus();
                }, 300);
            }
        })
        .catch(function () {
            pinErr.textContent = 'Không thể kết nối.';
            btnPinConfirm.disabled = false;
            btnPinConfirm.textContent = 'XÁC NHẬN PIN';
        });
    });

    /* ===== SEND OTP ===== */
    function sendOtp() {
        fetch('ajax/otp_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { alert(data.msg || 'Không gửi được OTP.'); return; }
            if (otpEmailEl) otpEmailEl.textContent = data.email_mask || '*******';
            clearInputs(otpInputs);
            btnOtpConfirm.disabled = true;
            otpErr.textContent = '';
            otpModal.classList.add('show');
            setTimeout(function () { otpInputs[0].focus(); }, 300);
            startResendCountdown(60);
        })
        .catch(function () { alert('Không thể gửi OTP.'); });
    }

    /* ===== OTP CONFIRM ===== */
    btnOtpConfirm.addEventListener('click', function () {
        var code = getVal(otpInputs);
        if (code.length !== 6) return;

        btnOtpConfirm.disabled = true;
        btnOtpConfirm.textContent = 'Đang xác minh...';

        fetch('ajax/otp_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=verify&code=' + encodeURIComponent(code)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeOtp();
                finalizePayment();
            } else {
                otpErr.textContent = data.msg || 'Mã không đúng.';
                otpInputs.forEach(function (i) {
                    i.classList.add('error');
                    setTimeout(function () { i.classList.remove('error'); }, 400);
                });
                btnOtpConfirm.disabled = false;
                btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
            }
        })
        .catch(function () {
            otpErr.textContent = 'Không thể kết nối.';
            btnOtpConfirm.disabled = false;
            btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
        });
    });

    /* ===== RESEND OTP ===== */
    var resendTimer = null;
    function startResendCountdown(seconds) {
        btnResendOtp.disabled = true;
        var left = seconds;
        btnResendOtp.textContent = 'Gửi lại (' + left + 's)';
        clearInterval(resendTimer);
        resendTimer = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(resendTimer);
                btnResendOtp.disabled = false;
                btnResendOtp.textContent = 'Gửi lại';
            } else {
                btnResendOtp.textContent = 'Gửi lại (' + left + 's)';
            }
        }, 1000);
    }

    btnResendOtp.addEventListener('click', function () {
        if (btnResendOtp.disabled) return;
        sendOtp();
    });

    /* ===== FINALIZE PAYMENT ===== */
    function finalizePayment() {
        // Show processing modal
        procBlock.style.display = '';
        successBlock.style.display = 'none';
        resultModal.classList.add('show');

        fetch('ajax/pay_order_confirm.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: ORDER_ID,
                csrf_token: CSRF
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                resultModal.classList.remove('show');
                alert(data.error || 'Không thể xử lý thanh toán.');
                return;
            }

            // Wait 3s → show success
            setTimeout(function () {
                procBlock.style.display = 'none';
                successBlock.style.display = '';
            }, 3000);
        })
        .catch(function () {
            resultModal.classList.remove('show');
            alert('Lỗi kết nối. Vui lòng thử lại.');
        });
    }

    /* Close modal when click outside */
    [pinModal, otpModal].forEach(function (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) m.classList.remove('show');
        });
    });

})();
</script>

<?php include 'includes/footer.php'; ?>