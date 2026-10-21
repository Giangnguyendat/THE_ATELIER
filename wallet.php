<?php
require_once 'includes/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=wallet.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$banks  = require 'includes/banks.php';
/**
 * Làm tối/sáng màu hex
 */
function shade_hex(string $hex, int $percent): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0] . $hex[1].$hex[1] . $hex[2].$hex[2];
    }
    $num = hexdec($hex);
    $r = max(0, min(255, (($num >> 16) & 0xFF) + $percent));
    $g = max(0, min(255, (($num >> 8) & 0xFF) + $percent));
    $b = max(0, min(255, ($num & 0xFF) + $percent));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
$stmt = $pdo->prepare("SELECT id, full_name, email, atelier_pay_balance, payment_pin FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) { header('Location: logout.php'); exit; }

$balance = (float)$user['atelier_pay_balance'];
$hasPin  = !empty($user['payment_pin']);

// Check đã unlock chưa (15 phút)
$walletUnlocked = !empty($_SESSION['wallet_unlocked_until']) && $_SESSION['wallet_unlocked_until'] > time();

$stmt = $pdo->prepare("SELECT * FROM linked_banks WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$stmt->execute([$userId]);
$linkedBanks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 30");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN type='topup' AND status='completed' THEN amount END), 0) AS total_topup,
        COALESCE(SUM(CASE WHEN type='payment' AND status='completed' THEN amount END), 0) AS total_payment,
        COALESCE(SUM(CASE WHEN type='withdraw' AND status='completed' THEN amount END), 0) AS total_withdraw,
        COUNT(CASE WHEN type='topup' AND status='completed' THEN 1 END) AS count_topup
    FROM wallet_transactions WHERE user_id = ?
");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<?php include 'includes/header.php'; ?>

<style>
/* =========================================================
   ATELIER PAY — WALLET
   ========================================================= */
:root {
    --pay-ink: #1c1b19;
    --pay-ivory: #f2ece0;
    --pay-rust: #a8461f;
    --pay-rust-deep: #7c3216;
    --pay-gold: #b98a1d;
    --pay-forest: #37423a;
    --pay-stone: #8a8377;
    --pay-white: #fffdf9;
    --pay-border: #e5dfd2;
}
.pay-page {
    background: #faf7f1;
    min-height: 100vh;
    padding: 40px 20px 60px;
}
.pay-wrap { max-width: 1080px; margin: 0 auto; }
.pay-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 28px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--pay-border);
}
.pay-head h1 {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 2.4rem;
    font-weight: 400;
    font-style: italic;
    letter-spacing: -0.02em;
    color: var(--pay-ink);
    margin: 0;
}
.pay-head .sub {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .78rem;
    color: var(--pay-stone);
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

/* ==== Balance Card ==== */
.pay-balance-card {
    position: relative;
    background: linear-gradient(135deg, #1c1b19 0%, #2b2823 100%);
    color: var(--pay-ivory);
    border-radius: 16px;
    padding: 36px 40px 32px;
    margin-bottom: 28px;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(28, 27, 25, 0.25);
}
.pay-balance-card::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 240px; height: 240px;
    background: radial-gradient(circle, rgba(185, 138, 29, 0.18), transparent 60%);
    pointer-events: none;
}
.pay-balance-card::after {
    content: '';
    position: absolute;
    bottom: -80px; left: -40px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(168, 70, 31, 0.15), transparent 60%);
    pointer-events: none;
}
.pay-balance-label {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .72rem;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: var(--pay-gold);
    margin-bottom: 12px;
    position: relative;
}
.pay-balance-value {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 3.4rem;
    font-weight: 400;
    letter-spacing: -0.03em;
    line-height: 1;
    margin-bottom: 22px;
    position: relative;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.pay-balance-value small {
    font-size: 1.1rem;
    color: var(--pay-stone);
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 400;
}
.pay-balance-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
}
.pay-balance-label {
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn-hide-balance {
    background: rgba(255, 253, 249, 0.12);
    border: 1px solid rgba(242, 236, 224, 0.2);
    color: var(--pay-gold);
    width: 26px;
    height: 26px;
    border-radius: 50%;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .18s;
    padding: 0;
}
.btn-hide-balance:hover {
    background: rgba(255, 253, 249, 0.2);
    border-color: rgba(242, 236, 224, 0.4);
}
.btn-hide-balance svg {
    width: 13px;
    height: 13px;
}

.balance-masked {
    letter-spacing: 0.15em;
    font-family: 'IBM Plex Mono', monospace;
    opacity: 0.85;
}

.btn-unlock-balance {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(242, 236, 224, 0.15);
    color: var(--pay-ivory);
    border: 1px solid rgba(242, 236, 224, 0.3);
    border-radius: 999px;
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .18s;
    margin-left: auto;
}
.btn-unlock-balance:hover {
    background: rgba(242, 236, 224, 0.25);
    border-color: rgba(242, 236, 224, 0.5);
    transform: translateY(-1px);
}
.btn-unlock-balance svg {
    width: 14px;
    height: 14px;
}
.pay-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    font-family: 'Inter', sans-serif;
    font-size: .88rem;
    font-weight: 500;
    border-radius: 8px;
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all .18s ease;
    line-height: 1;
}
.pay-btn--primary {
    background: var(--pay-rust);
    color: #fff;
    box-shadow: 0 4px 12px rgba(168, 70, 31, 0.3);
}
.pay-btn--primary:hover {
    background: var(--pay-rust-deep);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(168, 70, 31, 0.4);
}
.pay-btn--ghost {
    background: rgba(255, 253, 249, 0.08);
    color: var(--pay-ivory);
    border-color: rgba(242, 236, 224, 0.2);
}
.pay-btn--ghost:hover {
    background: rgba(255, 253, 249, 0.14);
    border-color: rgba(242, 236, 224, 0.4);
}
.pay-btn:disabled { opacity: .5; cursor: not-allowed; transform: none !important; }

/* ==== Stats ==== */
.pay-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}
.pay-stat {
    background: var(--pay-white);
    border: 1px solid var(--pay-border);
    border-radius: 12px;
    padding: 18px 20px;
}
.pay-stat-label {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .7rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--pay-stone);
    margin-bottom: 8px;
}
.pay-stat-value {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.5rem;
    color: var(--pay-ink);
    letter-spacing: -0.02em;
}

/* ==== Tabs ==== */
.pay-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--pay-border);
    margin-bottom: 20px;
}
.pay-tab {
    padding: 12px 20px;
    background: none;
    border: none;
    font-family: 'Inter', sans-serif;
    font-size: .9rem;
    font-weight: 500;
    color: var(--pay-stone);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all .15s;
}
.pay-tab.active { color: var(--pay-ink); border-bottom-color: var(--pay-rust); }
.pay-tab:hover:not(.active) { color: var(--pay-ink); }
.pay-panel { display: none; }
.pay-panel.active { display: block; animation: payFade .2s ease; }
@keyframes payFade {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: none; }
}

/* ==== Bank list ==== */
.pay-bank-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}
/* ==== Bank card with logo background ==== */
.pay-bank-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
}
.pay-bank-card {
    position: relative;
    aspect-ratio: 1.6 / 1;
    border-radius: 16px;
    padding: 22px 24px;
    color: #fff;
    overflow: hidden;
    box-shadow: 0 12px 32px rgba(28, 27, 25, 0.22);
    transition: transform .22s ease, box-shadow .22s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    isolation: isolate;
}
.pay-bank-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 40px rgba(28, 27, 25, 0.3);
}

/* Nền gradient + màu bank */
.pay-bank-card__bg {
    position: absolute;
    inset: 0;
    z-index: -2;
}
.pay-bank-card__bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 85% 15%, rgba(255, 255, 255, 0.14), transparent 45%),
        radial-gradient(circle at 15% 85%, rgba(0, 0, 0, 0.25), transparent 55%);
    pointer-events: none;
}

/* Logo ngân hàng — watermark to ở góc phải */
.pay-bank-card__logo {
    position: absolute;
    top: 50%;
    right: -30px;
    transform: translateY(-50%);
    width: 55%;
    max-width: 220px;
    height: auto;
    opacity: 0.22;
    filter: brightness(0) invert(1) drop-shadow(0 2px 8px rgba(0,0,0,0.3));
    pointer-events: none;
    z-index: -1;
    transition: opacity .25s ease;
}
.pay-bank-card:hover .pay-bank-card__logo {
    opacity: 0.3;
}

/* Logo nhỏ ở góc trên trái */
.pay-bank-card__brand {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
    z-index: 1;
}
.pay-bank-card__brand img {
    height: 22px;
    width: auto;
    filter: brightness(0) invert(1) drop-shadow(0 1px 3px rgba(0,0,0,0.25));
}
.pay-bank-card__brand span {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.1rem;
    font-weight: 500;
    letter-spacing: -0.01em;
    text-shadow: 0 1px 4px rgba(0,0,0,0.3);
}

