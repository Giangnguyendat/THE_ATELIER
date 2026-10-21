    <?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit($d) { ob_end_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}
$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền.']);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonExit(['ok' => false, 'msg' => 'ID không hợp lệ.']);
}

$stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy yêu cầu.']);
}

$r['created_at_fmt'] = date('d/m/Y H:i', strtotime($r['created_at']));
$r['replied_at_fmt'] = !empty($r['replied_at']) ? date('d/m/Y H:i', strtotime($r['replied_at'])) : null;

jsonExit([
    'ok'      => true,
    'request' => $r,
]); 