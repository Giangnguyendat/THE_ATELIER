<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$categories = [];

if (isset($pdo)) {
    try {
        $stmt =$pdo->query("SELECT * FROM categories ORDER BY name");
        $categories =$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Bỏ qua lỗi kết nối
    }
}

// Tính tổng số lượng sản phẩm trong giỏ hàng (nếu có)
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as$item) {
        $cartCount += is_array($item) ? ($item['quantity'] ?? 1) : (int)$item;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Shop Quần Áo - Thời Trang Hiện Đại'; ?></title>
    
    <!-- Google Fonts & Lucide Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <link rel="stylesheet" href="css/style.css">

    <!-- CSS Header Tùy Chỉnh -->
    <style>
        :root {
            --header-bg: rgba(255, 255, 255, 0.9);
            --header-border: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --accent-color: #e11d48;
            --accent-hover: #be123c;
            --bg-hover: #f8fafc;
            --radius-full: 9999px;
            --radius-md: 10px;
            --shadow-sticky: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding-top: 80px; /* Tránh đè content khi header fixed */
        }

        /* --- Header Core --- */
        .site-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 80px;
            background: var(--header-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--header-border);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .header-container {
            max-width: 1280px;
            height: 100%;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2rem;
        }

        /* --- Logo --- */
        .brand-logo {
            font-size: 1.4rem;
            font-weight: 800;
            text-decoration: none;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .brand-logo span {
            color: var(--accent-color);
        }

        /* --- Navigation Menu --- */
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-link {
            position: relative;
            padding: 0.6rem 1rem;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .nav-link:hover {
            background-color: var(--bg-hover);
            color: var(--accent-color);
        }

        /* --- Dropdown Danh Mục --- */
        .nav-dropdown {
            position: relative;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            width: 240px;
            background: #ffffff;
            border: 1px solid var(--header-border);
            border-radius: 12px;
            box-shadow: 0 15px 30px -5px rgba(0,0,0,0.1);
            padding: 0.5rem;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.2s ease;
            list-style: none;
        }

        .nav-dropdown:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: block;
            padding: 0.6rem 1rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background-color: var(--bg-hover);
            color: var(--accent-color);
            padding-left: 1.25rem;
        }

        /* --- Search Bar --- */
        .header-search {
            flex: 1;
            max-width: 320px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.55rem 1rem 0.55rem 2.4rem;
            background: var(--bg-hover);
            border: 1px solid transparent;
            border-radius: var(--radius-full);
            font-size: 0.875rem;
            color: var(--text-primary);
            outline: none;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            background: #ffffff;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 4px rgba(225, 29, 72, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            width: 16px;
            height: 16px;
            pointer-events: none;
        }

        /* --- Actions (Cart & Auth) --- */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Cart Icon */
        .cart-btn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: var(--radius-full);
            background: var(--bg-hover);
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .cart-btn:hover {
            background: var(--text-primary);
            color: #ffffff;
        }

        .cart-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--accent-color);
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            width: 18px;
            height: 18px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }

        /* Auth Buttons */
        .auth-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-auth-login {
            padding: 0.55rem 1.25rem;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border-radius: var(--radius-full);
            transition: all 0.2s ease;
        }

        .btn-auth-login:hover {
            background-color: var(--bg-hover);
        }

        .btn-auth-register {
            padding: 0.55rem 1.25rem;
            background: var(--text-primary);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border-radius: var(--radius-full);
            transition: all 0.2s ease;
        }

        .btn-auth-register:hover {
            background: var(--accent-color);
            transform: translateY(-1px);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.75rem;
            background: var(--bg-hover);
            border-radius: var(--radius-full);
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .user-profile:hover {
            background: #e2e8f0;
        }
                /* --- Atelier Pay Button --- */
        .btn-pay {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1rem;
            background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.85rem;
            text-decoration: none;
            border-radius: var(--radius-full);
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
            transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            letter-spacing: -0.01em;
        }

        .btn-pay::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.25) 0%, transparent 50%);
            opacity: 0;
            transition: opacity 0.22s ease;
            pointer-events: none;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(225, 29, 72, 0.45);
        }

        .btn-pay:hover::before { opacity: 1; }

        .btn-pay:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(225, 29, 72, 0.35);
        }

        .btn-pay svg {
            width: 16px;
            height: 16px;
            stroke-width: 2.5;
        }

        /* Pulse animation nhẹ cho icon */
        .btn-pay svg {
            animation: payPulse 2.4s ease-in-out infinite;
        }

        @keyframes payPulse {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.08); }
        }

        /* Badge số dư mini (nếu muốn dùng) */
        .btn-pay__balance {
            font-family: 'IBM Plex Mono', 'Courier New', monospace;
            font-size: 0.78rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.22);
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-full);
            margin-left: 0.15rem;
        }

        /* Responsive — thu gọn trên mobile */
        @media (max-width: 768px) {
            .btn-pay {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
            }
            .btn-pay__label { display: none; }  /* Ẩn chữ "Atelier Pay", chỉ giữ icon */
        }

        .btn-logout {
            color: var(--text-secondary);
            padding: 0.5rem;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .btn-logout:hover {
            color: var(--accent-color);
            background: #ffe4e6;
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .header-search { display: none; }
        }

        @media (max-width: 768px) {
            .nav-menu { display: none; } /* Có thể tích hợp mobile drawer ở đây */
            body { padding-top: 70px; }
            .site-header { height: 70px; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <div class="header-container">
        
        <!-- Logo -->
        <a href="index.php" class="brand-logo">
            <i data-lucide="shopping-bag" style="color: var(--accent-color);"></i>
            THE ATELIER<span>.</span>
        </a>

        <!-- Navigation Menu -->
        <nav>
            <ul class="nav-menu">
                <li><a href="index.php" class="nav-link">Trang chủ</a></li>
                <li class="nav-dropdown">
                    <a href="products.php" class="nav-link">
                        Sản phẩm 
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px;"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="products.php" class="dropdown-item">Tất cả sản phẩm</a></li>
                        <?php foreach ($categories as$cat): ?>
                            <li>
                                <a href="products.php?category=<?php echo $cat['id']; ?>" class="dropdown-item">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            </ul>
        </nav>

        <!-- Search Bar -->
        <form action="products.php" method="GET" class="header-search">
            <i data-lucide="search" class="search-icon"></i>
            <input type="text" name="search" placeholder="Tìm kiếm sản phẩm..." class="search-input">
        </form>

        <!-- Right Actions (Cart & Auth) -->
        <div class="header-actions">
            <!-- Giỏ Hàng -->
            <a href="cart.php" class="cart-btn" title="Giỏ hàng">
                <i data-lucide="shopping-cart" style="width: 20px; height: 20px;"></i>
                <?php if ($cartCount > 0): ?>
                    <span class="cart-badge"><?php echo $cartCount > 99 ? '99+' :$cartCount; ?></span>
                <?php endif; ?>
            </a>

<!-- Khối Tài Khoản -->
            <div class="auth-group">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Bấm vào cả khối profile hoặc tên người dùng để sang trang account.php -->
                                        <a href="account.php" class="user-profile" title="Trang cá nhân & Lịch sử đơn hàng">
                        <i data-lucide="user" style="width: 16px; height: 16px;"></i>
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Tài khoản'); ?></span>
                    </a>

                    <!-- Nút Atelier Pay -->
                    <a href="wallet.php" class="btn-pay" title="Ví ATELIER Pay">
                        <i data-lucide="wallet"></i>
                        <span class="btn-pay__label">Atelier Pay</span>
                    </a>

                    <a href="logout.php" class="btn-logout" title="Đăng xuất">
                        <i data-lucide="log-out" style="width: 18px; height: 18px;"></i>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn-auth-login">Đăng nhập</a>
                    <a href="register.php" class="btn-auth-register">Đăng ký</a>
                <?php endif; ?>
            </div>
        </div>

    </div>
</header>

<!-- Khởi tạo Icon Lucide -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
    });
</script>

<main>