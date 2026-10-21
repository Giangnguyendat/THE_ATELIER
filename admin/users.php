<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

/* ============ XỬ LÝ POST ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: users.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    // ĐỔI VAI TRÒ
    if ($action === 'toggle_role') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Không thể tự đổi vai trò của chính mình.'];
            header('Location: users.php'); exit;
        }
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetchColumn();
        if ($role !== false) {
            $newRole = $role === 'admin' ? 'customer' : 'admin';
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã đổi vai trò user #$id → $newRole"];
        }
        header('Location: users.php'); exit;
    }

    // KHÓA / MỞ KHÓA
    if ($action === 'toggle_lock') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Không thể tự khóa chính mình.'];
            header('Location: users.php'); exit;
        }
        $stmt = $pdo->prepare("SELECT is_locked FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        // Nếu chưa có cột is_locked, tự động thêm
        if ($current === false) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN is_locked TINYINT(1) DEFAULT 0");
                $current = 0;
            } catch (Exception $e) {}
        }
        if ($current !== false) {
            $newStatus = (int)$current === 1 ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_locked = ? WHERE id = ?")->execute([$newStatus, $id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => $newStatus ? "Đã khóa user #$id" : "Đã mở khóa user #$id"];
        }
        header('Location: users.php'); exit;
    }

    // XÓA
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Không thể tự xóa chính mình.'];
            header('Location: users.php'); exit;
        }
        // Kiểm tra có đơn hàng không
        $check = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Không thể xóa — user đã có đơn hàng.'];
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa user #$id"];
        }
        header('Location: users.php'); exit;
    }
}

/* ============ LẤY DỮ LIỆU ============ */
$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';

$where = []; $params = [];
if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($roleFilter, ['admin', 'customer'], true)) {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS order_count,
           (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE user_id = u.id AND status != 'cancelled') AS total_spent
    FROM users u
    $whereSQL
    ORDER BY u.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Kiểm tra cột is_locked có tồn tại không
$hasLockColumn = false;
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_locked'");
    $hasLockColumn = $checkCol && $checkCol->fetch() !== false;
} catch (Exception $e) {}
?>

<div class="admin-page-head">
    <div>
        <h1>Người dùng</h1>
        <div class="subtitle"><?php echo count($users); ?> tài khoản</div>
    </div>
</div>

<!-- FILTER -->
<div class="admin-card" style="padding:1rem 1.25rem;">
    <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:2;min-width:200px;">
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;">Tìm kiếm</label>
            <input type="text" name="q" class="adm-input" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Tên, email, SĐT...">
        </div>
        <div style="flex:1;min-width:150px;">
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;">Vai trò</label>
            <select name="role" class="adm-select">
                <option value="">Tất cả</option>
                <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="customer" <?php echo $roleFilter === 'customer' ? 'selected' : ''; ?>>Khách hàng</option>
            </select>
        </div>
        <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
        <?php if ($search !== '' || $roleFilter !== ''): ?>
            <a href="users.php" class="btn-adm btn-adm--ghost">Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($users)): ?>
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Không có người dùng nào</p>
    <?php else: ?>
        <table class="adm-table">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Họ tên</th>
                    <th>Email / SĐT</th>
                    <th>Vai trò</th>
                    <th>Đơn hàng</th>
                    <th>Đã chi</th>
                    <th style="width:230px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u):
                    $isSelf = (int)$u['id'] === (int)$_SESSION['user_id'];
                    $isLocked = $hasLockColumn && !empty($u['is_locked']);
                ?>
                    <tr style="<?php echo $isLocked ? 'opacity:0.55;' : ''; ?>">
                        <td><b>#<?php echo (int)$u['id']; ?></b></td>
                        <td>
                            <b><?php echo htmlspecialchars($u['full_name'] ?? '—'); ?></b>
                            <?php if ($isSelf): ?>
                                <span class="adm-badge adm-badge--orange">Bạn</span>
                            <?php endif; ?>
                            <?php if ($isLocked): ?>
                                <span class="adm-badge adm-badge--red">Đã khóa</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.82rem;">
                            <div><?php echo htmlspecialchars($u['email'] ?? '—'); ?></div>
                            <div style="color:var(--admin-text-muted);"><?php echo htmlspecialchars($u['phone'] ?? ''); ?></div>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="adm-badge adm-badge--red">👑 Admin</span>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--gray">Khách</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$u['order_count']; ?></td>
                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;">
                            <?php echo number_format($u['total_spent'], 0, ',', '.'); ?>đ
                        </td>
                        <td>
                            <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                                <?php if (!$isSelf): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="toggle_role">
                                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit" class="btn-adm btn-adm--ghost" style="padding:0.4rem 0.7rem;font-size:0.72rem;"
                                                onclick="return confirm('Đổi vai trò user này?');">
                                            <?php echo $u['role'] === 'admin' ? '↓ Hạ cấp' : '↑ Nâng admin'; ?>
                                        </button>
                                    </form>

                                    <?php if ($hasLockColumn): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="toggle_lock">
                                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" class="btn-adm btn-adm--edit" style="padding:0.4rem 0.7rem;font-size:0.72rem;"
                                                    onclick="return confirm('<?php echo $isLocked ? 'Mở khóa' : 'Khóa'; ?> user này?');">
                                                <?php echo $isLocked ? '🔓 Mở' : '🔒 Khóa'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirmDelete('Xóa user này? Hành động không thể hoàn tác.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit" class="btn-adm btn-adm--delete" style="padding:0.4rem 0.7rem;font-size:0.72rem;">
                                            Xóa
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:0.75rem;color:var(--admin-text-muted);">Không thể thao tác với chính mình</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if (!$hasLockColumn): ?>
<div class="admin-card" style="background:#fffbeb;border-color:#fde047;">
    <strong style="color:#92400e;">💡 Gợi ý:</strong>
    <span style="color:#854d0e;font-size:0.88rem;">
        Chưa có cột <code>is_locked</code> trong bảng users. Nút khóa/mở khóa sẽ xuất hiện sau khi bạn chạy:
    </span>
    <pre style="background:#fff;padding:0.75rem;border-radius:4px;margin-top:0.75rem;font-size:0.82rem;overflow:auto;">ALTER TABLE users ADD COLUMN is_locked TINYINT(1) DEFAULT 0;</pre>
</div>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>