<?php
// Bảo vệ — chỉ admin
ob_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   HELPER: Format badge (0 → ẩn, 1-9 → số, >9 → "9+")
   ============================================================ */
if (!function_exists('admBadgeCount')) {
    function admBadgeCount($n) {
        $n = (int)$n;
        if ($n <= 0) return '';
        return $n > 9 ? '9+' : (string)$n;
    }
}

/* ============================================================
   ĐẾM SỐ LƯỢNG CẦN CHÚ Ý
   ============================================================ */
$menuCounts = [
    'pending_orders'   => 0,
    'noreply_reviews'  => 0,
    'pending_returns'  => 0,
    'unread_notifs'    => 0,
];

try {
    $menuCounts['pending_orders'] = (int)$pdo->query("
        SELECT COUNT(*) FROM orders 
        WHERE status IN ('pending', 'processing')
    ")->fetchColumn();

    $menuCounts['noreply_reviews'] = (int)$pdo->query("
        SELECT COUNT(*) FROM reviews 
        WHERE admin_reply IS NULL OR admin_reply = ''
    ")->fetchColumn();
} catch (Exception $e) {}

try {
    $menuCounts['pending_returns'] = (int)$pdo->query("
        SELECT COUNT(*) FROM return_requests WHERE status = 'pending'
    ")->fetchColumn();
} catch (Exception $e) {}

try {
    $menuCounts['unread_notifs'] = (int)$pdo->query("
        SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0
    ")->fetchColumn();
} catch (Exception $e) {}

/* ============================================================
   RECENT NOTIFICATIONS (dropdown bell)
   ============================================================ */
$recentNotifs = [];
try {
    $recentNotifs = $pdo->query("
        SELECT id, type, title, icon, priority, is_read, link, created_at
        FROM admin_notifications
        ORDER BY is_read ASC, created_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$currentPage = basename($_SERVER['PHP_SELF']);

/* ============================================================
   PAGE TITLE — hiển thị trong topbar
   ============================================================ */
$pageTitles = [
    'index.php'              => 'Tổng quan',
    'orders.php'             => 'Đơn hàng',
    'returns.php'            => 'Đơn hoàn tiền',
    'products.php'           => 'Sản phẩm',
    'categories.php'         => 'Danh mục',
    'banners.php'            => 'Banner quảng cáo',
    'discount-events.php'    => 'Sự kiện giảm giá',
    'vouchers.php'           => 'Voucher / Mã giảm giá',
    'reviews.php'            => 'Đánh giá',
    'settings.php'           => 'Tài khoản nhận tiền',
    'tax.php'                => 'Quản lý thuế',
    'notifications.php'      => 'Thông báo',
    'users.php'              => 'Người dùng',
    'support.php'            => 'Yêu cầu hỗ trợ',
];
$pageTitle = $pageTitles[$currentPage] ?? 'Quản trị';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — THE ATELIER</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Thư viện Icon chuyên nghiệp (Phosphor Icons) -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        /* ============================================================
           ADMIN TOKENS
           ============================================================ */
        :root {
            --admin-sidebar-bg: #1c1b19;
            --admin-sidebar-hover: #2a2926;
            --admin-accent: #a8461f;
            --admin-accent-2: #e11d48;
            --admin-bg: #f2ece0;
            --admin-card: #fffdf9;
            --admin-border: #e8e0d0;
            --admin-text: #1c1b19;
            --admin-text-muted: #8a8377;
            --admin-sidebar-width: 260px;
            --admin-topbar-height: 64px;
        }

        * { box-sizing: border-box; }

        html, body.admin-body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            background: var(--admin-bg);
            color: var(--admin-text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        /* ============================================================
           LAYOUT — Grid 2 cột full height
           ============================================================ */
        .admin-layout {
            display: grid;
            grid-template-columns: var(--admin-sidebar-width) 1fr;
            height: 100vh;
            overflow: hidden;
        }

        /* ============================================================
           SIDEBAR — Sticky, scroll riêng
           ============================================================ */
        .admin-sidebar {
            background: var(--admin-sidebar-bg);
            color: #fffdf9;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 100;
        }

        .admin-sidebar::-webkit-scrollbar { width: 6px; }
        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 253, 249, 0.15);
            border-radius: 3px;
        }

        .admin-sidebar__brand {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 253, 249, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            background: var(--admin-sidebar-bg);
            z-index: 10;
        }

        .admin-sidebar__brand h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.25rem;
            margin: 0;
            letter-spacing: 0.05em;
            color: #fffdf9;
        }

        .admin-sidebar__brand p {
            margin: 4px 0 0;
            font-size: 0.7rem;
            opacity: 0.6;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .admin-sidebar__toggle {
            display: none;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            background: rgba(255, 253, 249, 0.12);
            border: none;
            border-radius: 8px;
            color: #fffdf9;
            font-size: 1.3rem;
            cursor: pointer;
            flex-shrink: 0;
            padding: 0;
            font-family: inherit;
            transition: background 0.2s;
        }

        .admin-sidebar__toggle:hover {
            background: rgba(255, 253, 249, 0.2);
        }

        .admin-nav {
            list-style: none;
            padding: 0;
            margin: 0;
            flex: 1;
        }

        .admin-nav__section {
            padding: 1rem 1.5rem 0.5rem;
            font-size: 0.65rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            opacity: 0.4;
        }

        .admin-nav a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.7rem 1.5rem;
            color: rgba(255, 253, 249, 0.85);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .admin-nav a:hover {
            background: var(--admin-sidebar-hover);
            color: #fffdf9;
        }

        .admin-nav a.active {
            background: var(--admin-sidebar-hover);
            border-left-color: var(--admin-accent);
            color: #fffdf9;
            font-weight: 600;
        }

        /* CSS cho Icon SVG */
        .admin-nav a i.ph {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
            display: inline-flex;
            justify-content: center;
        }

        .nav-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            margin-left: auto;
            border-radius: 10px;
            font-family: 'IBM Plex Mono', ui-monospace, monospace;
            font-size: 0.68rem;
            font-weight: 700;
            line-height: 1;
            color: #fff;
            flex: none;
        }

        .nav-badge--orange { background: #f59e0b; }
        .nav-badge--red    { background: #e11d48; }
        .nav-badge--blue   { background: #2563eb; }

        .admin-nav a.active .nav-badge--orange { background: #fbbf24; color: #78350f; }
        .admin-nav a.active .nav-badge--red    { background: #fb7185; color: #7f1d1d; }
        .admin-nav a.active .nav-badge--blue   { background: #60a5fa; color: #1e3a8a; }

        .admin-sidebar__footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 253, 249, 0.1);
            flex-shrink: 0;
        }

        .admin-sidebar__user {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-bottom: 0.75rem;
        }

        .admin-sidebar__user strong {
            display: block;
            color: #fffdf9;
            opacity: 1;
            margin-top: 2px;
        }

        .admin-sidebar__logout {
            display: block;
            padding: 0.6rem 1rem;
            background: rgba(255, 253, 249, 0.08);
            color: #fffdf9;
            text-decoration: none;
            text-align: center;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            transition: background 0.2s;
            border-radius: 6px;
        }

        .admin-sidebar__logout:hover { background: var(--admin-accent-2); }

        /* ============================================================
           MAIN — Scroll container
           ============================================================ */
        .admin-main {
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .admin-main::-webkit-scrollbar { width: 8px; }
        .admin-main::-webkit-scrollbar-thumb {
            background: rgba(28, 27, 25, 0.15);
            border-radius: 4px;
        }

        /* ============================================================
           TOPBAR — Sticky, luôn cố định
           ============================================================ */
        .admin-topbar {
            position: sticky;
            top: 0;
            height: var(--admin-topbar-height);
            background: rgba(255, 253, 249, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            gap: 16px;
            z-index: 900;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(28, 27, 25, 0.05);
        }

        .admin-topbar__title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--admin-text);
            margin: 0;
            letter-spacing: -0.01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .admin-topbar__right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        /* ============================================================
           BELL NOTIFICATION — Nằm trong topbar
           ============================================================ */
        .adm-bell-wrap {
            position: relative;
            z-index: 1000;
        }

        .adm-bell-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid var(--admin-border);
            background: #fff;
            cursor: pointer;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 0;
            color: var(--admin-text-muted);
        }

        .adm-bell-btn:hover {
            background: #faf6ee;
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(28, 27, 25, 0.1);
            color: var(--admin-accent);
        }

        .adm-bell-btn.has-new { animation: bellShake 0.55s ease; color: var(--admin-text); }

        @keyframes bellShake {
            0%, 100% { transform: rotate(0); }
            20% { transform: rotate(-12deg); }
            40% { transform: rotate(12deg); }
            60% { transform: rotate(-8deg); }
            80% { transform: rotate(8deg); }
        }

        .adm-bell-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: #e11d48;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 700;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            font-family: 'IBM Plex Mono', monospace;
            line-height: 1;
        }

        .adm-bell-panel {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 380px;
            max-width: calc(100vw - 32px);
            background: var(--admin-card);
            border: 1px solid var(--admin-border);
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(28, 27, 25, 0.18);
            z-index: 99999;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .adm-bell-panel.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .adm-bell-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid var(--admin-border);
            font-size: 0.9rem;
        }

        .adm-bell-head b { color: var(--admin-text); }

        .adm-bell-list {
            max-height: 380px;
            overflow-y: auto;
        }

        .adm-bell-item {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            padding: 0.8rem 1.1rem;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px solid #f5f2ec;
            transition: background 0.15s;
        }

        .adm-bell-item:last-child { border-bottom: none; }
        .adm-bell-item:hover { background: #fbf7ef; }
        .adm-bell-item.unread { background: #fffaf5; }
        .adm-bell-item.unread .adm-bell-title { font-weight: 700; }

        .adm-bell-icon {
            font-size: 1.3rem;
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #faf6ee;
        }

        .adm-bell-txt {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
            flex: 1;
        }

        .adm-bell-title {
            font-size: 0.85rem;
            color: var(--admin-text);
            line-height: 1.35;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .adm-bell-time {
            font-size: 0.7rem;
            color: var(--admin-text-muted);
            font-family: 'IBM Plex Mono', monospace;
        }

        .adm-bell-foot {
            padding: 0.7rem 1rem;
            border-top: 1px solid var(--admin-border);
            text-align: center;
            background: #fbf7ef;
        }

        .adm-bell-foot a,
        .adm-bell-foot button {
            color: var(--admin-accent);
            font-weight: 600;
            font-size: 0.8rem;
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .adm-bell-foot a:hover,
        .adm-bell-foot button:hover { text-decoration: underline; }

        .adm-bell-empty {
            padding: 2.5rem 1rem;
            text-align: center;
            color: var(--admin-text-muted);
            font-size: 0.85rem;
        }

        .adm-bell-empty .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.4;
            display: block;
        }

        /* ============================================================
           MAIN CONTENT INNER
           ============================================================ */
        .admin-main__inner {
            padding: 28px 32px 60px;
            flex: 1;
        }

        /* ============================================================
           PAGE HEAD (trong content)
           ============================================================ */
        .admin-page-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--admin-border);
            gap: 1rem;
            flex-wrap: wrap;
        }

        .admin-page-head h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.75rem;
            font-weight: 600;
            margin: 0;
        }

        .admin-page-head .subtitle {
            font-size: 0.85rem;
            color: var(--admin-text-muted);
            margin-top: 4px;
        }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn-adm {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.6rem 1.1rem;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-adm--primary { background: var(--admin-sidebar-bg); color: #fffdf9; }
        .btn-adm--primary:hover { background: var(--admin-accent); }

        .btn-adm--edit { background: #2563eb; color: #fff; }
        .btn-adm--edit:hover { background: #1d4ed8; }

        .btn-adm--delete { background: #e11d48; color: #fff; }
        .btn-adm--delete:hover { background: #be123c; }

        .btn-adm--ghost {
            background: transparent;
            color: var(--admin-text);
            border: 1px solid var(--admin-border);
        }
        .btn-adm--ghost:hover { background: var(--admin-bg); }

        /* ============================================================
           MARKETING TABS
           ============================================================ */
        .mkt-tabs {
            display: flex;
            gap: 6px;
            padding: 6px;
            background: var(--admin-card);
            border: 1px solid var(--admin-border);
            border-radius: 12px;
            margin-bottom: 20px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .mkt-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: transparent;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--admin-text-muted);
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .mkt-tab:hover {
            background: #fbf7ef;
            color: var(--admin-text);
        }

        .mkt-tab.active {
            background: var(--admin-sidebar-bg);
            color: #fffdf9;
            box-shadow: 0 2px 8px rgba(28, 27, 25, 0.15);
        }

        .mkt-tab .icon { font-size: 1.05rem; }

        .mkt-tab .cnt {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            border-radius: 999px;
            font-family: 'IBM Plex Mono', ui-monospace, monospace;
            font-size: 0.68rem;
            font-weight: 700;
            line-height: 1;
        }

        .mkt-tab.active .cnt { background: rgba(255, 255, 255, 0.22); }

        /* ============================================================
           CARDS, TABLE, FORM, MODAL, BADGES, TOAST
           ============================================================ */
        .admin-card {
            background: var(--admin-card);
            border: 1px solid var(--admin-border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .adm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
        }

        .adm-table th {
            background: var(--admin-sidebar-bg);
            color: #fffdf9;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .adm-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--admin-border);
            vertical-align: middle;
        }

        .adm-table tr:hover td { background: #fbf7ef; }
        .adm-table img { width: 48px; height: 48px; object-fit: cover; border-radius: 4px; }

        .adm-form-group { margin-bottom: 1rem; }

        .adm-form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--admin-text-muted);
            margin-bottom: 0.35rem;
        }

        .adm-input,
        .adm-select,
        .adm-textarea {
            width: 100%;
            padding: 0.65rem 0.9rem;
            border: 1px solid var(--admin-border);
            border-radius: 6px;
            font-size: 0.9rem;
            font-family: inherit;
            background: #fff;
            color: var(--admin-text);
            transition: border-color 0.2s;
        }

        .adm-input:focus,
        .adm-select:focus,
        .adm-textarea:focus {
            outline: none;
            border-color: var(--admin-accent);
        }

        .adm-textarea { resize: vertical; min-height: 80px; }

        .adm-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .adm-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .adm-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(28, 27, 25, 0.7);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .adm-modal.show { display: flex; }

        .adm-modal__box {
            background: var(--admin-card);
            border-radius: 10px;
            width: 100%;
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
            position: relative;
        }

        .adm-modal__close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--admin-text-muted);
            line-height: 1;
        }

        .adm-modal__close:hover { color: var(--admin-accent-2); }

        .adm-modal__title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.4rem;
            font-weight: 600;
            margin: 0 0 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--admin-border);
        }

        .adm-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.03em;
        }
        .adm-badge--green { background: #dcfce7; color: #166534; }
        .adm-badge--orange { background: #fef3c7; color: #92400e; }
        .adm-badge--red { background: #fee2e2; color: #991b1b; }
        .adm-badge--blue { background: #dbeafe; color: #1d4ed8; }
        .adm-badge--gray { background: #f3f4f6; color: #4b5563; }

        .adm-toast {
            position: fixed;
            top: calc(var(--admin-topbar-height) + 16px);
            right: 32px;
            background: #166534;
            color: #fff;
            padding: 0.85rem 1.5rem;
            border-radius: 8px;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.85rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 100000;
            opacity: 0;
            transform: translateY(-12px);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            max-width: calc(100vw - 64px);
        }

        .adm-toast.show { opacity: 1; transform: translateY(0); }
        .adm-toast.error { background: #b91c1c; }

        .adm-toast:not(.show) {
            pointer-events: none !important;
            visibility: hidden !important;
        }

        /* ============================================================
           📱 MOBILE RESPONSIVE
           ============================================================ */
        @media (max-width: 900px) {
            html, body.admin-body {
                overflow: auto;
                height: auto;
            }

            .admin-layout {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }

            /* Sidebar collapse */
            .admin-sidebar {
                height: auto;
                max-height: 60px;
                overflow: hidden;
                position: sticky;
                top: 0;
                z-index: 1000;
                transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .admin-sidebar.open {
                max-height: 100vh;
                overflow-y: auto;
            }

            .admin-sidebar__brand {
                padding: 10px 16px;
                height: 60px;
                position: static;
            }

            .admin-sidebar__brand h1 { font-size: 1rem; }
            .admin-sidebar__brand p { display: none; }
            .admin-sidebar__toggle { display: flex !important; }

            .admin-nav {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .admin-sidebar.open .admin-nav { max-height: 2000px; }

            .admin-nav__section { padding: 10px 16px 4px; font-size: 0.6rem; }
            .admin-nav a { padding: 12px 16px; font-size: 0.88rem; }
            .admin-sidebar__footer { padding: 14px 16px; margin-top: 0; }

            /* Main */
            .admin-main {
                height: auto;
                overflow: visible;
            }

            /* Topbar — sticky */
            .admin-topbar {
                padding: 0 16px;
                height: 56px;
                top: 0;
            }

            .admin-topbar__title { font-size: 1rem; }

            .admin-main__inner { padding: 16px 16px 40px; }

            /* Bell panel */
            .adm-bell-panel {
                width: calc(100vw - 32px);
                right: -8px;
            }

            .admin-page-head h1 { font-size: 1.35rem; }

            .adm-row, .adm-row-3 { grid-template-columns: 1fr; }

            .admin-card {
                padding: 14px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .adm-table {
                font-size: 0.78rem;
                min-width: 600px;
            }

            .adm-table th,
            .adm-table td {
                padding: 10px 8px;
                white-space: nowrap;
            }

            .adm-modal {
                padding: 12px;
                align-items: flex-start;
            }

            .adm-modal__box {
                padding: 20px 16px;
                max-width: 100%;
                max-height: 92vh;
                margin-top: 4vh;
            }

            .adm-modal__title { font-size: 1.15rem; padding-right: 40px; }

            .btn-adm { padding: 8px 14px; font-size: 0.78rem; }

            .adm-toast {
                top: 72px;
                right: 12px;
                left: 12px;
                font-size: 0.78rem;
                padding: 12px 16px;
                max-width: none;
            }
        }

        @media (max-width: 600px) {
            .mkt-tab { padding: 9px 14px; font-size: 0.78rem; }
            .mkt-tab .icon { font-size: 1rem; }
        }
    </style>
</head>
<body class="admin-body">
<div class="admin-layout">

    <!-- ============ SIDEBAR ============ -->
    <aside class="admin-sidebar">
        <div class="admin-sidebar__brand">
            <div>
                <h1>THE ATELIER</h1>
                <p>Admin Panel</p>
            </div>
            <button type="button" class="admin-sidebar__toggle" id="admSidebarToggle" aria-label="Menu">
                <i class="ph ph-list"></i>
            </button>
        </div>

        <ul class="admin-nav">
            <li class="admin-nav__section">Tổng quan</li>
            <li>
                <a href="index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                    <i class="ph ph-house"></i> Trang chủ
                </a>
            </li>

            <li class="admin-nav__section">Bán hàng</li>

            <li>
                <a href="orders.php" class="<?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>">
                    <i class="ph ph-package"></i> Đơn hàng
                    <?php
                    $__badgeOrders = admBadgeCount($menuCounts['pending_orders']);
                    if ($__badgeOrders !== ''):
                    ?>
                        <span class="nav-badge nav-badge--orange"><?php echo $__badgeOrders; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li>
                <a href="returns.php" class="<?php echo $currentPage === 'returns.php' ? 'active' : ''; ?>">
                    <i class="ph ph-arrow-u-up-left"></i> Đơn hoàn tiền
                    <?php
                    $__badgeReturns = admBadgeCount($menuCounts['pending_returns']);
                    if ($__badgeReturns !== ''):
                    ?>
                        <span class="nav-badge nav-badge--red"><?php echo $__badgeReturns; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li>
                <a href="products.php" class="<?php echo $currentPage === 'products.php' ? 'active' : ''; ?>">
                    <i class="ph ph-t-shirt"></i> Sản phẩm
                </a>
            </li>
            <li>
                <a href="categories.php" class="<?php echo $currentPage === 'categories.php' ? 'active' : ''; ?>">
                    <i class="ph ph-squares-four"></i> Danh mục
                </a>
            </li>

            <li class="admin-nav__section">Marketing</li>

            <li>
                <a href="banners.php" class="<?php echo $currentPage === 'banners.php' ? 'active' : ''; ?>">
                    <i class="ph ph-image"></i> Banner quảng cáo
                </a>
            </li>

            <li>
                <a href="discount-events.php"
                   class="<?php echo in_array($currentPage, ['discount-events.php', 'vouchers.php'], true) ? 'active' : ''; ?>">
                    <i class="ph ph-ticket"></i> Khuyến mãi
                    <?php
                    $__mktCount = 0;
                    try {
                        $__mktCount = (int)$pdo->query("
                            SELECT (SELECT COUNT(*) FROM discount_events WHERE active = 1)
                                 + (SELECT COUNT(*) FROM vouchers WHERE valid_from <= CURDATE() AND valid_to >= CURDATE() AND (usage_limit = 0 OR used_count < usage_limit))
                        ")->fetchColumn();
                    } catch (Exception $e) {}
                    if ($__mktCount > 0):
                    ?>
                        <span class="nav-badge nav-badge--orange"><?php echo admBadgeCount($__mktCount); ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li>
                <a href="reviews.php" class="<?php echo $currentPage === 'reviews.php' ? 'active' : ''; ?>">
                    <i class="ph ph-star"></i> Đánh giá
                    <?php
                    $__badgeReviews = admBadgeCount($menuCounts['noreply_reviews']);
                    if ($__badgeReviews !== ''):
                    ?>
                        <span class="nav-badge nav-badge--red"><?php echo $__badgeReviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="admin-nav__section">Hệ thống</li>

            <li>
                <a href="settings.php" class="<?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                    <i class="ph ph-credit-card"></i> Tài khoản nhận tiền
                </a>
            </li>

            <li>
                <a href="tax.php" class="<?php echo $currentPage === 'tax.php' ? 'active' : ''; ?>">
                    <i class="ph ph-chart-bar"></i> Quản lý thuế
                </a>
            </li>

            <li>
                <a href="../index.php" target="_blank">
                    <i class="ph ph-globe"></i> Xem trang chủ
                </a>
            </li>
        </ul>

        <div class="admin-sidebar__footer">
            <div class="admin-sidebar__user">
                Đăng nhập:
                <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></strong>
            </div>
            <a href="../logout.php" class="admin-sidebar__logout">Đăng xuất</a>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="admin-main">

        <!-- ============ TOPBAR (Sticky) ============ -->
        <header class="admin-topbar">
            <h2 class="admin-topbar__title"><?php echo htmlspecialchars($pageTitle); ?></h2>

            <div class="admin-topbar__right">
                <!-- BELL NOTIFICATION -->
                <div class="adm-bell-wrap">
                    <button type="button" id="admBellBtn"
                            class="adm-bell-btn <?php echo $menuCounts['unread_notifs'] > 0 ? 'has-new' : ''; ?>"
                            aria-label="Thông báo">
                        <i class="ph ph-bell"></i>
                        <span class="adm-bell-badge" id="admBellBadge"
                              style="<?php echo $menuCounts['unread_notifs'] > 0 ? '' : 'display:none;'; ?>">
                            <?php echo admBadgeCount($menuCounts['unread_notifs']) ?: '0'; ?>
                        </span>
                    </button>

                    <div class="adm-bell-panel" id="admBellPanel">
                        <div class="adm-bell-head">
                            <b>Thông báo</b>
                            <a href="notifications.php"
                               style="color:var(--admin-accent);font-size:0.78rem;font-weight:600;text-decoration:none;">
                                Xem tất cả &rarr;
                            </a>
                        </div>

                        <div class="adm-bell-list" id="admBellList">
                            <?php if (empty($recentNotifs)): ?>
                                <div class="adm-bell-empty">
                                    <i class="ph ph-bell-slash" style="font-size:2rem;opacity:0.4;display:block;margin-bottom:0.5rem;"></i>
                                    Không có thông báo nào
                                </div>
                            <?php else:
                                $__prioColor = [
                                    'urgent' => '#b91c1c',
                                    'high'   => '#c2410c',
                                    'normal' => '#1d4ed8',
                                    'low'    => '#57534e',
                                ];
                                foreach ($recentNotifs as $__n):
                                    $__c = $__prioColor[$__n['priority']] ?? '#57534e';
                                    // Chuyển đổi icon emoji cũ sang Phosphor Icon nếu có
                                    $__rawIcon = $__n['icon'] ?: 'ph-bell';
                                    $__iconClass = (strpos($__rawIcon, 'ph-') === 0) ? $__rawIcon : 'ph-bell'; 
                                    
                                    $__link = !empty($__n['link']) ? $__n['link'] : 'notifications.php';
                            ?>
                                <a href="<?php echo htmlspecialchars($__link); ?>"
                                   class="adm-bell-item <?php echo $__n['is_read'] ? '' : 'unread'; ?>"
                                   data-id="<?php echo (int)$__n['id']; ?>"
                                   data-link="<?php echo htmlspecialchars($__link); ?>"
                                   onclick="admBellOpenItem(event, <?php echo (int)$__n['id']; ?>, this);">
                                    <span class="adm-bell-icon" style="color:<?php echo $__c; ?>;">
                                        <i class="ph <?php echo $__iconClass; ?>"></i>
                                    </span>
                                    <span class="adm-bell-txt">
                                        <span class="adm-bell-title"><?php echo htmlspecialchars($__n['title']); ?></span>
                                        <span class="adm-bell-time"><?php echo date('d/m H:i', strtotime($__n['created_at'])); ?></span>
                                    </span>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="adm-bell-foot">
                            <?php if ($menuCounts['unread_notifs'] > 0): ?>
                                <button type="button" onclick="admMarkAllRead(event)">
                                    <i class="ph ph-checks"></i> Đánh dấu tất cả đã đọc
                                </button>
                            <?php else: ?>
                                <a href="notifications.php">Xem tất cả thông báo</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ============ CONTENT ============ -->
        <div class="admin-main__inner">

            <?php
            // Flash message
            $flash = $_SESSION['admin_flash'] ?? null;
            unset($_SESSION['admin_flash']);
            if ($flash):
            ?>
                <div class="adm-toast show <?php echo $flash['type'] === 'error' ? 'error' : ''; ?>"
                     id="flashToast">
                    <?php if ($flash['type'] === 'error'): ?>
                        <i class="ph ph-warning-circle" style="font-size:1.2rem;"></i>
                    <?php else: ?>
                        <i class="ph ph-check-circle" style="font-size:1.2rem;"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($flash['msg']); ?>
                </div>
                <script>
                    setTimeout(function () {
                        var t = document.getElementById('flashToast');
                        if (t) t.classList.remove('show');
                    }, 3500);
                </script>
            <?php endif; ?>

            <script>
            /* ============================================================
               1) BELL NOTIFICATION
               ============================================================ */
            (function () {
                var btn   = document.getElementById('admBellBtn');
                var panel = document.getElementById('admBellPanel');
                if (!btn || !panel) return;

                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    panel.classList.toggle('show');
                    if (panel.classList.contains('show')) {
                        btn.classList.remove('has-new');
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!panel.contains(e.target) && !btn.contains(e.target)) {
                        panel.classList.remove('show');
                    }
                });

                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') panel.classList.remove('show');
                });

                setInterval(refreshBellBadge, 45000);

                function refreshBellBadge() {
                    fetch('../ajax/get_notifications.php?limit=1')
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d.ok) return;
                            var badge = document.getElementById('admBellBadge');
                            if (d.unread > 0) {
                                if (!badge) {
                                    badge = document.createElement('span');
                                    badge.id = 'admBellBadge';
                                    badge.className = 'adm-bell-badge';
                                    btn.appendChild(badge);
                                }
                                badge.textContent = d.unread > 9 ? '9+' : d.unread;
                                badge.style.display = '';
                            } else if (badge) {
                                badge.style.display = 'none';
                            }
                        })
                        .catch(function () {});
                }

                window.admBellOpenItem = function (event, id, el) {
                    event.preventDefault();
                    event.stopPropagation();
                    var link = (el && el.dataset.link) ? el.dataset.link : 'notifications.php';
                    var fd = new FormData();
                    fd.append('id', id);
                    fetch('../ajax/mark_notification_read.php', { method: 'POST', body: fd })
                        .then(function () { window.location.href = link; })
                        .catch(function () { window.location.href = link; });
                };

                window.admMarkAllRead = function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    var fd = new FormData();
                    fd.append('all', '1');
                    fetch('../ajax/mark_notification_read.php', { method: 'POST', body: fd })
                        .then(function () {
                            var badge = document.getElementById('admBellBadge');
                            if (badge) badge.style.display = 'none';
                            panel.classList.remove('show');
                            setTimeout(function () { location.reload(); }, 200);
                        })
                        .catch(function () {});
                };
            })();

            /* ============================================================
               2) SIDEBAR TOGGLE (Mobile)
               ============================================================ */
            (function () {
                var sidebar = document.querySelector('.admin-sidebar');
                var toggle  = document.getElementById('admSidebarToggle');
                if (!sidebar || !toggle) return;

                toggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('open');
                    // Đổi icon toggle
                    if (sidebar.classList.contains('open')) {
                        toggle.innerHTML = '<i class="ph ph-x"></i>';
                    } else {
                        toggle.innerHTML = '<i class="ph ph-list"></i>';
                    }
                });

                document.addEventListener('click', function (e) {
                    if (window.innerWidth > 900) return;
                    if (!sidebar.contains(e.target)) {
                        sidebar.classList.remove('open');
                        toggle.innerHTML = '<i class="ph ph-list"></i>';
                    }
                });

                sidebar.querySelectorAll('.admin-nav a').forEach(function (link) {
                    link.addEventListener('click', function () {
                        if (window.innerWidth <= 900) {
                            sidebar.classList.remove('open');
                            toggle.innerHTML = '<i class="ph ph-list"></i>';
                        }
                    });
                });
            })();
            </script>