/* Badge mặc định */
.pay-bank-card__tag {
    display: inline-block;
    padding: 4px 10px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .62rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    background: var(--pay-gold);
    color: #1c1b19;
    border-radius: 999px;
    font-weight: 700;
    position: absolute;
    top: 20px;
    right: 22px;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

/* Chip */
.pay-bank-card__chip {
    width: 40px;
    height: 30px;
    background: linear-gradient(135deg, #e8d8a0 0%, #b98a1d 100%);
    border-radius: 6px;
    position: relative;
    margin-bottom: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    z-index: 1;
}
.pay-bank-card__chip::before,
.pay-bank-card__chip::after {
    content: '';
    position: absolute;
    left: 7px; right: 7px;
    height: 1px;
    background: rgba(0,0,0,0.28);
}
.pay-bank-card__chip::before { top: 10px; }
.pay-bank-card__chip::after  { bottom: 10px; }

/* Số tài khoản */
.pay-bank-card__number {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.12rem;
    letter-spacing: 0.14em;
    margin-bottom: 10px;
    position: relative;
    z-index: 1;
    text-shadow: 0 1px 4px rgba(0,0,0,0.35);
    font-weight: 500;
    word-spacing: 0.4em;
}

/* Tên chủ TK */
.pay-bank-card__holder {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .7rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.85;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
    text-shadow: 0 1px 3px rgba(0,0,0,0.35);
}

/* Actions */
.pay-bank-card__actions {
    display: flex;
    gap: 8px;
    position: relative;
    z-index: 2;
    padding-top: 12px;
    border-top: 1px solid rgba(255, 255, 255, 0.15);
}
.pay-bank-card__actions button {
    padding: 7px 14px;
    font-size: .74rem;
    font-family: 'Inter', sans-serif;
    font-weight: 500;
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 7px;
    cursor: pointer;
    transition: all .15s;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}
.pay-bank-card__actions button:hover {
    background: rgba(255, 255, 255, 0.24);
    border-color: rgba(255, 255, 255, 0.45);
    transform: translateY(-1px);
}
.pay-bank-card__actions button.danger {
    color: #fecaca;
    border-color: rgba(252, 165, 165, 0.4);
}
.pay-bank-card__actions button.danger:hover {
    background: rgba(185, 28, 28, 0.35);
    color: #fff;
}

.pay-empty {
    padding: 48px 20px;
    text-align: center;
    color: var(--pay-stone);
    background: var(--pay-white);
    border: 1px dashed var(--pay-border);
    border-radius: 12px;
}
.pay-empty-icon { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5; }
/* ==== Locked states ==== */
.stat-masked {
    letter-spacing: 0.15em;
    font-family: 'IBM Plex Mono', monospace;
    color: var(--pay-stone);
    opacity: 0.7;
}

.pay-stats--locked .pay-stat {
    position: relative;
    transition: all .2s;
}
.pay-stats--locked .pay-stat:hover {
    border-color: var(--pay-rust);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(168, 70, 31, 0.1);
}

/* Transaction list locked */
.pay-tx-locked {
    position: relative;
    min-height: 320px;
}
.pay-tx-locked__overlay {
    position: absolute;
    inset: 0;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 40px 20px;
    background: linear-gradient(180deg,
        rgba(250, 247, 241, 0.85) 0%,
        rgba(250, 247, 241, 0.95) 50%,
        rgba(250, 247, 241, 0.98) 100%);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border-radius: 12px;
}
.pay-tx-locked__icon {
    width: 76px;
    height: 76px;
    border-radius: 50%;
    background: #fef2f2;
    color: #e11d48;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
    box-shadow: 0 8px 24px rgba(225, 29, 72, 0.15);
}
.pay-tx-locked__title {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.35rem;
    font-weight: 400;
    font-style: italic;
    color: var(--pay-ink);
    margin: 0 0 8px;
}
.pay-tx-locked__desc {
    font-size: .88rem;
    color: var(--pay-stone);
    margin: 0 0 22px;
    max-width: 360px;
}
.pay-tx-list--blur {
    filter: blur(6px);
    opacity: 0.55;
    pointer-events: none;
    user-select: none;
}

/* ==== Transactions ==== */
.pay-tx-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
    background: var(--pay-border);
    border: 1px solid var(--pay-border);
    border-radius: 12px;
    overflow: hidden;
}
.pay-tx-row {
    display: grid;
    grid-template-columns: 48px 1fr auto;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: var(--pay-white);
    transition: background .15s;
}
.pay-tx-row:hover { background: #fdfbf7; }
.pay-tx-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}
.pay-tx-icon.topup    { background: #ecfdf5; color: #166534; }
.pay-tx-icon.payment  { background: #fef2f2; color: #b91c1c; }
.pay-tx-icon.refund   { background: #eff6ff; color: #1d4ed8; }
.pay-tx-icon.withdraw { background: #fff7ed; color: #c2410c; }
.pay-tx-icon.adjust   { background: #f5f5f4; color: #57534e; }
.pay-tx-title {
    font-size: .92rem;
    color: var(--pay-ink);
    font-weight: 500;
    margin-bottom: 3px;
}
.pay-tx-meta {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .72rem;
    color: var(--pay-stone);
}
.pay-tx-amount {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1rem;
    font-weight: 600;
    text-align: right;
}
.pay-tx-amount.plus  { color: #166534; }
.pay-tx-amount.minus { color: #b91c1c; }
.pay-tx-amount.pending { color: #c2410c; font-style: italic; }
.pay-tx-balance {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .72rem;
    color: var(--pay-stone);
    text-align: right;
    margin-top: 3px;
}

/* ==== Modal ==== */
.pay-modal {
    position: fixed; inset: 0;
    background: rgba(28, 27, 25, 0.6);
    backdrop-filter: blur(6px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.pay-modal.show { display: flex; animation: payFade .2s; }
.pay-modal__box {
    background: #fffdf9;
    border-radius: 16px;
    width: 100%;
    max-width: 480px;
    max-height: 92vh;
    overflow-y: auto;
    padding: 32px 32px 28px;
    position: relative;
    box-shadow: 
        0 0 0 1px rgba(28, 27, 25, 0.06),
        0 30px 80px rgba(28, 27, 25, 0.45);
    animation: payModalSlide .25s cubic-bezier(0.4, 0, 0.2, 1);
}
.pay-modal__box--wide { max-width: 560px; }
@keyframes payModalSlide {
    from { opacity: 0; transform: translateY(20px) scale(.97); }
    to   { opacity: 1; transform: none; }
}
.pay-modal__close {
    position: absolute;
    top: 14px; right: 14px;
    width: 34px; height: 34px;
    border-radius: 50%;
    border: none;
    background: #f2ece0;
    color: var(--pay-ink);
    font-size: 1.3rem;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
}
.pay-modal__close:hover { background: #e8e0d0; transform: rotate(90deg); }
.pay-modal__title {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.6rem;
    font-weight: 400;
    font-style: italic;
    color: var(--pay-ink);
    margin: 0 0 6px;
    letter-spacing: -0.01em;
}
.pay-modal__sub {
    font-size: .85rem;
    color: var(--pay-stone);
    margin-bottom: 24px;
}

.pay-steps {
    display: flex;
    gap: 8px;
    margin-bottom: 22px;
}
.pay-step {
    flex: 1; height: 3px;
    background: var(--pay-border);
    border-radius: 999px;
    overflow: hidden;
    position: relative;
}
.pay-step.active::before {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--pay-rust);
    animation: payStepFill .3s ease;
}
@keyframes payStepFill {
    from { transform: translateX(-100%); }
    to   { transform: none; }
}

.pay-field { margin-bottom: 16px; }
.pay-field label {
    display: block;
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--pay-stone);
    margin-bottom: 8px;
    font-family: 'IBM Plex Mono', monospace;
}
.pay-field input,
.pay-field select {
    width: 100%;
    padding: 13px 14px;
    font-family: 'Inter', sans-serif;
    font-size: .95rem;
    color: var(--pay-ink);
    background: #faf7f1;
    border: 1px solid var(--pay-border);
    border-radius: 8px;
    outline: none;
    transition: all .15s;
}
.pay-field input:focus,
.pay-field select:focus {
    border-color: var(--pay-rust);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
}
.pay-field input:disabled {
    opacity: .6;
    cursor: not-allowed;
}
.pay-field .hint {
    display: block;
    font-size: .75rem;
    color: var(--pay-stone);
    margin-top: 6px;
    font-style: italic;
}
.pay-field input[type="number"] {
    font-family: 'IBM Plex Mono', monospace;
    letter-spacing: 0.02em;
}
.pay-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 480px) {
    .pay-field-row { grid-template-columns: 1fr; }
}

.pay-quick-amounts {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}
.pay-quick-amounts button {
    padding: 7px 14px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .78rem;
    background: #faf7f1;
    border: 1px solid var(--pay-border);
    border-radius: 999px;
    cursor: pointer;
    color: var(--pay-ink);
    transition: all .15s;
}
.pay-quick-amounts button:hover {
    background: var(--pay-rust);
    color: #fff;
    border-color: var(--pay-rust);
}

.pay-otp-inputs {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin: 8px 0 18px;
}
.pay-otp-inputs input {
    width: 44px; height: 54px;
    text-align: center;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.4rem;
    font-weight: 600;
    background: #faf7f1;
    border: 1.5px solid var(--pay-border);
    border-radius: 10px;
    outline: none;
    transition: all .15s;
    color: var(--pay-ink);
}
.pay-otp-inputs input:focus {
    border-color: var(--pay-rust);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
}
.pay-otp-inputs input.filled {
    border-color: var(--pay-rust);
    background: #fff8f4;
}

.pay-summary {
    background: #faf7f1;
    border: 1px solid var(--pay-border);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
}
.pay-summary-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: .88rem;
}
.pay-summary-row.total {
    padding-top: 12px;
    border-top: 1px dashed #c8bfa9;
    margin-top: 6px;
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.15rem;
}
.pay-summary-row.total b {
    color: var(--pay-rust);
    font-family: 'IBM Plex Mono', monospace;
}

.pay-submit {
    width: 100%;
    padding: 14px;
    font-family: 'Inter', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    background: var(--pay-rust);
    color: #fff;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all .18s;
    margin-top: 4px;
}
.pay-submit:hover:not(:disabled) {
    background: var(--pay-rust-deep);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(168, 70, 31, 0.32);
}
.pay-submit:disabled { opacity: .5; cursor: not-allowed; }
.pay-alert {
    padding: 11px 14px;
    border-radius: 8px;
    font-size: .85rem;
    margin-bottom: 14px;
    display: none;
    align-items: flex-start;
    gap: 8px;
}
.pay-alert.show { display: flex; }
.pay-alert.error {
    background: #fef2f2;
    color: #991b1b;
    border-left: 3px solid #b91c1c;
}
.pay-alert.success {
    background: #ecfdf5;
    color: #166534;
    border-left: 3px solid #166534;
}
.pay-resend {
    text-align: center;
    font-size: .8rem;
    color: var(--pay-stone);
    margin-top: 12px;
}
.pay-resend button {
    background: none;
    border: none;
    color: var(--pay-rust);
    text-decoration: underline;
    cursor: pointer;
    font-size: .8rem;
    padding: 0;
}
.pay-resend button:disabled {
    color: var(--pay-stone);
    text-decoration: none;
    cursor: not-allowed;
}
/* ==== Processing ==== */
.pay-processing {
    padding: 32px 0 20px;
    text-align: center;
}
.pay-spinner {
    width: 68px;
    height: 68px;
    margin: 0 auto;
    border-radius: 50%;
    border: 4px solid #f2ece0;
    border-top-color: var(--pay-rust);
    border-right-color: var(--pay-gold);
    animation: paySpin 0.9s linear infinite;
    position: relative;
}
.pay-spinner::after {
    content: '';
    position: absolute;
    inset: 8px;
    border-radius: 50%;
    border: 3px solid transparent;
    border-bottom-color: rgba(168, 70, 31, 0.35);
    animation: paySpin 1.4s linear infinite reverse;
}
@keyframes paySpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
#txProcessing .pay-modal__title {
    color: #1c1b19;
    font-size: 1.4rem;
    margin-bottom: 8px;
}
#txProcessing .pay-modal__sub {
    color: #8a8377;
    font-size: .85rem;
}
.pay-success {
    text-align: center;
    padding: 8px 0 4px;
}
.pay-success-icon {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.6rem;
    font-weight: 700;
    margin: 0 auto 22px;
    box-shadow: 
        0 0 0 8px rgba(16, 185, 129, 0.1),
        0 8px 20px rgba(16, 185, 129, 0.2);
    animation: paySuccessPop .4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.pay-success-icon.withdraw {
    background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
    color: #9a3412;
    box-shadow: 
        0 0 0 8px rgba(194, 65, 12, 0.1),
        0 8px 20px rgba(194, 65, 12, 0.2);
}
.pay-success .pay-modal__title {
    color: #1c1b19;
    font-size: 1.7rem;
    margin-bottom: 10px;
    text-align: center;
    font-style: italic;
}
.pay-success .pay-modal__sub {
    color: #57534e;
    font-size: .92rem;
    line-height: 1.6;
    margin-bottom: 26px;
}
.pay-success .pay-modal__sub strong {
    color: #a8461f;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.15rem;
    font-weight: 700;
    display: inline-block;
    margin: 0 2px;
}
.pay-success-icon.withdraw {
    background: #fff7ed;
    color: #c2410c;
}
@keyframes paySuccessPop {
    from { transform: scale(0); }
    to   { transform: scale(1); }
}

/* ==== BANK MODAL — CARD PREVIEW ==== */
.bank-preview {
    position: relative;
    background: linear-gradient(135deg, #1c1b19 0%, #38342c 100%);
    color: var(--pay-ivory);
    border-radius: 14px;
    padding: 22px 24px;
    margin-bottom: 22px;
    overflow: hidden;
    aspect-ratio: 1.6 / 1;
    max-height: 190px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 12px 28px rgba(28, 27, 25, 0.28);
    transition: background .3s ease;
}
.bank-preview::before {
    content: '';
    position: absolute;
    top: -40%; right: -20%;
    width: 80%; height: 140%;
    background: radial-gradient(circle, rgba(255,255,255,0.08), transparent 55%);
    pointer-events: none;
}
.bank-preview__top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: relative;
}
.bank-preview__chip {
    width: 38px; height: 28px;
    background: linear-gradient(135deg, #e8d8a0 0%, #b98a1d 100%);
    border-radius: 5px;
    position: relative;
}
.bank-preview__chip::before,
.bank-preview__chip::after {
    content: '';
    position: absolute;
    left: 7px; right: 7px;
    height: 1px;
    background: rgba(0,0,0,0.22);
}
.bank-preview__chip::before { top: 10px; }
.bank-preview__chip::after  { bottom: 10px; }
.bank-preview__brand {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.1rem;
    font-style: italic;
    letter-spacing: 0.02em;
    text-align: right;
    max-width: 60%;
    opacity: 0.95;
}
.bank-preview__middle {
    position: relative;
}
.bank-preview__number {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.25rem;
    letter-spacing: 0.18em;
    margin-bottom: 10px;
    font-weight: 500;
}
.bank-preview__holder {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .72rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.7;
}
.bank-preview__bottom {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .68rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.65;
    position: relative;
}

/* Bank select stylized */
.pay-bank-select-wrap {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
    margin-bottom: 16px;
    max-height: 200px;
    overflow-y: auto;
    padding: 4px;
}
.pay-bank-option {
    padding: 12px 8px;
    text-align: center;
    font-size: .72rem;
    font-weight: 600;
    border: 1.5px solid var(--pay-border);
    border-radius: 10px;
    cursor: pointer;
    background: #fff;
    transition: all .15s;
    line-height: 1.2;
    color: var(--pay-ink);
}
.pay-bank-option:hover {
    border-color: #c8bfa9;
    transform: translateY(-1px);
}
.pay-bank-option.selected {
    border-color: var(--pay-rust);
    background: #fff8f4;
    box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
}

@media (max-width: 640px) {
    .pay-balance-card { padding: 26px 22px; }
    .pay-balance-value { font-size: 2.4rem; }
    .pay-head h1 { font-size: 1.7rem; }
    .pay-tx-row { grid-template-columns: 40px 1fr; }
    .pay-tx-amount { grid-column: 2; text-align: left; }
    .pay-otp-inputs input { width: 40px; height: 48px; font-size: 1.2rem; }
}
</style>

<main class="pay-page">
    <div class="pay-wrap">

        <div class="pay-head">
            <h1>Atelier Pay</h1>
            <span class="sub">Ví điện tử nội bộ</span>
        </div>

                <!-- BALANCE CARD -->
        <div class="pay-balance-card">
            <div class="pay-balance-label">
                Số dư khả dụng
                <?php if ($walletUnlocked): ?>
                    <button type="button" class="btn-hide-balance" id="btnHideBalance" title="Ẩn số dư">
                        <i data-lucide="eye-off"></i>
                    </button>
                <?php endif; ?>
            </div>
            <div class="pay-balance-value" id="balanceDisplay">
                <?php if ($walletUnlocked): ?>
                    <?php echo number_format($balance, 0, ',', '.'); ?><small>đ</small>
                <?php else: ?>
                    <span class="balance-masked">••••••</span><small>đ</small>
                    <button type="button" class="btn-unlock-balance" id="btnUnlockBalance" title="Xem số dư">
                        <i data-lucide="eye"></i>
                        <span>Xem</span>
                    </button>
                <?php endif; ?>
            </div>
            <div class="pay-balance-actions">
                <button type="button" class="pay-btn pay-btn--primary" id="btnOpenTopup">
                    <span>＋</span> Nạp tiền
                </button>
                <button type="button" class="pay-btn pay-btn--primary" id="btnOpenWithdraw" style="background:#37423a;box-shadow:0 4px 12px rgba(55,66,58,0.3);">
                    <span>↑</span> Rút tiền
                </button>
                <button type="button" class="pay-btn pay-btn--ghost" id="btnOpenAddBank">
                    <span>🏦</span> Liên kết ngân hàng
                </button>
            </div>
        </div>

        <!-- STATS -->
        <div class="pay-stats <?php echo $walletUnlocked ? '' : 'pay-stats--locked'; ?>"
             <?php if (!$walletUnlocked): ?>data-locked="1" style="cursor:pointer;" title="Nhập PIN để xem thông tin"<?php endif; ?>
             id="statsBox">
            <div class="pay-stat">
                <div class="pay-stat-label">Tổng nạp</div>
                <div class="pay-stat-value">
                    <?php if ($walletUnlocked): ?>
                        <?php echo number_format($stats['total_topup'], 0, ',', '.'); ?>đ
                    <?php else: ?>
                        <span class="stat-masked">••••••</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pay-stat">
                <div class="pay-stat-label">Đã chi tiêu</div>
                <div class="pay-stat-value">
                    <?php if ($walletUnlocked): ?>
                        <?php echo number_format($stats['total_payment'], 0, ',', '.'); ?>đ
                    <?php else: ?>
                        <span class="stat-masked">••••••</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pay-stat">
                <div class="pay-stat-label">Đã rút</div>
                <div class="pay-stat-value">
                    <?php if ($walletUnlocked): ?>
                        <?php echo number_format($stats['total_withdraw'], 0, ',', '.'); ?>đ
                    <?php else: ?>
                        <span class="stat-masked">••••••</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pay-stat">
                <div class="pay-stat-label">Số lần nạp</div>
                <div class="pay-stat-value">
                    <?php if ($walletUnlocked): ?>
                        <?php echo (int)$stats['count_topup']; ?>
                    <?php else: ?>
                        <span class="stat-masked">••</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TABS -->
        <div class="pay-tabs">
            <button type="button" class="pay-tab active" data-tab="tx">Lịch sử giao dịch</button>
            <button type="button" class="pay-tab" data-tab="banks">Ngân hàng liên kết (<?php echo count($linkedBanks); ?>)</button>
        </div>

                <!-- TRANSACTIONS -->
        <div class="pay-panel active" id="panel-tx">
            <?php if (!$walletUnlocked): ?>
                <!-- Khi chưa unlock — hiện overlay blur -->
                <div class="pay-tx-locked">
                    <div class="pay-tx-locked__overlay">
                        <div class="pay-tx-locked__icon">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </div>
                        <h3 class="pay-tx-locked__title">Lịch sử giao dịch đang được bảo vệ</h3>
                        <p class="pay-tx-locked__desc">Nhập mã PIN để xem số dư và lịch sử giao dịch</p>
                        <button type="button" class="pay-btn pay-btn--primary" id="btnUnlockFromTx">
                            <i data-lucide="eye" style="width:16px;height:16px;"></i>
                            Nhập PIN để xem
                        </button>
                    </div>

                    <!-- Fake rows làm nền blur -->
                    <div class="pay-tx-list pay-tx-list--blur">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="pay-tx-row">
                                <div class="pay-tx-icon topup">⬇</div>
                                <div>
                                    <div class="pay-tx-title">•••••••• ••• ••</div>
                                    <div class="pay-tx-meta">••/••/•••• ••:••</div>
                                </div>
                                <div>
                                    <div class="pay-tx-amount plus">+••••••đ</div>
                                    <div class="pay-tx-balance">Số dư: ••••••đ</div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php elseif (empty($transactions)): ?>
                <div class="pay-empty">
                    <div class="pay-empty-icon">📭</div>
                    <div>Chưa có giao dịch nào</div>
                    <div style="font-size:.85rem;margin-top:6px;">Nạp tiền để bắt đầu mua sắm với Atelier Pay</div>
                </div>
            <?php else: ?>
                <div class="pay-tx-list">
                    <?php foreach ($transactions as $tx):
                        $isPlus = in_array($tx['type'], ['topup', 'refund']);
                        $icons = ['topup'=>'⬇','payment'=>'⬆','refund'=>'↺','withdraw'=>'↑','adjust'=>'⚙'];
                        $titles = [
                            'topup'    => 'Nạp tiền vào ví',
                            'payment'  => 'Thanh toán đơn hàng',
                            'refund'   => 'Hoàn tiền',
                            'withdraw' => 'Rút tiền về ngân hàng',
                            'adjust'   => 'Điều chỉnh số dư',
                        ];
                        $isPending = $tx['status'] === 'pending';
                    ?>
                        <div class="pay-tx-row">
                            <div class="pay-tx-icon <?php echo $tx['type']; ?>">
                                <?php echo $icons[$tx['type']] ?? '•'; ?>
                            </div>
                            <div>
                                <div class="pay-tx-title">
                                    <?php echo $titles[$tx['type']] ?? $tx['type']; ?>
                                    <?php if ($isPending): ?>
                                        <span style="font-size:.7rem;color:#c2410c;font-family:'IBM Plex Mono',monospace;">· Đang xử lý</span>
                                    <?php endif; ?>
                                </div>
                                <div class="pay-tx-meta">
                                    <?php echo date('d/m/Y H:i', strtotime($tx['created_at'])); ?>
                                    <?php if ($tx['description']): ?>
                                        · <?php echo htmlspecialchars($tx['description']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="pay-tx-amount <?php echo $isPending ? 'pending' : ($isPlus ? 'plus' : 'minus'); ?>">
                                    <?php echo $isPlus ? '+' : '−'; ?><?php echo number_format($tx['amount'], 0, ',', '.'); ?>đ
                                </div>
                                <div class="pay-tx-balance">
                                    Số dư: <?php echo number_format($tx['balance_after'], 0, ',', '.'); ?>đ
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- BANKS -->
        <div class="pay-panel" id="panel-banks">
                        <?php if (empty($linkedBanks)): ?>
                <div class="pay-empty">
                    <div class="pay-empty-icon">🏦</div>
                    <div>Chưa có ngân hàng nào được liên kết</div>
                    <div style="font-size:.85rem;margin-top:6px;">Liên kết ngân hàng để nạp/rút tiền</div>
                    <button type="button" class="pay-btn pay-btn--primary" style="margin-top:16px;" onclick="document.getElementById('btnOpenAddBank').click()">
                        ＋ Thêm ngân hàng
                    </button>
                </div>
            <?php else: ?>
                <div class="pay-bank-list">
                    <?php foreach ($linkedBanks as $b):
                        $bankInfo = $banks[$b['bank_bin']] ?? [
                            'name' => $b['bank_name'],
                            'color' => '#8a8377',
                            'logo'  => '',
                        ];

                        // Mask số TK — dùng ký tự `•` nhưng cắt theo ký tự, không theo byte
                        $acct = $b['account_no'];
                        $len  = mb_strlen($acct, 'UTF-8');
                        if ($len > 4) {
                            $masked = str_repeat('•', $len - 4) . mb_substr($acct, -4, null, 'UTF-8');
                        } else {
                            $masked = $acct;
                        }
                        // Chia nhóm 4 ký tự
                        $chars  = preg_split('//u', $masked, -1, PREG_SPLIT_NO_EMPTY);
                        $groups = array_chunk($chars, 4);
                        $acctMask = implode(' ', array_map(fn($g) => implode('', $g), $groups));

                        // Nền gradient theo màu bank
                        $c1 = $bankInfo['color'];
                        $c2 = shade_hex($c1, -30); // Tối hơn 30%
                    ?>
                        <div class="pay-bank-card">
                            <!-- Nền gradient -->
                            <div class="pay-bank-card__bg" style="background: linear-gradient(135deg, <?php echo $c1; ?> 0%, <?php echo $c2; ?> 100%);"></div>

                            <!-- Logo watermark to -->
                            <?php if (!empty($bankInfo['logo'])): ?>
                                <img src="<?php echo htmlspecialchars($bankInfo['logo']); ?>"
                                     alt="" class="pay-bank-card__logo"
                                     onerror="this.style.display='none'">
                            <?php endif; ?>

                            <!-- Badge mặc định -->
                            <?php if ($b['is_default']): ?>
                                <span class="pay-bank-card__tag">Mặc định</span>
                            <?php endif; ?>

                            <!-- Brand nhỏ -->
                            <div class="pay-bank-card__brand">
                                <?php if (!empty($bankInfo['logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($bankInfo['logo']); ?>"
                                         alt=""
                                         onerror="this.style.display='none'">
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($bankInfo['name']); ?></span>
                            </div>

                            <!-- Nội dung -->
                            <div>
                                <div class="pay-bank-card__chip"></div>
                                <div class="pay-bank-card__number"><?php echo htmlspecialchars($acctMask); ?></div>
                                <div class="pay-bank-card__holder"><?php echo htmlspecialchars($b['account_name']); ?></div>
                            </div>

                            <!-- Actions -->
                                                        <div class="pay-bank-card__actions">
                                <?php if (!$b['is_default']): ?>
                                    <button type="button" onclick="setDefaultBank(<?php echo (int)$b['id']; ?>)">★ Mặc định</button>
                                <?php endif; ?>
                                <button type="button" class="danger" onclick="deleteBank(<?php echo (int)$b['id']; ?>)">Xóa</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        </div>

    </div>
</main>

<!-- ============ MODAL: GIAO DỊCH (NẠP / RÚT) ============ -->
<div class="pay-modal" id="txModal">
    <div class="pay-modal__box">
        <button type="button" class="pay-modal__close" onclick="closeTx()">×</button>

        <div class="pay-steps">
            <div class="pay-step" data-step="1"></div>
            <div class="pay-step" data-step="2"></div>
        </div>

        <!-- STEP 1 -->
        <div id="txStep1">
            <h2 class="pay-modal__title" id="txTitle">Nạp tiền vào ví</h2>
            <p class="pay-modal__sub" id="txSub">Chọn số tiền và ngân hàng</p>

            <div class="pay-alert error" id="txErr1"></div>

            <div class="pay-field">
                <label id="txAmountLabel">Số tiền nạp</label>
                <input type="number" id="txAmount" placeholder="Nhập số tiền" step="1000">
                <div class="pay-quick-amounts" id="txQuickAmounts"></div>
            </div>

            <div class="pay-field">
                <label id="txBankLabel">Ngân hàng nguồn</label>
                <select id="txBank">
                    <option value="">— Chọn ngân hàng —</option>
                    <?php foreach ($linkedBanks as $b):
                        $bankInfo = $banks[$b['bank_bin']] ?? ['name' => $b['bank_name']];
                    ?>
                        <option value="<?php echo (int)$b['id']; ?>">
                            <?php echo htmlspecialchars($bankInfo['name']); ?> **** <?php echo htmlspecialchars(substr($b['account_no'], -4)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($linkedBanks)): ?>
                    <span class="hint">⚠️ Bạn chưa liên kết ngân hàng nào</span>
                <?php endif; ?>
            </div>

            <button type="button" class="pay-submit" id="btnTxNext" <?php echo empty($linkedBanks) ? 'disabled' : ''; ?>>
                Tiếp tục
            </button>
        </div>

        <!-- STEP 2 -->
        <div id="txStep2" style="display:none;">
            <h2 class="pay-modal__title">Xác nhận giao dịch</h2>
            <p class="pay-modal__sub">Nhập OTP đã gửi tới email và mã PIN</p>

            <div class="pay-summary">
                <div class="pay-summary-row">
                    <span id="sumAmountLabel">Số tiền</span>
                    <b id="sumAmount">—</b>
                </div>
                <div class="pay-summary-row">
                    <span id="sumBankLabel">Ngân hàng</span>
                    <b id="sumBank">—</b>
                </div>
                <div class="pay-summary-row total">
                    <span id="sumTotalLabel">Cộng vào ví</span>
                    <b id="sumTotal">—</b>
                </div>
            </div>

            <div class="pay-alert error" id="txErr2"></div>

            <div class="pay-field">
                <label>Mã OTP (đã gửi tới email)</label>
                <div class="pay-otp-inputs" id="otpBoxes">
                    <input type="text" maxlength="1" inputmode="numeric">
                    <input type="text" maxlength="1" inputmode="numeric">
                    <input type="text" maxlength="1" inputmode="numeric">
                    <input type="text" maxlength="1" inputmode="numeric">
                    <input type="text" maxlength="1" inputmode="numeric">
                    <input type="text" maxlength="1" inputmode="numeric">
                </div>
                <span class="hint" style="text-align:center;display:block;">OTP có hiệu lực 5 phút</span>
            </div>

            <div class="pay-field">
                <label>Mã PIN (6 số)</label>
                <input type="password" id="pinInput" maxlength="6" inputmode="numeric" placeholder="••••••">
            </div>

            <button type="button" class="pay-submit" id="btnTxConfirm" disabled>Xác nhận</button>

            <div class="pay-resend">
                Không nhận được mã? <button type="button" id="btnResendOtp" disabled>Gửi lại (60s)</button>
            </div>
        </div>
        <!-- PROCESSING -->
        <div id="txProcessing" style="display:none;">
            <div class="pay-processing">
                <div class="pay-spinner"></div>
                <h2 class="pay-modal__title" style="text-align:center;margin-top:24px;" id="processingTitle">Đang xử lý giao dịch...</h2>
                <p class="pay-modal__sub" style="text-align:center;" id="processingSub">Vui lòng không đóng cửa sổ này</p>
            </div>
        </div>

        <!-- SUCCESS -->
        <div id="txSuccess" style="display:none;">
            <div class="pay-success">
                <div class="pay-success-icon" id="successIcon">✓</div>
                <h2 class="pay-modal__title" style="text-align:center;" id="successTitle">Thành công!</h2>
                <p class="pay-modal__sub" style="text-align:center;" id="successSub">—</p>
                <button type="button" class="pay-submit" style="margin-top:16px;" onclick="location.reload()">Đóng</button>
            </div>
        </div>
    </div>
</div>
<!-- ============ MODAL: MỞ KHÓA SỐ DƯ ============ -->
<div class="pay-modal" id="unlockModal">
    <div class="pay-modal__box" style="max-width:420px;">
        <button type="button" class="pay-modal__close" onclick="closeUnlock()">×</button>
        <div style="text-align:center;margin-bottom:20px;">
            <div style="width:64px;height:64px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h2 class="pay-modal__title" style="text-align:center;">Xem số dư ví</h2>
            <p class="pay-modal__sub" style="text-align:center;">Nhập mã PIN 6 số để xem số dư</p>
        </div>

        <div class="pay-alert error" id="unlockErr"></div>

        <div class="pay-field">
            <label style="text-align:center;">Mã PIN</label>
            <input type="password" id="unlockPin" maxlength="6" inputmode="numeric" placeholder="••••••" autocomplete="off" style="text-align:center;letter-spacing:0.8em;font-size:1.4rem;font-family:'IBM Plex Mono',monospace;">
            <span class="hint" style="text-align:center;display:block;">Hiển thị trong 15 phút</span>
        </div>

        <button type="button" class="pay-submit" id="btnUnlockSubmit" disabled>Xem số dư</button>
    </div>
</div>
<!-- ============ MODAL: XÁC NHẬN XÓA BANK ============ -->
<div class="pay-modal" id="deleteBankModal">
    <div class="pay-modal__box" style="max-width:420px;">
        <button type="button" class="pay-modal__close" onclick="closeDeleteBank()">×</button>
        <div style="text-align:center;margin-bottom:20px;">
            <div style="width:64px;height:64px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 6h18"></path>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path>
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    <line x1="10" y1="11" x2="10" y2="17"></line>
                    <line x1="14" y1="11" x2="14" y2="17"></line>
                </svg>
            </div>
            <h2 class="pay-modal__title" style="text-align:center;">Xóa liên kết ngân hàng</h2>
            <p class="pay-modal__sub" style="text-align:center;">
                Bạn sắp xóa <strong id="deleteBankName">—</strong><br>
                <span style="font-size:.82rem;">Nhập mã PIN để xác nhận</span>
            </p>
        </div>

        <div class="pay-alert error" id="deleteBankErr"></div>

        <div class="pay-field">
            <label style="text-align:center;">Mã PIN</label>
            <input type="password" id="deleteBankPin" maxlength="6" inputmode="numeric" placeholder="••••••" autocomplete="off" style="text-align:center;letter-spacing:0.8em;font-size:1.4rem;font-family:'IBM Plex Mono',monospace;">
        </div>

        <button type="button" class="pay-submit" id="btnConfirmDeleteBank" disabled style="background:#b91c1c;">Xóa liên kết</button>
        <button type="button" class="pay-submit" id="btnCancelDeleteBank" style="background:#f2ece0;color:#1c1b19;margin-top:8px;">Hủy bỏ</button>
    </div>
</div>
<!-- ============ MODAL: LIÊN KẾT NGÂN HÀNG (REDESIGN) ============ -->
<div class="pay-modal" id="bankModal">
    <div class="pay-modal__box pay-modal__box--wide">
        <button type="button" class="pay-modal__close" onclick="closeBankModal()">×</button>
        <h2 class="pay-modal__title">Liên kết ngân hàng</h2>
        <p class="pay-modal__sub">Thêm tài khoản để nạp/rút tiền với Atelier Pay</p>

        <div class="pay-alert error" id="bankErr"></div>

        <!-- Card preview -->
        <div class="bank-preview" id="bankPreview">
            <div class="bank-preview__top">
                <div class="bank-preview__chip"></div>
                <div class="bank-preview__brand" id="previewBrand">Chọn ngân hàng</div>
            </div>
            <div class="bank-preview__middle">
                <div class="bank-preview__number" id="previewNumber">•••• •••• •••• ••••</div>
                <div class="bank-preview__holder" id="previewHolder">TÊN CHỦ TÀI KHOẢN</div>
            </div>
            <div class="bank-preview__bottom">
                <span id="previewLabel">Atelier Pay</span>
                <span id="previewAcct">STK</span>
            </div>
        </div>

        <!-- Bank picker -->
        <div class="pay-field">
            <label>Chọn ngân hàng</label>
            <div class="pay-bank-select-wrap" id="bankPicker">
                <?php foreach ($banks as $bin => $info): ?>
                    <div class="pay-bank-option" data-bin="<?php echo $bin; ?>" data-name="<?php echo htmlspecialchars($info['name']); ?>">
                        <?php echo htmlspecialchars($info['name']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="bankBin">
        </div>

        <div class="pay-field-row">
            <div class="pay-field">
                <label>Số tài khoản</label>
                <input type="text" id="bankAcctNo" maxlength="20" inputmode="numeric" placeholder="VD: 1234567890">
            </div>
            <div class="pay-field">
                <label>Số điện thoại</label>
                <input type="text" id="bankPhone" maxlength="11" inputmode="numeric" placeholder="VD: 0901234567">
            </div>
        </div>

        <div class="pay-field">
            <label>Tên chủ tài khoản</label>
            <input type="text" id="bankAcctName" maxlength="100" placeholder="VD: NGUYEN VAN A" style="text-transform:uppercase;">
            <span class="hint">Viết không dấu, in hoa (giống trên thẻ)</span>
        </div>

        <div class="pay-field">
            <label>Số CCCD / CMND</label>
            <input type="text" id="bankCccd" maxlength="12" inputmode="numeric" placeholder="VD: 001201012345">
            <span class="hint">12 chữ số, bắt đầu bằng 0 (VD: 001xxx...)</span>
        </div>

        <button type="button" class="pay-submit" id="btnSaveBank">
            Lưu ngân hàng
        </button>
    </div>
</div>

<script>
/* =========================================================
   ATELIER PAY — JS
   ========================================================= */
(function () {
    'use strict';

    const CSRF = <?php echo json_encode($csrf); ?>;
    const HAS_PIN = <?php echo $hasPin ? 'true' : 'false'; ?>;
    const CURRENT_BALANCE = <?php echo json_encode($balance); ?>;

    const fmt = n => new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ';
    const showErr = (el, msg) => { el.textContent = msg; el.classList.add('show'); };
    const hideErr = (el) => el.classList.remove('show');

    /* ============ TABS ============ */
    document.querySelectorAll('.pay-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.pay-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.pay-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('panel-' + tab.dataset.tab).classList.add('active');
        });
    });

    /* ============================================================
       MODAL: BANK — REDESIGN
       ============================================================ */
    const bankModal = document.getElementById('bankModal');
    const bankErr = document.getElementById('bankErr');
    const bankPicker = document.getElementById('bankPicker');
    const bankBinInput = document.getElementById('bankBin');
    const preview = document.getElementById('bankPreview');
    const previewBrand = document.getElementById('previewBrand');
    const previewNumber = document.getElementById('previewNumber');
    const previewHolder = document.getElementById('previewHolder');
    const previewAcct = document.getElementById('previewAcct');

    const BANK_COLORS = <?php echo json_encode(array_map(fn($b) => $b['color'], $banks)); ?>;

    document.getElementById('btnOpenAddBank').addEventListener('click', () => {
        bankModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        resetBankForm();
    });

    window.closeBankModal = function () {
        bankModal.classList.remove('show');
        document.body.style.overflow = '';
        hideErr(bankErr);
    };

    bankModal.addEventListener('click', e => { if (e.target === bankModal) closeBankModal(); });

    function resetBankForm() {
        bankPicker.querySelectorAll('.pay-bank-option').forEach(o => o.classList.remove('selected'));
        bankBinInput.value = '';
        document.getElementById('bankAcctNo').value = '';
        document.getElementById('bankPhone').value = '';
        document.getElementById('bankAcctName').value = '';
        document.getElementById('bankCccd').value = '';
        previewBrand.textContent = 'Chọn ngân hàng';
        previewNumber.textContent = '•••• •••• •••• ••••';
        previewHolder.textContent = 'TÊN CHỦ TÀI KHOẢN';
        previewAcct.textContent = 'STK';
        preview.style.background = 'linear-gradient(135deg, #1c1b19 0%, #38342c 100%)';
        hideErr(bankErr);
    }

    /* Bank picker */
    bankPicker.querySelectorAll('.pay-bank-option').forEach(opt => {
        opt.addEventListener('click', () => {
            bankPicker.querySelectorAll('.pay-bank-option').forEach(o => o.classList.remove('selected'));
            opt.classList.add('selected');
            bankBinInput.value = opt.dataset.bin;
            previewBrand.textContent = opt.dataset.name;

            const color = BANK_COLORS[opt.dataset.bin] || '#1c1b19';
            preview.style.background = `linear-gradient(135deg, ${color} 0%, ${shade(color, -25)} 100%)`;
        });
    });

    /* Số TK preview */
    document.getElementById('bankAcctNo').addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g, '');
        const v = e.target.value;
        if (!v) {
            previewNumber.textContent = '•••• •••• •••• ••••';
            previewAcct.textContent = 'STK';
        } else {
            const masked = '•••• •••• •••• ' + v.slice(-4).padStart(4, '•');
            previewNumber.textContent = masked;
            previewAcct.textContent = 'x' + v.slice(-4);
        }
    });

    /* SĐT */
    document.getElementById('bankPhone').addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(0, 11);
    });

    /* Tên chủ TK preview */
    document.getElementById('bankAcctName').addEventListener('input', e => {
        e.target.value = e.target.value.toUpperCase();
        previewHolder.textContent = e.target.value || 'TÊN CHỦ TÀI KHOẢN';
    });

    /* CCCD */
    document.getElementById('bankCccd').addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(0, 12);
    });

    /* Save bank */
    document.getElementById('btnSaveBank').addEventListener('click', async () => {
        const bin   = bankBinInput.value.trim();
        const acct  = document.getElementById('bankAcctNo').value.trim();
        const phone = document.getElementById('bankPhone').value.trim();
        const name  = document.getElementById('bankAcctName').value.trim().toUpperCase();
        const cccd  = document.getElementById('bankCccd').value.trim();

        hideErr(bankErr);

        if (!bin)                        return showErr(bankErr, 'Vui lòng chọn ngân hàng');
        if (!/^\d{6,20}$/.test(acct))    return showErr(bankErr, 'Số tài khoản phải là 6-20 chữ số');
        if (!/^0\d{9,10}$/.test(phone))  return showErr(bankErr, 'SĐT phải bắt đầu bằng 0, 10-11 số');
        if (name.length < 3)             return showErr(bankErr, 'Tên chủ tài khoản quá ngắn');
        if (!/^0\d{11}$/.test(cccd))     return showErr(bankErr, 'CCCD phải là 12 chữ số, bắt đầu bằng 0');

        const btn = document.getElementById('btnSaveBank');
        btn.disabled = true;
        btn.textContent = 'Đang lưu...';

        try {
            const res = await fetch('ajax/wallet_bank_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add',
                    bank_bin: bin,
                    account_no: acct,
                    account_name: name,
                    phone: phone,
                    cccd: cccd,
                    csrf_token: CSRF,
                }),
            });
            const data = await res.json();
            if (data.ok) {
                location.reload();
            } else {
                showErr(bankErr, data.error || 'Không lưu được');
                btn.disabled = false;
                btn.textContent = 'Lưu ngân hàng';
            }
        } catch (e) {
            showErr(bankErr, 'Lỗi kết nối');
            btn.disabled = false;
            btn.textContent = 'Lưu ngân hàng';
        }
    });

    /* ============ BANK: DELETE / SET DEFAULT ============ */
    /* ============ DELETE BANK (có PIN) ============ */
    const deleteBankModal = document.getElementById('deleteBankModal');
    const deleteBankPin = document.getElementById('deleteBankPin');
    const deleteBankErr = document.getElementById('deleteBankErr');
    const btnConfirmDeleteBank = document.getElementById('btnConfirmDeleteBank');
    let deleteBankId = 0;

    window.deleteBank = function (id) {
        deleteBankId = id;

        // Lấy tên bank để hiển thị
        const card = document.querySelector('.pay-bank-card button.danger[onclick*="deleteBank(' + id + ')"]');
        let bankName = 'ngân hàng này';
        if (card) {
            const cardEl = card.closest('.pay-bank-card');
            const nameEl = cardEl?.querySelector('.pay-bank-card__brand span');
            if (nameEl) bankName = nameEl.textContent.trim();
        }
        document.getElementById('deleteBankName').textContent = bankName;

        deleteBankModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        deleteBankPin.value = '';
        deleteBankErr.classList.remove('show');
        btnConfirmDeleteBank.disabled = true;
        setTimeout(() => deleteBankPin.focus(), 200);
    };

    window.closeDeleteBank = function () {
        deleteBankModal.classList.remove('show');
        document.body.style.overflow = '';
        deleteBankPin.value = '';
        deleteBankErr.classList.remove('show');
    };

    if (deleteBankModal) {
        deleteBankModal.addEventListener('click', e => {
            if (e.target === deleteBankModal) closeDeleteBank();
        });
    }

    document.getElementById('btnCancelDeleteBank')?.addEventListener('click', closeDeleteBank);

    if (deleteBankPin) {
        deleteBankPin.addEventListener('input', e => {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
            btnConfirmDeleteBank.disabled = (e.target.value.length !== 6);
        });
        deleteBankPin.addEventListener('keydown', e => {
            if (e.key === 'Enter' && deleteBankPin.value.length === 6) {
                btnConfirmDeleteBank.click();
            }
        });
    }

    if (btnConfirmDeleteBank) {
        btnConfirmDeleteBank.addEventListener('click', async () => {
            const pin = deleteBankPin.value;
            if (pin.length !== 6) return;

            deleteBankErr.classList.remove('show');
            btnConfirmDeleteBank.disabled = true;
            btnConfirmDeleteBank.textContent = 'Đang xử lý...';

            try {
                const res = await fetch('ajax/wallet_bank_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        bank_id: deleteBankId,
                        pin: pin,
                        csrf_token: CSRF,
                    }),
                });
                const data = await res.json();

                if (data.ok) {
                    btnConfirmDeleteBank.textContent = '✓ Đã xóa';
                    // Chờ 800ms rồi reload để user thấy thông báo
                    setTimeout(() => location.reload(), 800);
                } else {
                    deleteBankErr.textContent = data.error || 'Không xóa được';
                    deleteBankErr.classList.add('show');
                    if (data.clear_pin) deleteBankPin.value = '';
                    btnConfirmDeleteBank.disabled = false;
                    btnConfirmDeleteBank.textContent = 'Xóa liên kết';
                    deleteBankPin.focus();
                }
            } catch (e) {
                deleteBankErr.textContent = 'Lỗi kết nối';
                deleteBankErr.classList.add('show');
                btnConfirmDeleteBank.disabled = false;
                btnConfirmDeleteBank.textContent = 'Xóa liên kết';
            }
        });
    }

    window.setDefaultBank = function (id) {
        fetch('ajax/wallet_bank_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_default', bank_id: id, csrf_token: CSRF }),
        }).then(r => r.json()).then(d => {
            if (d.ok) location.reload();
            else alert(d.error || 'Không đặt được');
        });
    };

    /* ============================================================
       MODAL: TRANSACTION (TOPUP / WITHDRAW)
       ============================================================ */
    const txModal = document.getElementById('txModal');
    const step1 = document.getElementById('txStep1');
    const step2 = document.getElementById('txStep2');
    const step3 = document.getElementById('txSuccess');
    const txErr1 = document.getElementById('txErr1');
    const txErr2 = document.getElementById('txErr2');
    const btnNext = document.getElementById('btnTxNext');
    const btnConfirm = document.getElementById('btnTxConfirm');
    const btnResend = document.getElementById('btnResendOtp');
    const pinInput = document.getElementById('pinInput');
    const amountInput = document.getElementById('txAmount');
    const bankSelect = document.getElementById('txBank');
    const otpBoxes = document.querySelectorAll('#otpBoxes input');

    let mode = 'topup'; // 'topup' | 'withdraw'
    let currentTxId = 0;
    let currentAmount = 0;
    let currentBankName = '';
    let resendTimer = null;

    document.getElementById('btnOpenTopup').addEventListener('click', () => {
        if (!HAS_PIN) return alert('Vui lòng thiết lập mã PIN thanh toán trước (trong trang Tài khoản).');
        openTx('topup');
    });

    document.getElementById('btnOpenWithdraw').addEventListener('click', () => {
        if (!HAS_PIN) return alert('Vui lòng thiết lập mã PIN thanh toán trước (trong trang Tài khoản).');
        if (CURRENT_BALANCE < 50000) return alert('Số dư tối thiểu 50.000đ để rút tiền.');
        openTx('withdraw');
    });

    window.closeTx = function () {
        txModal.classList.remove('show');
        document.body.style.overflow = '';
        clearInterval(resendTimer);
    };

    txModal.addEventListener('click', e => { if (e.target === txModal) closeTx(); });

    function openTx(m) {
        mode = m;
        txModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        resetTx();
    }

    function resetTx() {
        step1.style.display = '';
        step2.style.display = 'none';
        step3.style.display = 'none';
        amountInput.value = '';
        bankSelect.value = '';
        otpBoxes.forEach(i => { i.value = ''; i.classList.remove('filled'); });
        pinInput.value = '';
        btnConfirm.disabled = true;
        hideErr(txErr1);
        hideErr(txErr2);

        document.querySelectorAll('.pay-step').forEach((s, i) => {
            s.classList.toggle('active', i === 0);
        });

        // Customize UI theo mode
        if (mode === 'topup') {
            document.getElementById('txTitle').textContent = 'Nạp tiền vào ví';
            document.getElementById('txSub').textContent = 'Chọn số tiền và ngân hàng nguồn';
            document.getElementById('txAmountLabel').textContent = 'Số tiền nạp';
            document.getElementById('txBankLabel').textContent = 'Ngân hàng nguồn';
            document.getElementById('sumAmountLabel').textContent = 'Số tiền nạp';
            document.getElementById('sumBankLabel').textContent = 'Từ ngân hàng';
            document.getElementById('sumTotalLabel').textContent = 'Cộng vào ví';
            document.getElementById('btnTxConfirm').textContent = 'Xác nhận nạp tiền';
            document.getElementById('successTitle').textContent = 'Nạp tiền thành công!';
            document.getElementById('successIcon').textContent = '⬇';
            document.getElementById('successIcon').className = 'pay-success-icon';

            document.getElementById('txQuickAmounts').innerHTML = `
                <button type="button" data-amt="50000">50k</button>
                <button type="button" data-amt="100000">100k</button>
                <button type="button" data-amt="200000">200k</button>
                <button type="button" data-amt="500000">500k</button>
                <button type="button" data-amt="1000000">1 triệu</button>
            `;
        } else {
            document.getElementById('txTitle').textContent = 'Rút tiền về ngân hàng';
            document.getElementById('txSub').textContent = 'Chọn số tiền và ngân hàng nhận';
            document.getElementById('txAmountLabel').textContent = 'Số tiền rút';
            document.getElementById('txBankLabel').textContent = 'Ngân hàng nhận';
            document.getElementById('sumAmountLabel').textContent = 'Số tiền rút';
            document.getElementById('sumBankLabel').textContent = 'Về ngân hàng';
            document.getElementById('sumTotalLabel').textContent = 'Trừ từ ví';
            document.getElementById('btnTxConfirm').textContent = 'Xác nhận rút tiền';
            document.getElementById('successTitle').textContent = 'Rút thành công!';
            document.getElementById('successIcon').textContent = '↑';
            document.getElementById('successIcon').className = 'pay-success-icon withdraw';

            // Quick amounts cho withdraw
            const avail = Math.floor(CURRENT_BALANCE / 1000) * 1000;
            const opts = [50000, 100000, 200000, 500000].filter(v => v <= avail);
            if (avail >= 10000) opts.push(avail);
            document.getElementById('txQuickAmounts').innerHTML = opts.map(v =>
                `<button type="button" data-amt="${v}">${v === avail ? 'Tối đa ' : ''}${fmt(v)}</button>`
            ).join('');
        }

        // Reset quick amount handlers
        document.querySelectorAll('#txQuickAmounts button').forEach(b => {
            b.addEventListener('click', () => {
                amountInput.value = b.dataset.amt;
            });
        });

        // Set min/max cho input
        if (mode === 'topup') {
            amountInput.min = 10000;
            amountInput.max = 50000000;
            amountInput.placeholder = 'Nhập số tiền (≥ 10.000đ)';
        } else {
            amountInput.min = 50000;
            amountInput.max = CURRENT_BALANCE;
            amountInput.placeholder = 'Nhập số tiền (≥ 50.000đ)';
        }
    }

    function goStep(n) {
        document.querySelectorAll('.pay-step').forEach((s, i) => {
            s.classList.toggle('active', i < n);
        });
    }

    /* Step 1 → send OTP */
    btnNext.addEventListener('click', async () => {
        const amount = parseInt(amountInput.value, 10) || 0;
        const bankId = parseInt(bankSelect.value, 10) || 0;

        hideErr(txErr1);

        if (mode === 'topup') {
            if (amount < 10000)     return showErr(txErr1, 'Số tiền nạp tối thiểu 10.000đ');
            if (amount > 50000000)  return showErr(txErr1, 'Số tiền nạp tối đa 50.000.000đ');
        } else {
            if (amount < 50000)                 return showErr(txErr1, 'Số tiền rút tối thiểu 50.000đ');
            if (amount > CURRENT_BALANCE)       return showErr(txErr1, 'Số tiền rút vượt quá số dư hiện tại');
            if (CURRENT_BALANCE - amount < 0)   return showErr(txErr1, 'Số dư còn lại không đủ');
        }
        if (!bankId) return showErr(txErr1, 'Vui lòng chọn ngân hàng');

        btnNext.disabled = true;
        btnNext.textContent = 'Đang gửi OTP...';

        const endpoint = mode === 'topup'
            ? 'ajax/wallet_topup_send_otp.php'
            : 'ajax/wallet_withdraw_send_otp.php';

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    amount,
                    bank_id: bankId,
                    csrf_token: CSRF,
                }),
            });
            const data = await res.json();

            if (!data.ok) {
                showErr(txErr1, data.error || 'Không gửi được OTP');
                btnNext.disabled = false;
                btnNext.textContent = 'Tiếp tục';
                return;
            }

            currentTxId = data.request_id;
            currentAmount = amount;
            currentBankName = data.bank_name;

            document.getElementById('sumAmount').textContent = fmt(amount);
            document.getElementById('sumBank').textContent = data.bank_name;
            document.getElementById('sumTotal').textContent = fmt(amount);

            step1.style.display = 'none';
            step2.style.display = '';
            goStep(2);

            btnNext.disabled = false;
            btnNext.textContent = 'Tiếp tục';

            setTimeout(() => otpBoxes[0].focus(), 100);
            startResendCountdown(60);

        } catch (e) {
            showErr(txErr1, 'Lỗi kết nối, thử lại');
            btnNext.disabled = false;
            btnNext.textContent = 'Tiếp tục';
        }
    });

    /* OTP boxes */
    otpBoxes.forEach((input, idx) => {
        input.addEventListener('input', e => {
            const v = e.target.value.replace(/\D/g, '');
            e.target.value = v;
            e.target.classList.toggle('filled', !!v);
            if (v && idx < 5) otpBoxes[idx + 1].focus();
            checkConfirmReady();
        });
        input.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !e.target.value && idx > 0) otpBoxes[idx - 1].focus();
        });
        input.addEventListener('paste', e => {
            e.preventDefault();
            const text = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            text.split('').forEach((ch, i) => {
                if (otpBoxes[i]) { otpBoxes[i].value = ch; otpBoxes[i].classList.add('filled'); }
            });
            otpBoxes[Math.min(text.length, 5)].focus();
            checkConfirmReady();
        });
    });

    pinInput.addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
        checkConfirmReady();
    });

    function getOtp() {
        return Array.from(otpBoxes).map(i => i.value).join('');
    }

    function checkConfirmReady() {
        btnConfirm.disabled = !(getOtp().length === 6 && pinInput.value.length === 6);
    }

    /* Step 2 → confirm */
    btnConfirm.addEventListener('click', async () => {
        const otp = getOtp();
        const pin = pinInput.value;

        hideErr(txErr2);
        if (otp.length !== 6) return showErr(txErr2, 'Nhập đủ 6 số OTP');
        if (pin.length !== 6) return showErr(txErr2, 'Nhập đủ 6 số PIN');

        btnConfirm.disabled = true;
        btnConfirm.textContent = 'Đang xử lý...';

        const endpoint = mode === 'topup'
            ? 'ajax/wallet_topup_confirm.php'
            : 'ajax/wallet_withdraw_confirm.php';

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    request_id: currentTxId,
                    otp_code: otp,
                    pin: pin,
                    csrf_token: CSRF,
                }),
            });
            const data = await res.json();

            if (!data.ok) {
                showErr(txErr2, data.error || 'Xác nhận thất bại');
                btnConfirm.disabled = false;
                btnConfirm.textContent = mode === 'topup' ? 'Xác nhận nạp tiền' : 'Xác nhận rút tiền';
                if (data.clear_pin) pinInput.value = '';
                if (data.clear_otp) {
                    otpBoxes.forEach(i => { i.value = ''; i.classList.remove('filled'); });
                    otpBoxes[0].focus();
                }
                checkConfirmReady();
                return;
            }

                        // Success — hiện loading 3s rồi mới show success
            clearInterval(resendTimer);

            // Chuẩn bị nội dung success trước
            if (mode === 'topup') {
                document.getElementById('processingTitle').textContent = 'Đang cộng tiền vào ví...';
                document.getElementById('processingSub').textContent   = 'Vui lòng không đóng cửa sổ này';
                document.getElementById('successTitle').textContent    = 'Nạp tiền thành công!';
                document.getElementById('successIcon').textContent     = '✓';
                document.getElementById('successIcon').className       = 'pay-success-icon';
                document.getElementById('successSub').innerHTML =
                    `Đã cộng <strong style="color:#a8461f;">${fmt(currentAmount)}</strong> vào ví Atelier Pay`;
            } else {
                document.getElementById('processingTitle').textContent = 'Đang chuyển tiền về ngân hàng...';
                document.getElementById('processingSub').textContent   = 'Vui lòng không đóng cửa sổ này';
                document.getElementById('successTitle').textContent    = 'Rút tiền thành công!';
                document.getElementById('successIcon').textContent     = '✓';
                document.getElementById('successIcon').className       = 'pay-success-icon withdraw';
                document.getElementById('successSub').innerHTML =
                    `Đã rút <strong style="color:#c2410c;">${fmt(currentAmount)}</strong> về ${currentBankName}.<br>
                     <span style="font-size:.8rem;color:#8a8377;">Tiền sẽ vào tài khoản trong 1–2 giờ làm việc</span>`;
            }

            // Show processing
            step2.style.display = 'none';
            const processingEl = document.getElementById('txProcessing');
            processingEl.style.display = '';
            goStep(3);

            // Đợi 3s rồi show success
            setTimeout(() => {
                processingEl.style.display = 'none';
                step3.style.display = '';
            }, 3000);

        } catch (e) {
            showErr(txErr2, 'Lỗi kết nối, thử lại');
            btnConfirm.disabled = false;
            btnConfirm.textContent = mode === 'topup' ? 'Xác nhận nạp tiền' : 'Xác nhận rút tiền';
        }
    });

    /* Resend OTP */
    btnResend.addEventListener('click', async () => {
        if (btnResend.disabled) return;
        btnResend.disabled = true;
        btnResend.textContent = 'Đang gửi...';

        const endpoint = mode === 'topup'
            ? 'ajax/wallet_topup_send_otp.php'
            : 'ajax/wallet_withdraw_send_otp.php';

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    amount: currentAmount,
                    bank_id: parseInt(bankSelect.value, 10) || 0,
                    resend_of: currentTxId,
                    csrf_token: CSRF,
                }),
            });
            const data = await res.json();
            if (data.ok) {
                currentTxId = data.request_id;
                otpBoxes.forEach(i => { i.value = ''; i.classList.remove('filled'); });
                otpBoxes[0].focus();
                checkConfirmReady();
                startResendCountdown(60);
                hideErr(txErr2);
            } else {
                showErr(txErr2, data.error || 'Không gửi lại được');
                btnResend.disabled = false;
                btnResend.textContent = 'Gửi lại';
            }
        } catch (e) {
            showErr(txErr2, 'Lỗi kết nối');
            btnResend.disabled = false;
            btnResend.textContent = 'Gửi lại';
        }
    });

    function startResendCountdown(seconds) {
        btnResend.disabled = true;
        let left = seconds;
        btnResend.textContent = `Gửi lại (${left}s)`;
        clearInterval(resendTimer);
        resendTimer = setInterval(() => {
            left--;
            if (left <= 0) {
                clearInterval(resendTimer);
                btnResend.disabled = false;
                btnResend.textContent = 'Gửi lại';
            } else {
                btnResend.textContent = `Gửi lại (${left}s)`;
            }
        }, 1000);
    }

    /* Helper: shade hex color */
    function shade(hex, percent) {
        hex = hex.replace('#', '');
        const num = parseInt(hex, 16);
        const r = Math.max(0, Math.min(255, (num >> 16) + percent));
        const g = Math.max(0, Math.min(255, ((num >> 8) & 0xFF) + percent));
        const b = Math.max(0, Math.min(255, (num & 0xFF) + percent));
        return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
    }
    /* ============================================================
       UNLOCK BALANCE
       ============================================================ */
    const unlockModal = document.getElementById('unlockModal');
    const unlockPin = document.getElementById('unlockPin');
    const unlockErr = document.getElementById('unlockErr');
    const btnUnlockSubmit = document.getElementById('btnUnlockSubmit');
    const btnUnlockBalance = document.getElementById('btnUnlockBalance');

    if (btnUnlockBalance) {
        btnUnlockBalance.addEventListener('click', () => {
            unlockModal.classList.add('show');
            document.body.style.overflow = 'hidden';
            unlockPin.value = '';
            unlockErr.classList.remove('show');
            btnUnlockSubmit.disabled = true;
            setTimeout(() => unlockPin.focus(), 200);
        });
    }

    window.closeUnlock = function () {
        unlockModal.classList.remove('show');
        document.body.style.overflow = '';
    };

    if (unlockModal) {
        unlockModal.addEventListener('click', e => {
            if (e.target === unlockModal) closeUnlock();
        });
    }

    if (unlockPin) {
        unlockPin.addEventListener('input', e => {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
            btnUnlockSubmit.disabled = (e.target.value.length !== 6);
        });
        unlockPin.addEventListener('keydown', e => {
            if (e.key === 'Enter' && unlockPin.value.length === 6) {
                btnUnlockSubmit.click();
            }
        });
    }

    if (btnUnlockSubmit) {
        btnUnlockSubmit.addEventListener('click', async () => {
            const pin = unlockPin.value;
            if (pin.length !== 6) return;

            unlockErr.classList.remove('show');
            btnUnlockSubmit.disabled = true;
            btnUnlockSubmit.textContent = 'Đang kiểm tra...';

            try {
                const res = await fetch('ajax/wallet_unlock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pin, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (data.ok) {
                    location.reload();
                } else {
                    unlockErr.textContent = data.error || 'PIN không đúng';
                    unlockErr.classList.add('show');
                    unlockPin.value = '';
                    btnUnlockSubmit.disabled = true;
                    btnUnlockSubmit.textContent = 'Xem số dư';
                    unlockPin.focus();
                }
            } catch (e) {
                unlockErr.textContent = 'Lỗi kết nối';
                unlockErr.classList.add('show');
                btnUnlockSubmit.disabled = false;
                btnUnlockSubmit.textContent = 'Xem số dư';
            }
        });
    }

    /* Hide balance (thu lại ngay) */
    const btnHideBalance = document.getElementById('btnHideBalance');
    if (btnHideBalance) {
        btnHideBalance.addEventListener('click', async () => {
            btnHideBalance.disabled = true;
            try {
                await fetch('ajax/wallet_unlock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'lock', csrf_token: CSRF }),
                });
                location.reload();
            } catch (e) {
                alert('Không ẩn được. Vui lòng thử lại.');
                btnHideBalance.disabled = false;
            }
        });
    }
    /* Click vào stats locked → mở modal unlock */
    const statsBox = document.getElementById('statsBox');
    if (statsBox && statsBox.dataset.locked === '1') {
        statsBox.addEventListener('click', () => {
            if (btnUnlockBalance) btnUnlockBalance.click();
        });
    }

    /* Nút unlock từ transaction overlay */
    const btnUnlockFromTx = document.getElementById('btnUnlockFromTx');
    if (btnUnlockFromTx) {
        btnUnlockFromTx.addEventListener('click', () => {
            if (btnUnlockBalance) btnUnlockBalance.click();
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>