<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

/* ============================================================
   FILTER + SEARCH
   ============================================================ */
$filter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$openId = (int)($_GET['id'] ?? 0);

$where  = [];
$params = [];

$validStatuses = ['new', 'processing', 'resolved', 'closed'];
if (in_array($filter, $validStatuses, true)) {
    $where[]  = 'status = ?';
    $params[] = $filter;
}

if ($search !== '') {
    $where[]  = "(name LIKE ? OR email LIKE ? OR phone LIKE ? OR message LIKE ? OR id = ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = is_numeric($search) ? (int)$search : 0;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT sr.*, u.full_name AS user_name
    FROM support_requests sr
    LEFT JOIN users u ON u.id = sr.user_id
    $whereSQL
    ORDER BY
        CASE sr.status
            WHEN 'new' THEN 1
            WHEN 'processing' THEN 2
            WHEN 'resolved' THEN 3
            ELSE 4
        END,
        sr.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Counts */
$counts = ['all' => 0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM support_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['status']] = (int)$r['c'];
        $counts['all'] += (int)$r['c'];
    }
} catch (Exception $e) {}

$totalNew = $counts['new'] ?? 0;

/* Status map */
$statusMap = [
    'new'        => ['Mới',           'adm-badge--orange'],
    'processing' => ['Đang xử lý',    'adm-badge--blue'],
    'resolved'   => ['Đã giải quyết', 'adm-badge--green'],
    'closed'     => ['Đã đóng',       'adm-badge--gray'],
];
?>

