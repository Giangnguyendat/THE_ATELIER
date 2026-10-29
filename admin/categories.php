<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

// Tự động tạo bảng homepage_sections nếu chưa có
$pdo->exec("CREATE TABLE IF NOT EXISTS `homepage_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `subtitle` varchar(150) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `section_key` varchar(100) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

/* ============ HELPER: SLUG TIẾNG VIỆT ============ */
if (!function_exists('vnSlug')) {
    function vnSlug(string $str): string {
        $map = [
            'a' => 'áàảãạăắằẳẵặâấầẩẫậÁÀẢÃẠĂẮẰẲẴẶÂẤẦẨẪẬ',
            'e' => 'éèẻẽẹêếềểễệÉÈẺẼẸÊẾỀỂỄỆ',
            'i' => 'íìỉĩịÍÌỈĨỊ',
            'o' => 'óòỏõọôốồổỗộơớờởỡợÓÒỎÕỌÔỐỒỔỖỘƠỚỜỞỠỢ',
            'u' => 'úùủũụưứừửữựÚÙỦŨỤƯỨỪỬỮỰ',
            'y' => 'ýỳỷỹỵÝỲỶỸỴ',
            'd' => 'đĐ',
        ];
        foreach ($map as $ascii => $chars) {
            $str = preg_replace('/[' . $chars . ']/u', $ascii, $str);
        }
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        return trim($str, '-');
    }
}

/* ============ HELPER: ĐẾM SẢN PHẨM THEO KHỐI ============ */
if (!function_exists('getSectionProductCount')) {
    function getSectionProductCount(PDO $pdo, string $key): int {
        switch ($key) {
            case 'giam-gia':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE discount_percent > 0 AND id < 100")->fetchColumn();
            case 'combo-thu-dong':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id = (SELECT id FROM categories WHERE slug='combo' LIMIT 1) AND id < 100")->fetchColumn();
            case 'ban-chay':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sold_count > 0")->fetchColumn();
            case 'giam-kich-san':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id BETWEEN 100 AND 104")->fetchColumn();
            case 'combo-thoi-trang':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id BETWEEN 105 AND 109")->fetchColumn();
            case 'phong-cach-toi-gian':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id BETWEEN 110 AND 114")->fetchColumn();
            case 'khuyen-mai-mua-he':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id BETWEEN 115 AND 119")->fetchColumn();
            case 'san-pham-moi':
                return (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id BETWEEN 120 AND 124")->fetchColumn();
            default:
                return 0;
        }
    }
}

/* ============ XỬ LÝ POST ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: categories.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    /* --- XÓA DANH MỤC --- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
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

    /* --- LƯU DANH MỤC --- */
    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if ($slug === '' && $name !== '') {
            $slug = vnSlug($name);
        }

        if ($name === '' || $slug === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập đủ Tên và Slug.'];
            header('Location: categories.php'); exit;
        }

        /* Tự động thêm hậu tố -2, -3... nếu slug trùng */
        $baseSlug = $slug;
        $i = 2;
        while (true) {
            $dup = $pdo->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
            $dup->execute([$slug, $id]);
            if (!$dup->fetch()) break;
            $slug = $baseSlug . '-' . $i++;
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

    /* --- XÓA KHỐI TRANG CHỦ --- */
    if ($action === 'delete_section') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM homepage_sections WHERE id = ?")->execute([$id]);
        $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa khối #$id"];
        header('Location: categories.php'); exit;
    }

    /* --- LƯU KHỐI TRANG CHỦ --- */
    if ($action === 'save_section') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $link = trim($_POST['link'] ?? '');
        $section_key = trim($_POST['section_key'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);

        if ($title === '' || $section_key === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập đủ Tiêu đề và Mã khối.'];
            header('Location: categories.php'); exit;
        }

        $dup = $pdo->prepare("SELECT id FROM homepage_sections WHERE section_key = ? AND id != ?");
        $dup->execute([$section_key, $id]);
        if ($dup->fetch()) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => "Mã khối '$section_key' đã tồn tại."];
            header('Location: categories.php'); exit;
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE homepage_sections SET title=?, subtitle=?, link=?, section_key=?, display_order=? WHERE id=?")
                ->execute([$title, $subtitle, $link, $section_key, $display_order, $id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã cập nhật khối #$id"];
        } else {
            $pdo->prepare("INSERT INTO homepage_sections (title, subtitle, link, section_key, display_order) VALUES (?, ?, ?, ?, ?)")
                ->execute([$title, $subtitle, $link, $section_key, $display_order]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã thêm khối mới'];
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

$sections = $pdo->query("SELECT * FROM homepage_sections ORDER BY display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$totalProducts       = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$uncategorizedCount  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id IS NULL")->fetchColumn();
$categorizedCount    = $totalProducts - $uncategorizedCount;
?>

<!-- ================= PHẦN 1: DANH MỤC SẢN PHẨM ================= -->
<div class="admin-page-head">
    <div>
        <h1>Danh mục sản phẩm</h1>
        <div class="subtitle"><?php echo count($categories); ?> danh mục &middot; <?php echo $categorizedCount; ?>/<?php echo $totalProducts; ?> sản phẩm đã phân loại</div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openCategoryModal()">
        <i class="ph ph-plus"></i> Thêm danh mục
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
                    <th>Slug (URL SEO)</th>
                    <th style="width:150px;">Sản phẩm</th>
                    <th style="width:180px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><b style="font-family:'IBM Plex Mono',monospace;">#<?php echo (int)$c['id']; ?></b></td>
                        <td><b><?php echo htmlspecialchars($c['name']); ?></b></td>
                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;color:var(--admin-text-muted);">
                            <?php echo htmlspecialchars($c['slug']); ?>
                        </td>
                        <td>
                            <?php if ((int)$c['product_count'] > 0): ?>
                                <a href="products.php?cat=<?php echo (int)$c['id']; ?>" class="adm-badge adm-badge--blue" title="Xem sản phẩm thuộc danh mục này">
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

<!-- ================= PHẦN 2: KHỐI TRANG CHỦ ================= -->
<div class="admin-page-head" style="margin-top: 3rem;">
    <div>
        <h1>Khối Trang chủ</h1>
        <div class="subtitle"><?php echo count($sections); ?> khối hiển thị (Bán chạy, Mới về...)</div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openSectionModal()">
        <i class="ph ph-plus"></i> Thêm khối mới
    </button>
</div>

<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($sections)): ?>
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Chưa có khối nào</p>
    <?php else: ?>
        <table class="adm-table">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Tiêu đề / Phụ đề</th>
                    <th>Mã khối (Key)</th>
                    <th>Đường dẫn "Xem tất cả"</th>
                    <th style="width:120px;">Sản phẩm</th>
                    <th style="width:90px;text-align:center;">Thứ tự</th>
                    <th style="width:180px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sections as $s): 
                    $secProdCount = getSectionProductCount($pdo, $s['section_key']);
                ?>
                    <tr>
                        <td><b style="font-family:'IBM Plex Mono',monospace;">#<?php echo (int)$s['id']; ?></b></td>
                        <td>
                            <b style="font-family:'Fraunces',serif;font-size:1.05rem;"><?php echo htmlspecialchars($s['title']); ?></b>
                            <?php if ($s['subtitle']): ?>
                                <div style="font-size:0.75rem;color:var(--admin-text-muted);letter-spacing:0.05em;text-transform:uppercase;">
                                    <?php echo htmlspecialchars($s['subtitle']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;color:var(--admin-accent);">
                            <?php echo htmlspecialchars($s['section_key']); ?>
                        </td>
                        <td style="font-size:0.85rem;color:#4b5563;">
                            <?php echo htmlspecialchars($s['link'] ?: '—'); ?>
                        </td>
                        <!-- CỘT SẢN PHẨM -->
                        <td>
                            <?php if ($secProdCount > 0): ?>
                                <a href="products.php?collection=<?php echo urlencode($s['section_key']); ?>" class="adm-badge adm-badge--blue" title="Xem sản phẩm thuộc khối này">
                                    <?php echo $secProdCount; ?> sản phẩm
                                </a>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--gray">Trống</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-family:'IBM Plex Mono',monospace;font-weight:600;">
                            <?php echo (int)$s['display_order']; ?>
                        </td>
                        <td>
                            <button type="button" class="btn-adm btn-adm--edit"
                                    onclick='editSection(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                Sửa
                            </button>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirmDelete('Xóa khối này?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <button type="submit" class="btn-adm btn-adm--delete">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ================= MODAL DANH MỤC ================= -->
<div class="adm-modal" id="categoryModal">
    <div class="adm-modal__box" style="max-width:480px;">
        <button type="button" class="adm-modal__close" onclick="closeCategoryModal()">&times;</button>
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
                <label>Slug (URL SEO) *</label>
                <input type="text" name="slug" id="c_slug" class="adm-input" required maxlength="100"
                       placeholder="ao-nam, quan-nu, combo">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px;">
                    <i class="ph ph-link"></i> Chỉ dùng cho URL thân thiện. Không ảnh hưởng việc phân loại sản phẩm (phân loại dựa trên <b>ID danh mục</b>).
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem"
                        onclick="closeCategoryModal()">Hủy</button>
                <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                    <i class="ph ph-floppy-disk"></i> Lưu danh mục
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL KHỐI TRANG CHỦ ================= -->
<div class="adm-modal" id="sectionModal">
    <div class="adm-modal__box" style="max-width:520px;">
        <button type="button" class="adm-modal__close" onclick="closeSectionModal()">&times;</button>
        <h2 class="adm-modal__title" id="sectionModalTitle">Thêm khối mới</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save_section">
            <input type="hidden" name="id" id="s_id" value="">

            <div class="adm-form-group">
                <label>Tiêu đề chính (Title) *</label>
                <input type="text" name="title" id="s_title" class="adm-input" required maxlength="150"
                       placeholder="VD: Bán chạy nhất">
            </div>

            <div class="adm-form-group">
                <label>Phụ đề nhỏ (Subtitle)</label>
                <input type="text" name="subtitle" id="s_subtitle" class="adm-input" maxlength="150"
                       placeholder="VD: ĐƯỢC YÊU THÍCH NHẤT">
            </div>

            <div class="adm-form-group">
                <label>Mã khối (Section Key) *</label>
                <input type="text" name="section_key" id="s_section_key" class="adm-input" required maxlength="100"
                       placeholder="VD: best-sellers">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px;">
                    <i class="ph ph-info"></i> Dùng để gọi trong code (VD: <code>best-sellers</code>). Không dấu, cách nhau bằng dấu gạch ngang.
                </div>
            </div>

            <div class="adm-form-group">
                <label>Đường dẫn "Xem tất cả"</label>
                <input type="text" name="link" id="s_link" class="adm-input" maxlength="255"
                       placeholder="VD: products.php?collection=best-sellers">
            </div>

            <div class="adm-form-group">
                <label>Thứ tự hiển thị (Số nhỏ đứng trước)</label>
                <input type="number" name="display_order" id="s_display_order" class="adm-input" value="0">
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem"
                        onclick="closeSectionModal()">Hủy</button>
                <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                    <i class="ph ph-floppy-disk"></i> Lưu khối
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* --- JS CHO DANH MỤC --- */
function autoSlug(name) {
    var slugInput = document.getElementById('c_slug');
    if (slugInput.dataset.manual === '1') return;
    var slug = name
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
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

/* --- JS CHO KHỐI TRANG CHỦ --- */
function openSectionModal() {
    document.getElementById('sectionModalTitle').textContent = 'Thêm khối mới';
    document.querySelector('#sectionModal form').reset();
    document.getElementById('s_id').value = '';
    document.getElementById('s_display_order').value = 0;
    document.getElementById('sectionModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editSection(s) {
    document.getElementById('sectionModalTitle').textContent = 'Sửa khối #' + s.id;
    document.getElementById('s_id').value = s.id;
    document.getElementById('s_title').value = s.title;
    document.getElementById('s_subtitle').value = s.subtitle || '';
    document.getElementById('s_section_key').value = s.section_key;
    document.getElementById('s_link').value = s.link || '';
    document.getElementById('s_display_order').value = s.display_order || 0;
    document.getElementById('sectionModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeSectionModal() {
    document.getElementById('sectionModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('sectionModal').addEventListener('click', function (e) {
    if (e.target === this) closeSectionModal();
});
</script>

<?php include 'includes/admin_footer.php'; ?>