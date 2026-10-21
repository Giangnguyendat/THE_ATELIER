<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: banners.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM banners WHERE id = ?")->execute([$id]);
        $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã xóa banner #' . $id];
        header('Location: banners.php'); exit;
    }

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $image    = trim($_POST['image_url'] ?? '');
        $link     = trim($_POST['link'] ?? '');
        $position = $_POST['position'] ?? 'homepage';
        $active   = (int)($_POST['active'] ?? 1) ? 1 : 0;

        if (!in_array($position, ['homepage', 'sidebar_left', 'sidebar_right'], true)) {
            $position = 'homepage';
        }

        if ($title === '' || $image === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập đủ Tiêu đề + Ảnh.'];
            header('Location: banners.php'); exit;
        }

        if ($id > 0) {
            $pdo->prepare("
                UPDATE banners SET title=?, image_url=?, link=?, position=?, active=?
                WHERE id=?
            ")->execute([$title, $image, $link, $position, $active, $id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã cập nhật banner #' . $id];
        } else {
            $pdo->prepare("
                INSERT INTO banners (title, image_url, link, position, active, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([$title, $image, $link, $position, $active]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã thêm banner mới'];
        }
        header('Location: banners.php'); exit;
    }
}

$positionFilter = $_GET['pos'] ?? '';
$where = ''; $params = [];
if (in_array($positionFilter, ['homepage','sidebar_left','sidebar_right'], true)) {
    $where = 'WHERE position = ?';
    $params[] = $positionFilter;
}
$stmt = $pdo->prepare("SELECT * FROM banners $where ORDER BY created_at DESC");
$stmt->execute($params);
$banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

$posLabels = [
    'homepage'     => ['Trang chủ', 'adm-badge--blue'],
    'sidebar_left' => ['Sidebar trái', 'adm-badge--orange'],
    'sidebar_right'=> ['Sidebar phải', 'adm-badge--orange'],
];
?>

<div class="admin-page-head">
    <div>
        <h1>Banner quảng cáo</h1>
        <div class="subtitle"><?php echo count($banners); ?> banner</div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openBannerModal()">
        + Thêm banner
    </button>
</div>

<!-- Filter -->
<div class="admin-card" style="padding:0.85rem 1.25rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
    <a href="banners.php" class="btn-adm <?php echo $positionFilter === '' ? 'btn-adm--primary' : 'btn-adm--ghost'; ?>">Tất cả</a>
    <a href="banners.php?pos=homepage" class="btn-adm <?php echo $positionFilter === 'homepage' ? 'btn-adm--primary' : 'btn-adm--ghost'; ?>">Trang chủ</a>
    <a href="banners.php?pos=sidebar_left" class="btn-adm <?php echo $positionFilter === 'sidebar_left' ? 'btn-adm--primary' : 'btn-adm--ghost'; ?>">Sidebar trái</a>
    <a href="banners.php?pos=sidebar_right" class="btn-adm <?php echo $positionFilter === 'sidebar_right' ? 'btn-adm--primary' : 'btn-adm--ghost'; ?>">Sidebar phải</a>
</div>

<!-- Grid banner -->
<?php if (empty($banners)): ?>
    <div class="admin-card"><p style="text-align:center;padding:2rem;color:var(--admin-text-muted);">Chưa có banner nào</p></div>
<?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;">
        <?php foreach ($banners as $b):
            $posInfo = $posLabels[$b['position']] ?? [$b['position'], 'adm-badge--gray'];
        ?>
            <div class="admin-card" style="padding:0;overflow:hidden;">
                <img src="../<?php echo htmlspecialchars($b['image_url']); ?>"
                     style="width:100%;height:160px;object-fit:cover;background:#f1f5f9;"
                     onerror="this.src='https://via.placeholder.com/320x160?text=No+Image'">

                <div style="padding:1rem;">
                    <div style="display:flex;justify-content:space-between;gap:0.5rem;margin-bottom:0.5rem;">
                        <b style="font-size:0.95rem;"><?php echo htmlspecialchars($b['title']); ?></b>
                        <span class="adm-badge <?php echo $posInfo[1]; ?>"><?php echo $posInfo[0]; ?></span>
                    </div>

                    <div style="font-size:0.75rem;color:var(--admin-text-muted);margin-bottom:0.35rem;">
                        🔗 <?php echo htmlspecialchars($b['link'] ?: 'Không có'); ?>
                    </div>

                    <div style="margin-bottom:0.85rem;">
                        <?php if ($b['active']): ?>
                            <span class="adm-badge adm-badge--green">Đang hiện</span>
                        <?php else: ?>
                            <span class="adm-badge adm-badge--gray">Đang ẩn</span>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;gap:0.5rem;">
                        <button type="button" class="btn-adm btn-adm--edit" style="flex:1;justify-content:center;"
                                onclick='editBanner(<?php echo json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            Sửa
                        </button>
                        <form method="POST" style="flex:1;" onsubmit="return confirmDelete('Xóa banner này?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                            <button type="submit" class="btn-adm btn-adm--delete" style="width:100%;justify-content:center;">
                                Xóa
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal -->
<div class="adm-modal" id="bannerModal">
    <div class="adm-modal__box">
        <button type="button" class="adm-modal__close" onclick="closeBannerModal()">×</button>
        <h2 class="adm-modal__title" id="bannerModalTitle">Thêm banner</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="b_id" value="">

            <div class="adm-form-group">
                <label>Tiêu đề *</label>
                <input type="text" name="title" id="b_title" class="adm-input" required maxlength="200">
            </div>

            <div class="adm-form-group">
                <label>Ảnh (URL hoặc đường dẫn) *</label>
                <input type="text" name="image_url" id="b_image_url" class="adm-input" required
                       placeholder="images/products/banners/banner1.jpg">
            </div>

            <div class="adm-form-group">
                <label>Link khi bấm vào</label>
                <input type="text" name="link" id="b_link" class="adm-input" placeholder="products.php">
            </div>

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Vị trí</label>
                    <select name="position" id="b_position" class="adm-select">
                        <option value="homepage">Trang chủ</option>
                        <option value="sidebar_left">Sidebar trái</option>
                        <option value="sidebar_right">Sidebar phải</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label>Trạng thái</label>
                    <select name="active" id="b_active" class="adm-select">
                        <option value="1">Hiển thị</option>
                        <option value="0">Ẩn</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem"
                        onclick="closeBannerModal()">Hủy</button>
                <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                    💾 Lưu banner
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openBannerModal() {
    document.getElementById('bannerModalTitle').textContent = 'Thêm banner mới';
    document.querySelector('#bannerModal form').reset();
    document.getElementById('b_id').value = '';
    document.getElementById('bannerModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editBanner(b) {
    document.getElementById('bannerModalTitle').textContent = 'Sửa banner #' + b.id;
    document.getElementById('b_id').value        = b.id;
    document.getElementById('b_title').value     = b.title;
    document.getElementById('b_image_url').value = b.image_url;
    document.getElementById('b_link').value      = b.link || '';
    document.getElementById('b_position').value  = b.position || 'homepage';
    document.getElementById('b_active').value    = b.active;
    document.getElementById('bannerModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeBannerModal() {
    document.getElementById('bannerModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('bannerModal').addEventListener('click', function (e) {
    if (e.target === this) closeBannerModal();
});
</script>

<?php include 'includes/admin_footer.php'; ?>