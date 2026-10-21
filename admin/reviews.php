<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

/* ============ HELPER: adminImg nếu chưa có ============ */
if (!function_exists('adminImg')) {
    function adminImg($url) {
        if (empty($url)) return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        return '../' . ltrim($url, '/');
    }
}

/* ============ XỬ LÝ POST ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: reviews.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    /* XÓA ĐÁNH GIÁ */
    if ($action === 'delete_review') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Xóa ảnh vật lý
            $imgStmt = $pdo->prepare("SELECT image_url FROM review_images WHERE review_id = ?");
            $imgStmt->execute([$id]);
            foreach ($imgStmt->fetchAll(PDO::FETCH_COLUMN) as $imgPath) {
                $fullPath = __DIR__ . '/../' . $imgPath;
                if (file_exists($fullPath)) @unlink($fullPath);
            }
            $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa đánh giá #$id"];
        }
        header('Location: reviews.php'); exit;
    }

    /* PHẢN HỒI */
    if ($action === 'reply') {
        $id    = (int)($_POST['id'] ?? 0);
        $reply = trim($_POST['admin_reply'] ?? '');
        if ($id > 0) {
            if ($reply === '') {
                // Xóa phản hồi nếu để trống
                $pdo->prepare("UPDATE reviews SET admin_reply = NULL WHERE id = ?")->execute([$id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa phản hồi #$id"];
            } else {
                $pdo->prepare("UPDATE reviews SET admin_reply = ? WHERE id = ?")->execute([$reply, $id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã gửi phản hồi cho đánh giá #$id"];
            }
        }
        // Redirect giữ filter
        $back = $_SERVER['HTTP_REFERER'] ?? 'reviews.php';
        header('Location: ' . $back);
        exit;
    }
}

/* ============ LẤY DỮ LIỆU ============ */
$filter = $_GET['filter'] ?? '';
$search = trim($_GET['q'] ?? '');
$star   = isset($_GET['star']) ? (int)$_GET['star'] : 0;
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = []; $params = [];

if ($filter === '5star')       $where[] = 'r.rating = 5';
elseif ($filter === 'low')     $where[] = 'r.rating <= 2';
elseif ($filter === 'noreply') $where[] = '(r.admin_reply IS NULL OR r.admin_reply = "")';
elseif ($filter === 'replied') $where[] = 'r.admin_reply IS NOT NULL AND r.admin_reply != ""';

if ($star >= 1 && $star <= 5) {
    $where[] = 'r.rating = ?';
    $params[] = $star;
}

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR r.content LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Đếm tổng
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN products p ON r.product_id = p.id
    $whereSQL
");
$countStmt->execute($params);
$totalReviews = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalReviews / $perPage));
$page = min(max(1, $page), $totalPages);
$offset = ($page - 1) * $perPage;

// Query chính
$stmt = $pdo->prepare("
    SELECT r.*,
           u.full_name AS user_name, u.email AS user_email, u.phone AS user_phone,
           p.name AS product_name, p.image_url AS product_image
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN products p ON r.product_id = p.id
    $whereSQL
    ORDER BY r.created_at DESC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ảnh của reviews
$reviewImages = [];
if (!empty($reviews)) {
    $ids = array_column($reviews, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $imgStmt = $pdo->prepare("SELECT review_id, image_url FROM review_images WHERE review_id IN ($ph)");
    $imgStmt->execute($ids);
    foreach ($imgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reviewImages[$row['review_id']][] = $row['image_url'];
    }
}

// Thống kê chung
$stats = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        COALESCE(AVG(rating), 0) AS avg_r,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS star5,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS star4,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS star3,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS star2,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS star1,
        SUM(CASE WHEN admin_reply IS NULL OR admin_reply = '' THEN 1 ELSE 0 END) AS noreply
    FROM reviews
")->fetch(PDO::FETCH_ASSOC);

// Preserve filter trong phân trang
$qs = $_GET;
unset($qs['page']);
?>

<style>
/* ===== ADMIN REVIEWS TABLE ===== */
.rv-table-wrap {
    background: #fff;
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    overflow: hidden;
}

.rv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.rv-table thead th {
    background: var(--admin-sidebar-bg);
    color: #fffdf9;
    text-align: left;
    padding: 0.75rem 0.85rem;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 500;
    white-space: nowrap;
}

.rv-table tbody tr.rv-row {
    border-bottom: 1px solid var(--admin-border);
    cursor: pointer;
    transition: background 0.15s;
}
.rv-table tbody tr.rv-row:hover { background: #fbf7ef; }
.rv-table tbody tr.rv-row.is-open { background: #f8f4eb; }

.rv-table td {
    padding: 0.85rem 0.85rem;
    vertical-align: middle;
}

/* Cột sản phẩm */
.rv-td-product { display: flex; gap: 0.65rem; align-items: center; min-width: 200px; }
.rv-td-product img {
    width: 42px; height: 42px;
    object-fit: cover;
    border-radius: 6px;
    flex: none;
    background: #f1f5f9;
}
.rv-td-product .info { min-width: 0; }
.rv-td-product .pname {
    font-weight: 600;
    font-size: 0.82rem;
    color: var(--admin-text);
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.rv-td-product .pid {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    color: var(--admin-text-muted);
    margin-top: 2px;
}

/* Cột user */
.rv-td-user { min-width: 160px; }
.rv-td-user .uname { font-weight: 600; color: var(--admin-text); font-size: 0.82rem; }
.rv-td-user .uemail {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    color: var(--admin-text-muted);
    margin-top: 2px;
    word-break: break-all;
}

/* Cột rating */
.rv-td-rating { text-align: center; white-space: nowrap; }
.rv-td-rating .stars {
    font-size: 1.05rem;
    letter-spacing: 0.05rem;
    color: #f59e0b;
    line-height: 1;
}
.rv-td-rating .stars .empty { color: #d1d5db; }
.rv-td-rating .num {
    display: block;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: var(--admin-text-muted);
    margin-top: 3px;
}

/* Cột nội dung */
.rv-td-content {
    max-width: 320px;
    min-width: 180px;
}
.rv-td-content .text {
    font-size: 0.82rem;
    color: #4b5563;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.rv-td-content .text.empty {
    font-style: italic;
    color: var(--admin-text-muted);
}
.rv-td-content .imgs-count {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    color: var(--admin-text-muted);
    margin-top: 4px;
}

/* Cột ngày */
.rv-td-date {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: var(--admin-text-muted);
    white-space: nowrap;
}

/* Cột phản hồi */
.rv-td-reply { text-align: center; }

.rv-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 12px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    white-space: nowrap;
}
.rv-badge--replied { background: #dcfce7; color: #166534; }
.rv-badge--noreply { background: #fef3c7; color: #92400e; }

/* Cột thao tác */
.rv-td-actions {
    text-align: right;
    white-space: nowrap;
}
.rv-td-actions .btn-adm {
    padding: 0.4rem 0.7rem;
    font-size: 0.72rem;
}

/* Expand arrow */
.rv-expand-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #f3f4f6;
    color: var(--admin-text-muted);
    font-size: 0.7rem;
    transition: transform 0.25s, background 0.2s, color 0.2s;
    flex: none;
}
.rv-row.is-open .rv-expand-icon {
    transform: rotate(180deg);
    background: var(--admin-accent);
    color: #fff;
}

/* Expand panel (chi tiết + reply) */
.rv-detail-row { display: none; }
.rv-detail-row.is-open { display: table-row; }

.rv-detail-cell {
    background: #fafafa;
    padding: 0 !important;
    border-bottom: 2px solid var(--admin-accent);
}

.rv-detail-inner {
    padding: 1.25rem 1.5rem;
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 1.5rem;
}

@media (max-width: 900px) {
    .rv-detail-inner { grid-template-columns: 1fr; }
}

.rv-detail-title {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--admin-text-muted);
    margin: 0 0 0.6rem;
}

.rv-detail-content {
    background: #fff;
    border: 1px solid var(--admin-border);
    border-radius: 6px;
    padding: 1rem 1.15rem;
    font-size: 0.88rem;
    line-height: 1.65;
    color: var(--admin-text);
    white-space: pre-wrap;
    word-break: break-word;
}

.rv-detail-content.empty {
    font-style: italic;
    color: var(--admin-text-muted);
}

/* Ảnh trong detail */
.rv-detail-images {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.85rem;
}
.rv-detail-images img {
    width: 90px;
    height: 90px;
    object-fit: cover;
    border: 1px solid var(--admin-border);
    border-radius: 6px;
    cursor: zoom-in;
    transition: transform 0.2s;
}
.rv-detail-images img:hover { transform: scale(1.05); }

/* Reply form */
.rv-reply-form textarea {
    width: 100%;
    min-height: 90px;
    padding: 0.75rem 0.9rem;
    border: 1px solid var(--admin-border);
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.88rem;
    line-height: 1.55;
    resize: vertical;
    background: #fff;
    transition: border-color 0.2s;
}
.rv-reply-form textarea:focus {
    outline: none;
    border-color: var(--admin-accent);
}

.rv-reply-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.75rem;
    flex-wrap: wrap;
}

.rv-reply-existing {
    background: #ecfdf5;
    border-left: 3px solid #166534;
    padding: 0.75rem 1rem;
    border-radius: 4px;
    margin-bottom: 0.85rem;
    font-size: 0.85rem;
    line-height: 1.55;
    color: #064e3b;
    position: relative;
}
.rv-reply-existing__label {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #166534;
    margin-bottom: 0.35rem;
    display: block;
}
.rv-reply-existing__date {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.68rem;
    color: #6b7280;
    margin-top: 0.35rem;
    display: block;
}

/* Thống kê bar */
.admin-rv-stats {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 2rem;
    padding: 1.5rem;
    background: #fff;
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    margin-bottom: 1.25rem;
}
.admin-rv-stats__score {
    text-align: center;
    border-right: 1px solid var(--admin-border);
    padding-right: 1.5rem;
}
.admin-rv-stats__avg {
    font-family: 'Fraunces', serif;
    font-size: 3rem;
    font-weight: 700;
    color: var(--admin-accent);
    line-height: 1;
}
.admin-rv-stats__stars {
    font-size: 1.3rem;
    margin: 0.35rem 0;
    letter-spacing: 0.15rem;
}
.admin-rv-stats__total {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: var(--admin-text-muted);
    letter-spacing: 0.05em;
}
.admin-rv-stats__bars {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    justify-content: center;
}
.admin-rv-bar {
    display: grid;
    grid-template-columns: 42px 1fr 42px;
    gap: 0.75rem;
    align-items: center;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.75rem;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 4px;
    transition: background 0.15s;
}
.admin-rv-bar:hover { background: #f8f4eb; }
.admin-rv-bar.active { background: #fef3c7; }
.admin-rv-bar__label { color: var(--admin-text-muted); }
.admin-rv-bar__count { color: var(--admin-text-muted); text-align: right; }
.admin-rv-bar__track {
    height: 8px;
    background: var(--admin-border);
    border-radius: 4px;
    overflow: hidden;
}
.admin-rv-bar__fill {
    height: 100%;
    background: #f59e0b;
    border-radius: 4px;
    transition: width 0.3s;
}

@media (max-width: 900px) {
    .admin-rv-stats { grid-template-columns: 1fr; }
    .admin-rv-stats__score {
        border-right: none;
        border-bottom: 1px solid var(--admin-border);
        padding-right: 0;
        padding-bottom: 1rem;
    }
    .rv-table-wrap { overflow-x: auto; }
    .rv-table { min-width: 900px; }
}
</style>

<div class="admin-page-head">
    <div>
        <h1>Quản lý đánh giá</h1>
        <div class="subtitle">
            <?php echo (int)$stats['total']; ?> đánh giá ·
            TB <?php echo number_format((float)$stats['avg_r'], 1); ?>/5
            <?php if ((int)$stats['noreply'] > 0): ?>
                · <span style="color:#d92400e;"><?php echo (int)$stats['noreply']; ?> chưa phản hồi</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- THỐNG KÊ + FILTER THEO SAO -->
<div class="admin-rv-stats">
    <div class="admin-rv-stats__score">
        <div class="admin-rv-stats__avg"><?php echo number_format((float)$stats['avg_r'], 1); ?></div>
        <div class="admin-rv-stats__stars">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <span style="color:<?php echo $i <= round((float)$stats['avg_r']) ? '#f59e0b' : '#d1d5db'; ?>">★</span>
            <?php endfor; ?>
        </div>
        <div class="admin-rv-stats__total">Tổng <?php echo (int)$stats['total']; ?> đánh giá</div>
    </div>

    <div class="admin-rv-stats__bars">
        <?php
        $total = max(1, (int)$stats['total']);
        for ($s = 5; $s >= 1; $s--):
            $count = (int)$stats['star' . $s];
            $pct = $count / $total * 100;
            $isActive = ($star === $s);
        ?>
            <div class="admin-rv-bar <?php echo $isActive ? 'active' : ''; ?>"
                 onclick="filterByStar(<?php echo $s; ?>)">
                <span class="admin-rv-bar__label"><?php echo $s; ?> ★</span>
                <div class="admin-rv-bar__track">
                    <div class="admin-rv-bar__fill" style="width:<?php echo $pct; ?>%"></div>
                </div>
                <span class="admin-rv-bar__count"><?php echo $count; ?></span>
            </div>
        <?php endfor; ?>
    </div>
</div>

<!-- FILTER -->
<div class="admin-card" style="padding:1rem 1.25rem;">
    <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:2;min-width:200px;">
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;">
                Tìm kiếm
            </label>
            <input type="text" name="q" class="adm-input" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Sản phẩm, người dùng, nội dung...">
        </div>

        <div style="flex:1;min-width:180px;">
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;">
                Bộ lọc
            </label>
            <select name="filter" class="adm-select">
                <option value="">Tất cả</option>
                <option value="5star"   <?php echo $filter === '5star'   ? 'selected' : ''; ?>>Đánh giá 5 sao</option>
                <option value="low"     <?php echo $filter === 'low'     ? 'selected' : ''; ?>>Đánh giá thấp (≤ 2★)</option>
                <option value="noreply" <?php echo $filter === 'noreply' ? 'selected' : ''; ?>>Chưa phản hồi</option>
                <option value="replied" <?php echo $filter === 'replied' ? 'selected' : ''; ?>>Đã phản hồi</option>
            </select>
        </div>

        <?php if ($star >= 1): ?>
            <input type="hidden" name="star" value="<?php echo (int)$star; ?>">
        <?php endif; ?>

        <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
        <?php if ($search !== '' || $filter !== '' || $star > 0): ?>
            <a href="reviews.php" class="btn-adm btn-adm--ghost">Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- KẾT QUẢ -->
<div style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;color:var(--admin-text-muted);
            margin-bottom:0.85rem;letter-spacing:0.02em;">
    Hiển thị <b style="color:var(--admin-text);"><?php echo count($reviews); ?></b>
    / <b style="color:var(--admin-text);"><?php echo (int)$totalReviews; ?></b> đánh giá
    <?php if ($star > 0): ?>
        · Lọc <b><?php echo $star; ?> sao</b>
    <?php endif; ?>
</div>

<!-- BẢNG -->
<?php if (empty($reviews)): ?>
    <div class="admin-card">
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Không có đánh giá nào phù hợp</p>
    </div>
<?php else: ?>
    <div class="rv-table-wrap">
        <table class="rv-table">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th style="width:60px;">ID</th>
                    <th>Sản phẩm</th>
                    <th>Khách hàng</th>
                    <th style="width:90px;text-align:center;">Đánh giá</th>
                    <th>Nội dung</th>
                    <th style="width:100px;">Ngày</th>
                    <th style="width:110px;text-align:center;">Phản hồi</th>
                    <th style="width:130px;text-align:right;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $rv):
                    $rvId = (int)$rv['id'];
                    $imgs = $reviewImages[$rvId] ?? [];
                    $hasReply = !empty($rv['admin_reply']);
                ?>
                    <!-- ROW CHÍNH -->
                    <tr class="rv-row" data-id="<?php echo $rvId; ?>" onclick="toggleDetail(<?php echo $rvId; ?>)">
                        <td>
                            <span class="rv-expand-icon">▼</span>
                        </td>
                        <td>
                            <b style="font-family:'IBM Plex Mono',monospace;">#<?php echo $rvId; ?></b>
                        </td>
                        <td>
                            <div class="rv-td-product">
                                <img src="<?php echo adminImg($rv['product_image']); ?>" alt="">
                                <div class="info">
                                    <div class="pname"><?php echo htmlspecialchars($rv['product_name'] ?? '—'); ?></div>
                                    <div class="pid">SP #<?php echo (int)$rv['product_id']; ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="rv-td-user">
                                <div class="uname"><?php echo htmlspecialchars($rv['user_name'] ?? 'Khách'); ?></div>
                                <div class="uemail"><?php echo htmlspecialchars($rv['user_email'] ?? ''); ?></div>
                            </div>
                        </td>
                        <td class="rv-td-rating">
                            <div class="stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="<?php echo $i <= (int)$rv['rating'] ? '' : 'empty'; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <span class="num"><?php echo (int)$rv['rating']; ?>/5</span>
                        </td>
                        <td class="rv-td-content">
                            <?php if (!empty($rv['content'])): ?>
                                <div class="text"><?php echo htmlspecialchars($rv['content']); ?></div>
                            <?php else: ?>
                                <div class="text empty">(Không có nội dung)</div>
                            <?php endif; ?>
                            <?php if (!empty($imgs)): ?>
                                <div class="imgs-count">📷 <?php echo count($imgs); ?> ảnh</div>
                            <?php endif; ?>
                        </td>
                        <td class="rv-td-date">
                            <?php echo date('d/m/Y', strtotime($rv['created_at'])); ?><br>
                            <span style="font-size:0.65rem;"><?php echo date('H:i', strtotime($rv['created_at'])); ?></span>
                        </td>
                        <td class="rv-td-reply">
                            <?php if ($hasReply): ?>
                                <span class="rv-badge rv-badge--replied">✓ Đã trả lời</span>
                            <?php else: ?>
                                <span class="rv-badge rv-badge--noreply">Chưa</span>
                            <?php endif; ?>
                        </td>
                        <td class="rv-td-actions" onclick="event.stopPropagation();">
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirmDelete('Xóa đánh giá #<?php echo $rvId; ?>?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete_review">
                                <input type="hidden" name="id" value="<?php echo $rvId; ?>">
                                <button type="submit" class="btn-adm btn-adm--delete">🗑 Xóa</button>
                            </form>
                        </td>
                    </tr>

                    <!-- ROW CHI TIẾT (ẩn mặc định) -->
                    <tr class="rv-detail-row" id="rv-detail-<?php echo $rvId; ?>">
                        <td colspan="9" class="rv-detail-cell">
                            <div class="rv-detail-inner">

                                <!-- LEFT: Nội dung đầy đủ + ảnh -->
                                <div>
                                    <h4 class="rv-detail-title">📝 Nội dung đánh giá đầy đủ</h4>
                                    <?php if (!empty($rv['content'])): ?>
                                        <div class="rv-detail-content"><?php echo htmlspecialchars($rv['content']); ?></div>
                                    <?php else: ?>
                                        <div class="rv-detail-content empty">Khách hàng không để lại nội dung.</div>
                                    <?php endif; ?>

                                    <?php if (!empty($imgs)): ?>
                                        <h4 class="rv-detail-title" style="margin-top:1.25rem;">📷 Ảnh khách hàng gửi (<?php echo count($imgs); ?>)</h4>
                                        <div class="rv-detail-images">
                                            <?php foreach ($imgs as $img): ?>
                                                <img src="<?php echo adminImg($img); ?>" alt="Ảnh review"
                                                     onclick="window.open(this.src, '_blank')">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- RIGHT: Phản hồi -->
                                <div>
                                    <h4 class="rv-detail-title">💬 Phản hồi của shop</h4>

                                    <?php if ($hasReply): ?>
                                        <div class="rv-reply-existing">
                                            <span class="rv-reply-existing__label">Đã phản hồi</span>
                                            <?php echo nl2br(htmlspecialchars($rv['admin_reply'])); ?>
                                            <span class="rv-reply-existing__date">
                                                Cập nhật: <?php echo date('d/m/Y H:i', strtotime($rv['updated_at'] ?? $rv['created_at'])); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" class="rv-reply-form"
                                          onsubmit="event.stopPropagation();">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="reply">
                                        <input type="hidden" name="id" value="<?php echo $rvId; ?>">

                                        <textarea name="admin_reply" rows="4"
                                                  placeholder="Cảm ơn bạn đã tin tưởng và ủng hộ THE ATELIER..."><?php echo htmlspecialchars($rv['admin_reply'] ?? ''); ?></textarea>

                                        <div class="rv-reply-actions">
                                            <button type="submit" class="btn-adm btn-adm--primary">
                                                💬 <?php echo $hasReply ? 'Cập nhật' : 'Gửi phản hồi'; ?>
                                            </button>
                                            <?php if ($hasReply): ?>
                                                <button type="button" class="btn-adm btn-adm--delete"
                                                        onclick="deleteReply(<?php echo $rvId; ?>)">
                                                    🗑 Xóa phản hồi
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- PHÂN TRANG -->
    <?php if ($totalPages > 1): ?>
        <nav class="pagination-nav">
            <ul class="pagination">
                <?php
                if ($page > 1):
                    $qs['page'] = $page - 1;
                ?>
                    <li><a href="?<?php echo http_build_query($qs); ?>" class="page-link">&laquo; Trước</a></li>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);

                if ($start > 1): ?>
                    <li><a href="?<?php echo http_build_query(array_merge($qs, ['page' => 1])); ?>" class="page-link">1</a></li>
                    <?php if ($start > 2): ?>
                        <li><span class="page-link" style="pointer-events:none;">…</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++):
                    $qs['page'] = $i;
                ?>
                    <li>
                        <a href="?<?php echo http_build_query($qs); ?>"
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?>
                        <li><span class="page-link" style="pointer-events:none;">…</span></li>
                    <?php endif; ?>
                    <li><a href="?<?php echo http_build_query(array_merge($qs, ['page' => $totalPages])); ?>" class="page-link"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>

                <?php if ($page < $totalPages):
                    $qs['page'] = $page + 1;
                ?>
                    <li><a href="?<?php echo http_build_query($qs); ?>" class="page-link">Sau &raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>

<?php endif; ?>

<!-- HIDDEN FORM xóa phản hồi -->
<form id="deleteReplyForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="reply">
    <input type="hidden" name="id" id="delReplyId" value="">
    <input type="hidden" name="admin_reply" value="">
</form>

<script>
/* ============================================================
   TOGGLE CHI TIẾT
   ============================================================ */
function toggleDetail(id) {
    var row = document.querySelector('.rv-row[data-id="' + id + '"]');
    var detail = document.getElementById('rv-detail-' + id);
    if (!row || !detail) return;

    var isOpen = detail.classList.contains('is-open');

    // Đóng tất cả các row đang mở khác (optional — comment nếu muốn mở nhiều)
    // document.querySelectorAll('.rv-detail-row.is-open').forEach(function (r) {
    //     r.classList.remove('is-open');
    // });
    // document.querySelectorAll('.rv-row.is-open').forEach(function (r) {
    //     r.classList.remove('is-open');
    // });

    if (isOpen) {
        detail.classList.remove('is-open');
        row.classList.remove('is-open');
    } else {
        detail.classList.add('is-open');
        row.classList.add('is-open');
    }
}

/* ============================================================
   XÓA PHẢN HỒI
   ============================================================ */
function deleteReply(id) {
    if (!confirm('Xóa phản hồi này? Khách hàng sẽ không thấy phản hồi nữa.')) return;
    document.getElementById('delReplyId').value = id;
    document.getElementById('deleteReplyForm').submit();
}

/* ============================================================
   FILTER THEO SAO
   ============================================================ */
function filterByStar(star) {
    var params = new URLSearchParams(window.location.search);
    if (params.get('star') === String(star)) {
        params.delete('star');
    } else {
        params.set('star', star);
    }
    params.delete('page');
    window.location.search = params.toString();
}

/* ============================================================
   CHẶN CLICK TRONG FORM KHÔNG ĐÓNG ROW
   ============================================================ */
document.querySelectorAll('.rv-detail-inner').forEach(function (inner) {
    inner.addEventListener('click', function (e) {
        e.stopPropagation();
    });
});

/* ============================================================
   AUTO OPEN ROW NẾU URL CÓ #rv-ID
   ============================================================ */
(function () {
    var hash = window.location.hash;
    var m = hash.match(/^#rv-(\d+)$/);
    if (m) {
        var id = m[1];
        setTimeout(function () {
            toggleDetail(id);
            var el = document.getElementById('rv-detail-' + id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 200);
    }
})();
</script>

<?php include 'includes/admin_footer.php'; ?>