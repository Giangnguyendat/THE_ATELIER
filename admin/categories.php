<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

/* ============ XỬ LÝ POST ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: categories.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    // XÓA
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Kiểm tra có sản phẩm trong danh mục không
        $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Không thể xóa — còn sản phẩm trong danh mục này.'];
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa danh mục #$id"];
        }
        header('Location: categories.php'); exit;
    }

    // LƯU
    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        // Auto slug nếu trống
        if ($slug === '' && $name !== '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', 
                    iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
            $slug = trim($slug, '-');
        }

        if ($name === '' || $slug === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập đủ Tên và Slug.'];
            header('Location: categories.php'); exit;
        }

        // Check slug trùng (trừ chính nó khi update)
        $dupCheck = $pdo->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
        $dupCheck->execute([$slug, $id]);
        if ($dupCheck->fetch()) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => "Slug '$slug' đã tồn tại."];
            header('Location: categories.php'); exit;
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE categories SET name = ?, slug = ? WHERE id = ?")
                ->execute([$name, $slug, $id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã cập nhật danh mục #$id"];
        } else {
            $pdo->prepare("INSERT INTO categories (name, slug, created_at) VALUES (?, ?, NOW())")
                ->execute([$name, $slug]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã thêm danh mục mới'];
        }
        header('Location: categories.php'); exit;
    }
}

/* ============ LẤY DỮ LIỆU ============ */
$categories = $pdo->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count
    FROM categories c
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="admin-page-head">
    <div>
        <h1>Danh mục sản phẩm</h1>
        <div class="subtitle"><?php echo count($categories); ?> danh mục</div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openCategoryModal()">
        + Thêm danh mục
    </button>
</div>

<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($categories)): ?>
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Chưa có danh mục nào</p>
    <?php else: ?>
        <table class="adm-table">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Tên danh mục</th>
                    <th>Slug</th>
                    <th style="width:120px;">Sản phẩm</th>
                    <th style="width:180px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><b>#<?php echo (int)$c['id']; ?></b></td>
                        <td><b><?php echo htmlspecialchars($c['name']); ?></b></td>
                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;color:var(--admin-text-muted);">
                            <?php echo htmlspecialchars($c['slug']); ?>
                        </td>
                        <td>
                            <?php if ((int)$c['product_count'] > 0): ?>
                                <a href="products.php?cat=<?php echo (int)$c['id']; ?>" class="adm-badge adm-badge--blue">
                                    <?php echo (int)$c['product_count']; ?> sản phẩm
                                </a>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--gray">Trống</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn-adm btn-adm--edit"
                                    onclick='editCategory(<?php echo json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Sửa
                            </button>
                            <form method="POST" style="display:inline;" 
                                  onsubmit="return confirmDelete('Xóa danh mục này?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                <button type="submit" class="btn-adm btn-adm--delete">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- MODAL -->
<div class="adm-modal" id="categoryModal">
    <div class="adm-modal__box" style="max-width:480px;">
        <button type="button" class="adm-modal__close" onclick="closeCategoryModal()">×</button>
        <h2 class="adm-modal__title" id="categoryModalTitle">Thêm danh mục</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="c_id" value="">

            <div class="adm-form-group">
                <label>Tên danh mục *</label>
                <input type="text" name="name" id="c_name" class="adm-input" required maxlength="100"
                       placeholder="VD: Áo nam, Quần nữ, Combo..."
                       oninput="autoSlug(this.value)">
            </div>

            <div class="adm-form-group">
                <label>Slug (đường dẫn không dấu) *</label>
                <input type="text" name="slug" id="c_slug" class="adm-input" required maxlength="100"
                       placeholder="ao-nam, quan-nu, combo">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px;">
                    Dùng cho URL: products.php?category=...
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem"
                        onclick="closeCategoryModal()">Hủy</button>
                <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                    💾 Lưu danh mục
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function autoSlug(name) {
    var slugInput = document.getElementById('c_slug');
    // Chỉ auto nếu user chưa tự nhập
    if (slugInput.dataset.manual === '1') return;
    var slug = name
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // bỏ dấu
        .replace(/đ/g, 'd')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    slugInput.value = slug;
}

document.getElementById('c_slug')?.addEventListener('input', function () {
    this.dataset.manual = '1';
});

function openCategoryModal() {
    document.getElementById('categoryModalTitle').textContent = 'Thêm danh mục mới';
    document.querySelector('#categoryModal form').reset();
    document.getElementById('c_id').value = '';
    document.getElementById('c_slug').dataset.manual = '';
    document.getElementById('categoryModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editCategory(c) {
    document.getElementById('categoryModalTitle').textContent = 'Sửa danh mục #' + c.id;
    document.getElementById('c_id').value   = c.id;
    document.getElementById('c_name').value = c.name;
    document.getElementById('c_slug').value = c.slug;
    document.getElementById('c_slug').dataset.manual = '1';
    document.getElementById('categoryModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('categoryModal').addEventListener('click', function (e) {
    if (e.target === this) closeCategoryModal();
});
</script>

<?php include 'includes/admin_footer.php'; ?>