<style>
    .adm-sup {
        --c-primary: #a8461f;
        --c-border:  #efeae0;
        --c-muted:   #8a8377;
    }

    /* Page head */
    .adm-sup .admin-page-head {
        display:flex; align-items:flex-end; justify-content:space-between;
        margin-bottom:1.5rem; padding-bottom:1.1rem;
        border-bottom:1px solid var(--c-border); gap:1rem; flex-wrap:wrap;
    }
    .adm-sup .admin-page-head h1 {
        font-family:'Playfair Display',Georgia,serif;
        font-size:2rem; margin:0; color:#24211d;
    }
    .adm-sup .admin-page-head .subtitle {
        font-size:0.85rem; color:var(--c-muted); margin-top:0.4rem;
    }
    .adm-sup .admin-page-head .subtitle b { color:var(--c-primary); }

    /* Tabs */
    .adm-sup .adm-tabs {
        display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1.25rem;
        padding-bottom:0.75rem; border-bottom:1px solid var(--c-border);
    }
    .adm-sup .adm-tab {
        padding:0.5rem 0.95rem; background:#fff; border:1px solid var(--c-border);
        border-radius:8px; font-size:0.8rem; font-weight:600;
        color:#24211d; text-decoration:none; transition:all 0.15s;
        display:inline-flex; align-items:center; gap:0.4rem;
    }
    .adm-sup .adm-tab:hover { background:#fbf9f5; }
    .adm-sup .adm-tab.active {
        background:var(--c-primary); color:#fff; border-color:var(--c-primary);
    }
    .adm-sup .adm-tab .cnt {
        background:rgba(0,0,0,0.08); padding:1px 7px; border-radius:999px;
        font-family:'IBM Plex Mono',monospace; font-size:0.7rem; font-weight:700;
    }
    .adm-sup .adm-tab.active .cnt { background:rgba(255,255,255,0.22); }

    /* Filter */
    .adm-sup .adm-filter {
        background:#fff; border:1px solid var(--c-border);
        border-radius:12px; padding:1rem 1.25rem;
        margin-bottom:1.25rem;
    }
    .adm-sup .adm-filter form {
        display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;
    }
    .adm-sup .adm-filter input,
    .adm-sup .adm-filter select {
        width:100%; padding:0.65rem 0.85rem;
        font-size:0.88rem; font-family:inherit; color:#24211d;
        background:#fbf9f5; border:1px solid var(--c-border);
        border-radius:8px; outline:none; box-sizing:border-box;
    }
    .adm-sup .adm-filter input:focus,
    .adm-sup .adm-filter select:focus {
        border-color:var(--c-primary); background:#fff;
        box-shadow:0 0 0 3px rgba(168,70,31,0.10);
    }
    .adm-sup .adm-filter .btn-adm {
        padding:0.65rem 1.1rem; border-radius:8px;
        font-weight:600; font-size:0.82rem; border:none;
        cursor:pointer; text-decoration:none;
        transition:all 0.15s;
    }
    .adm-sup .adm-filter .btn-adm--primary {
        background:var(--c-primary); color:#fff;
    }
    .adm-sup .adm-filter .btn-adm--primary:hover { background:#8f3a18; }
    .adm-sup .adm-filter .btn-adm--ghost {
        background:#fff; color:#24211d; border:1px solid var(--c-border);
    }
    .adm-sup .adm-filter .btn-adm--ghost:hover { background:#fbf9f5; }

    /* List */
    .adm-sup .sup-list { display:flex; flex-direction:column; gap:0.65rem; }
    .adm-sup .sup-card {
        display:flex; gap:1rem; align-items:flex-start;
        padding:1rem 1.15rem;
        background:#fff; border:1px solid var(--c-border);
        border-radius:12px; cursor:pointer;
        transition:all 0.18s;
        position:relative;
    }
    .adm-sup .sup-card:hover {
        transform:translateX(3px);
        box-shadow:0 6px 20px rgba(36,33,29,0.08);
        border-color:#c8bfa9;
    }
    .adm-sup .sup-card.new {
        background:linear-gradient(90deg, #fffaf5 0%, #ffffff 40%);
        border-left:4px solid var(--c-primary);
        padding-left:calc(1.15rem - 3px);
    }
    .adm-sup .sup-card.new::after {
        content:''; position:absolute; top:1rem; right:1rem;
        width:8px; height:8px; border-radius:50%; background:var(--c-primary);
        box-shadow:0 0 0 4px rgba(168,70,31,0.15);
    }
    .adm-sup .sup-icon {
        width:44px; height:44px; border-radius:12px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-size:1.25rem; background:#faf8f4;
    }
    .adm-sup .sup-body { flex:1; min-width:0; }
    .adm-sup .sup-top {
        display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.35rem;
    }
    .adm-sup .sup-title { font-weight:700; color:#24211d; font-size:0.95rem; }
    .adm-sup .sup-time {
        font-size:0.72rem; color:var(--c-muted);
        font-family:'IBM Plex Mono',monospace; margin-left:auto;
    }
    .adm-sup .sup-preview {
        font-size:0.85rem; color:#4b5563; line-height:1.55;
        overflow:hidden; text-overflow:ellipsis;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
    }
    .adm-sup .sup-meta {
        font-size:0.78rem; color:var(--c-muted); margin-bottom:4px;
        font-family:'IBM Plex Mono',monospace;
    }

    /* Badges */
    .adm-sup .adm-badge {
        display:inline-flex; align-items:center; gap:0.3rem;
        padding:0.25rem 0.65rem; font-size:0.7rem; font-weight:600;
        border-radius:999px; border:1px solid transparent;
    }
    .adm-sup .adm-badge::before {
        content:''; width:5px; height:5px; border-radius:50%;
        background:currentColor; opacity:0.85;
    }
    .adm-sup .adm-badge--orange { color:#c2410c; background:#fff7ed; border-color:#fed7aa; }
    .adm-sup .adm-badge--blue   { color:#1d4ed8; background:#eff6ff; border-color:#bfdbfe; }
    .adm-sup .adm-badge--green  { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; }
    .adm-sup .adm-badge--gray   { color:#57534e; background:#f5f5f4; border-color:#e7e5e4; }

    /* Empty */
    .adm-sup .adm-empty {
        text-align:center; padding:4rem 1.5rem; color:var(--c-muted);
        background:#fff; border:1px solid var(--c-border); border-radius:12px;
    }
    .adm-sup .adm-empty-icon { font-size:3rem; margin-bottom:0.75rem; opacity:0.4; }

    /* ============ MODAL ============ */
    .adm-sup .adm-modal {
        position:fixed; inset:0; background:rgba(36,33,29,0.55);
        backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
        display:none; align-items:center; justify-content:center;
        z-index:9999; padding:1.5rem;
    }
    .adm-sup .adm-modal.show { display:flex; animation:supFade 0.2s; }
    @keyframes supFade { from { opacity:0; } to { opacity:1; } }

    .adm-sup .adm-modal__box {
        background:#fff; border-radius:16px;
        width:100%; max-width:820px; max-height:92vh; overflow-y:auto;
        padding:1.75rem 2rem 2rem; position:relative;
        box-shadow:0 16px 40px rgba(36,33,29,0.20);
        animation:supSlide 0.25s cubic-bezier(0.4,0,0.2,1);
    }
    @keyframes supSlide {
        from { opacity:0; transform:translateY(20px) scale(0.98); }
        to   { opacity:1; transform:translateY(0) scale(1); }
    }
    .adm-sup .adm-modal__close {
        position:absolute; top:1rem; right:1rem;
        width:36px; height:36px; border-radius:50%; border:none;
        background:#f5f2ec; color:#24211d; font-size:1.3rem; line-height:1;
        cursor:pointer; display:flex; align-items:center; justify-content:center;
        transition:all 0.15s;
    }
    .adm-sup .adm-modal__close:hover { background:#e8e0d0; transform:rotate(90deg); }
    .adm-sup .adm-modal__title {
        font-family:'Playfair Display',Georgia,serif;
        font-size:1.5rem; margin:0 0 1.5rem; color:#24211d;
        padding-right:3rem; letter-spacing:-0.01em;
    }

    /* Modal content */
    .adm-sup .sup-info-grid {
        display:grid; grid-template-columns:1fr 1fr; gap:1rem;
        margin-bottom:1.5rem;
    }
    .adm-sup .sup-info-box {
        background:#faf8f4; border:1px solid #efeae0;
        border-radius:10px; padding:1rem;
    }
    .adm-sup .sup-info-box__label {
        font-size:0.68rem; letter-spacing:0.1em; text-transform:uppercase;
        color:#8a8377; margin-bottom:0.5rem; font-weight:700;
    }
    .adm-sup .sup-info-box__value {
        font-size:0.9rem; color:#24211d; line-height:1.7;
    }
    .adm-sup .sup-info-box__value a { color:#a8461f; }

    .adm-sup .sup-message {
        background:#fffaf5; border:1px solid #f5c9a8;
        border-radius:10px; padding:1rem 1.15rem; margin-bottom:1.5rem;
    }
    .adm-sup .sup-message__label {
        font-size:0.68rem; letter-spacing:0.1em; text-transform:uppercase;
        color:#7c3216; margin-bottom:0.5rem; font-weight:700;
    }
    .adm-sup .sup-message__body {
        font-size:0.9rem; color:#24211d; line-height:1.7; white-space:pre-wrap;
    }

    /* Reply form */
    .adm-sup .sup-reply {
        background:#f0fdf4; border:1px solid #bbf7d0;
        border-radius:12px; padding:1.25rem;
    }
    .adm-sup .sup-reply__head {
        font-size:0.78rem; letter-spacing:0.08em; text-transform:uppercase;
        color:#166534; font-weight:700; margin-bottom:1rem;
        display:flex; align-items:center; gap:0.5rem;
    }
    .adm-sup .sup-reply label {
        display:block; font-size:0.75rem; font-weight:600;
        color:#374151; margin-bottom:6px;
    }
    .adm-sup .sup-reply input,
    .adm-sup .sup-reply textarea,
    .adm-sup .sup-reply select {
        width:100%; padding:0.75rem 1rem;
        border:1px solid #d1d5db; border-radius:8px;
        font-size:0.9rem; font-family:inherit; color:#24211d;
        background:#fff; box-sizing:border-box;
        transition:border-color 0.15s;
    }
    .adm-sup .sup-reply textarea { resize:vertical; min-height:140px; line-height:1.65; }
    .adm-sup .sup-reply input:focus,
    .adm-sup .sup-reply textarea:focus,
    .adm-sup .sup-reply select:focus {
        outline:none; border-color:#166534;
        box-shadow:0 0 0 3px rgba(22,101,52,0.10);
    }
    .adm-sup .sup-reply__row {
        display:grid; grid-template-columns:2fr 1fr; gap:1rem; margin-bottom:1rem;
    }
    .adm-sup .sup-reply__actions {
        display:flex; gap:0.75rem; margin-top:1rem;
    }
    .adm-sup .sup-reply__actions .btn-adm {
        flex:1; padding:0.75rem; border-radius:8px; border:none;
        font-size:0.88rem; font-weight:600; cursor:pointer;
        transition:all 0.15s; font-family:inherit;
    }
    .adm-sup .btn-adm--success {
        background:#166534; color:#fff;
    }
    .adm-sup .btn-adm--success:hover:not(:disabled) { background:#14532d; }
    .adm-sup .btn-adm--success:disabled { opacity:0.55; cursor:not-allowed; }
    .adm-sup .btn-adm--ghost {
        background:#fff; color:#374151; border:1px solid #d1d5db !important;
    }
    .adm-sup .btn-adm--ghost:hover { background:#f3f4f6; }

    .adm-sup .sup-reply-err {
        color:#b91c1c; font-size:0.82rem; margin:0.5rem 0 0;
        min-height:1em; font-weight:600;
    }
    .adm-sup .sup-reply-hint {
        font-size:0.78rem; color:#4b5563; margin-top:0.5rem; line-height:1.55;
    }

    /* Reply history */
    .adm-sup .sup-reply-history {
        background:#eff6ff; border-left:4px solid #2563eb;
        border-radius:8px; padding:1rem 1.15rem; margin-bottom:1.5rem;
    }
    .adm-sup .sup-reply-history__label {
        font-size:0.68rem; letter-spacing:0.1em; text-transform:uppercase;
        color:#1d4ed8; font-weight:700; margin-bottom:0.5rem;
    }
    .adm-sup .sup-reply-history__meta {
        font-size:0.78rem; color:#6b7280; margin-bottom:0.5rem;
        font-family:'IBM Plex Mono',monospace;
    }
    .adm-sup .sup-reply-history__body {
        font-size:0.88rem; color:#1e3a8a; line-height:1.65; white-space:pre-wrap;
    }

    /* Toast */
    .adm-sup .adm-toast {
        position:fixed; top:1.5rem; left:50%;
        transform:translateX(-50%) translateY(-20px);
        background:#166534; color:#fff;
        padding:0.9rem 1.75rem; border-radius:8px;
        font-family:'IBM Plex Mono',monospace; font-size:0.85rem;
        box-shadow:0 10px 25px rgba(0,0,0,0.15);
        z-index:99999; opacity:0;
        transition:all 0.3s; max-width:90vw; font-weight:600;
    }
    .adm-sup .adm-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
    .adm-sup .adm-toast.error { background:#b91c1c; }

    @media (max-width:720px) {
        .adm-sup .admin-page-head h1 { font-size:1.6rem; }
        .adm-sup .adm-modal__box { padding:1.5rem 1.25rem; }
        .adm-sup .sup-info-grid { grid-template-columns:1fr; }
        .adm-sup .sup-reply__row { grid-template-columns:1fr; }
    }
</style>

<div class="adm-sup">

    <!-- PAGE HEAD -->
    <div class="admin-page-head">
        <div>
            <h1>Yêu cầu hỗ trợ</h1>
            <div class="subtitle">
                Tổng <b><?php echo (int)$counts['all']; ?></b> yêu cầu ·
                <b style="color:#c2410c;"><?php echo (int)$totalNew; ?></b> chưa xử lý
            </div>
        </div>
    </div>

    <!-- TABS -->
    <div class="adm-tabs">
        <a href="support.php" class="adm-tab <?php echo $filter === '' ? 'active' : ''; ?>">
            📋 Tất cả <span class="cnt"><?php echo (int)$counts['all']; ?></span>
        </a>
        <?php foreach ($statusMap as $k => $v): ?>
            <a href="support.php?status=<?php echo $k; ?>"
               class="adm-tab <?php echo $filter === $k ? 'active' : ''; ?>">
                <?php echo $v[0]; ?>
                <?php if (!empty($counts[$k])): ?>
                    <span class="cnt"><?php echo (int)$counts[$k]; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- FILTER -->
    <div class="adm-filter">
        <form method="GET">
            <div style="flex:2;min-width:240px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--c-muted);margin-bottom:0.35rem;font-weight:700;letter-spacing:0.06em;">
                    🔍 Tìm kiếm
                </label>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Mã YC, tên, email, nội dung...">
            </div>
            <div style="flex:1;min-width:150px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--c-muted);margin-bottom:0.35rem;font-weight:700;letter-spacing:0.06em;">
                    Trạng thái
                </label>
                <select name="status">
                    <option value="">Tất cả</option>
                    <?php foreach ($statusMap as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filter === $k ? 'selected' : ''; ?>>
                            <?php echo $v[0]; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
                <?php if ($search !== '' || $filter !== ''): ?>
                    <a href="support.php" class="btn-adm btn-adm--ghost">Xóa lọc</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- LIST -->
    <?php if (empty($requests)): ?>
        <div class="adm-empty">
            <div class="adm-empty-icon">📭</div>
            <div style="font-size:1rem;font-weight:600;color:#24211d;margin-bottom:0.35rem;">
                Không có yêu cầu nào
            </div>
            <div style="font-size:0.85rem;">Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm.</div>
        </div>
    <?php else: ?>
        <div class="sup-list">
            <?php foreach ($requests as $r):
                $isNew   = $r['status'] === 'new';
                $stInfo  = $statusMap[$r['status']] ?? ['Không rõ', 'adm-badge--gray'];
                $iconMap = [
                    'order'    => '📦', 'return'   => '🔄', 'payment'  => '💳',
                    'product'  => '👕', 'shipping' => '🚚', 'account'  => '👤',
                    'other'    => '💬',
                ];
                $icon = '💬';
                foreach ($iconMap as $k => $v) {
                    if (stripos($r['subject'], $k) !== false) { $icon = $v; break; }
                }
            ?>
                <div class="sup-card <?php echo $isNew ? 'new' : ''; ?>"
                     data-id="<?php echo (int)$r['id']; ?>"
                     onclick="openSupportModal(<?php echo (int)$r['id']; ?>)">
                    <div class="sup-icon"><?php echo $icon; ?></div>
                    <div class="sup-body">
                        <div class="sup-top">
                            <span class="sup-title">#SR<?php echo (int)$r['id']; ?> — <?php echo htmlspecialchars($r['name']); ?></span>
                            <span class="adm-badge <?php echo $stInfo[1]; ?>"><?php echo $stInfo[0]; ?></span>
                            <span class="sup-time"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></span>
                        </div>
                        <div class="sup-meta">
                            <?php echo htmlspecialchars($r['email']); ?>
                            <?php if (!empty($r['phone'])): ?> · <?php echo htmlspecialchars($r['phone']); ?><?php endif; ?>
                            · <b style="color:#a8461f;"><?php echo htmlspecialchars($r['subject']); ?></b>
                        </div>
                        <div class="sup-preview"><?php echo htmlspecialchars($r['message']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============ MODAL ============ -->
    <div class="adm-modal" id="supportModal">
        <div class="adm-modal__box">
            <button type="button" class="adm-modal__close" onclick="closeSupportModal()">×</button>
            <h2 class="adm-modal__title" id="supModalTitle">Chi tiết </h2>

            <div id="supModalContent">
                <p style="text-align:center;padding:2rem;color:#8a8377;">⏳ Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="adm-toast" id="supToast"></div>

</div>

<script>
var supModal   = document.getElementById('supportModal');
var supContent = document.getElementById('supModalContent');
var supTitle   = document.getElementById('supModalTitle');
var supToastEl = document.getElementById('supToast');
var currentId  = 0;
var CSRF       = <?php echo json_encode($_SESSION['csrf_token']); ?>;

function supEscape(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
}

function supToast(msg, isError) {
    supToastEl.textContent = msg;
    supToastEl.className = 'adm-toast show' + (isError ? ' error' : '');
    clearTimeout(supToast._t);
    supToast._t = setTimeout(function () {
        supToastEl.className = 'adm-toast';
    }, 3500);
}

/* ============ OPEN MODAL ============ */
function openSupportModal(id) {
    currentId = id;
    supTitle.textContent = 'Chi tiết yêu cầu #SR' + id;
    supContent.innerHTML = '<p style="text-align:center;padding:2rem;color:#8a8377;">⏳ Đang tải...</p>';
    supModal.classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('support_detail_ajax.php?id=' + id)
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) {
                supContent.innerHTML = '<p style="text-align:center;padding:2rem;color:#b91c1c;">'
                    + supEscape(d.msg || 'Không tải được') + '</p>';
                return;
            }
            renderSupportDetail(d);
        })
        .catch(function () {
            supContent.innerHTML = '<p style="text-align:center;padding:2rem;color:#b91c1c;">Lỗi kết nối.</p>';
        });
}

function closeSupportModal() {
    supModal.classList.remove('show');
    document.body.style.overflow = '';
    currentId = 0;
}

supModal.addEventListener('click', function (e) {
    if (e.target === supModal) closeSupportModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && supModal.classList.contains('show')) closeSupportModal();
});

/* ============ RENDER DETAIL ============ */
function renderSupportDetail(d) {
    var r = d.request;
    var statusLabel = {
        'new':        ['Mới',           '#c2410c', '#fff7ed'],
        'processing': ['Đang xử lý',    '#1d4ed8', '#eff6ff'],
        'resolved':   ['Đã giải quyết', '#15803d', '#f0fdf4'],
        'closed':     ['Đã đóng',       '#57534e', '#f5f5f4']
    };
    var sl = statusLabel[r.status] || ['Không rõ', '#57534e', '#f5f5f4'];

    var html = '';

    /* INFO GRID */
    html += '<div class="sup-info-grid">';

    html += '<div class="sup-info-box">';
    html += '<div class="sup-info-box__label">👤 Người gửi</div>';
    html += '<div class="sup-info-box__value">';
    html += '<b>' + supEscape(r.name) + '</b><br>';
    html += '📧 <a href="mailto:' + supEscape(r.email) + '">' + supEscape(r.email) + '</a><br>';
    if (r.phone) html += '📞 ' + supEscape(r.phone);
    html += '</div></div>';

    html += '<div class="sup-info-box">';
    html += '<div class="sup-info-box__label">📋 Yêu cầu</div>';
    html += '<div class="sup-info-box__value">';
    html += 'Mã: <b>#SR' + r.id + '</b><br>';
    html += 'Chủ đề: <b>' + supEscape(r.subject) + '</b><br>';
    html += 'Ngày: ' + supEscape(r.created_at_fmt) + '<br>';
    html += 'Trạng thái: <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:0.75rem;font-weight:700;color:'
         + sl[1] + ';background:' + sl[2] + ';">' + sl[0] + '</span>';
    html += '</div></div>';

    html += '</div>';

    /* MESSAGE */
    html += '<div class="sup-message">';
    html += '<div class="sup-message__label">💬 Nội dung</div>';
    html += '<div class="sup-message__body">' + supEscape(r.message) + '</div>';
    html += '</div>';

    /* REPLY HISTORY */
    if (r.reply_content) {
        html += '<div class="sup-reply-history">';
        html += '<div class="sup-reply-history__label">📨 Đã trả lời</div>';
        html += '<div class="sup-reply-history__meta">' + supEscape(r.replied_at_fmt || '') + ' → ' + supEscape(r.replied_to_email || r.email) + '</div>';
        html += '<div class="sup-reply-history__body">' + supEscape(r.reply_content) + '</div>';
        html += '</div>';
    }

    /* REPLY FORM */
    html += '<div class="sup-reply">';
    html += '<div class="sup-reply__head">✉️ Trả lời qua email</div>';

    html += '<div class="sup-reply__row">';
    html += '<div>';
    html += '<label>Email nhận</label>';
    html += '<input type="email" id="supReplyEmail" value="' + supEscape(r.email) + '">';
    html += '</div>';
    html += '<div>';
    html += '<label>Sau khi gửi</label>';
    html += '<select id="supReplyStatus">';
    html += '<option value="resolved" ' + (r.status === 'resolved' ? 'selected' : '') + '>Đánh dấu đã giải quyết</option>';
    html += '<option value="processing" ' + (r.status === 'processing' ? 'selected' : '') + '>Đánh dấu đang xử lý</option>';
    html += '<option value="closed" ' + (r.status === 'closed' ? 'selected' : '') + '>Đóng yêu cầu</option>';
    html += '</select>';
    html += '</div>';
    html += '</div>';

    html += '<div>';
    html += '<label>Nội dung trả lời *</label>';
    html += '<textarea id="supReplyBody" placeholder="Xin chào ' + supEscape(r.name) + ',&#10;&#10;Về yêu cầu #SR' + r.id + ' của bạn, chúng tôi..."></textarea>';
    html += '</div>';

    html += '<p class="sup-reply-hint">';
    html += '💡 Nội dung sẽ được gửi dưới dạng <b>email HTML</b> có thương hiệu THE ATELIER. ';
    html += 'Bạn có thể viết nhiều đoạn — ngắt dòng sẽ được giữ nguyên.';
    html += '</p>';

    html += '<p class="sup-reply-err" id="supReplyErr"></p>';

    html += '<div class="sup-reply__actions">';
    html += '<button type="button" class="btn-adm btn-adm--ghost" onclick="closeSupportModal()">Hủy</button>';
    html += '<button type="button" class="btn-adm btn-adm--success" id="supSendReplyBtn" onclick="sendSupportReply()">📨 Gửi trả lời</button>';
    html += '</div>';

    html += '</div>';

    supContent.innerHTML = html;
}

/* ============ SEND REPLY ============ */
function sendSupportReply() {
    var errEl  = document.getElementById('supReplyErr');
    var btn    = document.getElementById('supSendReplyBtn');
    var emailEl = document.getElementById('supReplyEmail');
    var bodyEl  = document.getElementById('supReplyBody');
    var statusEl = document.getElementById('supReplyStatus');

    errEl.textContent = '';

    var email  = (emailEl.value || '').trim();
    var body   = (bodyEl.value || '').trim();
    var status = statusEl.value;

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
        errEl.textContent = '⚠ Email không hợp lệ.';
        emailEl.focus();
        return;
    }
    if (body.length < 10) {
        errEl.textContent = '⚠ Nội dung cần ít nhất 10 ký tự.';
        bodyEl.focus();
        return;
    }

    btn.disabled = true;
    var oldTxt = btn.textContent;
    btn.textContent = '⏳ Đang gửi...';

    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('request_id', currentId);
    fd.append('email', email);
    fd.append('content', body);
    fd.append('status', status);

    fetch('support_reply.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                supToast(d.msg || '✅ Đã gửi trả lời!', false);
                closeSupportModal();
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                errEl.textContent = d.msg || 'Có lỗi xảy ra.';
                btn.disabled = false;
                btn.textContent = oldTxt;
            }
        })
        .catch(function () {
            errEl.textContent = 'Không thể kết nối tới máy chủ.';
            btn.disabled = false;
            btn.textContent = oldTxt;
        });
}

/* ============ AUTO OPEN NẾU CÓ ?id= ============ */
<?php if ($openId > 0): ?>
document.addEventListener('DOMContentLoaded', function () {
    openSupportModal(<?php echo (int)$openId; ?>);
});
<?php endif; ?>
</script>

<?php include 'includes/admin_footer.php'; ?>
