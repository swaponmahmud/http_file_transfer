<?php
// admin.php — simple admin dashboard for viewing the `files` table (MIME removed) — Light *Gray* Theme
declare(strict_types=1);

session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* ========= SIMPLE AUTH (change the key!) ========= */
$ADMIN_KEY = getenv('ADMIN_KEY') ?: 'change-me-123'; // <-- change this!

// Login via GET key (e.g., admin.php?key=XYZ)
if (isset($_GET['key']) && is_string($_GET['key']) && hash_equals($ADMIN_KEY, $_GET['key'])) {
    $_SESSION['admin_ok'] = true;
}

// If not logged in, show a tiny lock screen (LIGHT GRAY)
if (empty($_SESSION['admin_ok'])) {
    http_response_code(401);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Admin Login</title>
      <style>
        :root{
          --bg:#f3f4f6;      /* light gray background */
          --card:#f9fafb;    /* soft gray card */
          --text:#111827;
          --muted:#6b7280;
          --border:#e5e7eb;
          --accent:#22c55e;
        }
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}
        .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;box-shadow:0 10px 28px rgba(0,0,0,.05);width:min(420px,92%)}
        .title{margin:0 0 12px;font-weight:800;letter-spacing:.3px}
        .muted{color:var(--muted);font-size:13px;margin-bottom:12px}
        .row{display:flex;gap:8px}
        input[type=text]{flex:1;border-radius:10px;border:1px solid var(--border);padding:10px;background:#fff}
        .btn{border:0;border-radius:10px;padding:10px 16px;font-weight:700;color:#fff;background:var(--accent);cursor:pointer;box-shadow:0 6px 14px rgba(34,197,94,.22)}
        .btn:hover{filter:brightness(1.03)}
      </style>
    </head>
    <body>
      <div class="card">
        <h2 class="title">Admin Access</h2>
        <div class="muted">Append <code>?key=YOUR_KEY</code> in the URL or set <code>ADMIN_KEY</code> in environment.</div>
        <div class="row">
          <input type="text" value="?key=<?php echo htmlspecialchars($ADMIN_KEY); ?>" readonly onclick="this.select()">
          <button class="btn" onclick="location.search='?key=<?php echo urlencode($ADMIN_KEY); ?>'">Enter</button>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* ========= HELPERS ========= */
function human_size(int $bytes, int $decimals = 1): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB','PB'];
    $pow = (int) floor(log($bytes, 1024));
    $pow = max(0, min($pow, count($units) - 1));
    $value = $bytes / (1024 ** $pow);
    $precision = ($value < 10 && $pow > 0) ? $decimals : 0;
    return number_format($value, $precision) . ' ' . $units[$pow];
}
function is_active_row(array $r): bool {
    $now = new DateTime();
    $exp = new DateTime($r['expires_at']);
    $notExpired = $exp > $now;
    $underLimit = ($r['max_downloads'] === null) || ((int)$r['downloads'] < (int)$r['max_downloads']);
    return $notExpired && $underLimit;
}
function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

/* ========= INPUTS ========= */
$pdo = db();

$q          = trim((string)($_GET['q'] ?? ''));     // search by file name only
$status     = (string)($_GET['status'] ?? 'all');   // all|active|expired|limited
$sort       = (string)($_GET['sort'] ?? 'id_desc'); // id_desc|id_asc|size_desc|size_asc|exp_asc|exp_desc|dl_desc|dl_asc|created_desc|created_asc
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = min(200, max(10, (int)($_GET['pp'] ?? 50)));

$csrf = csrf_token();

/* ========= ACTIONS (POST) ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $token  = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        exit('Bad CSRF token');
    }

    if ($action === 'delete_selected') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && $ids) {
            $idInts = array_map('intval', $ids);
            $in = implode(',', array_fill(0, count($idInts), '?'));
            $stmt = $pdo->prepare("DELETE FROM files WHERE id IN ($in)");
            $stmt->execute($idInts);
        }
        header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }

    if ($action === 'delete_expired') {
        $pdo->prepare("DELETE FROM files WHERE expires_at < NOW()")->execute();
        header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }

    if ($action === 'export_csv') {
        // Export current filter result as CSV (MIME removed)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=files_export_'.date('Ymd_His').'.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','orig_name','size','downloads','max_downloads','expires_at','created_at','status','share_link']);

        [$whereSql, $params] = buildWhere($q, $status);
        $sql = "SELECT id, orig_name, size, downloads, max_downloads, expires_at, created_at FROM files $whereSql ".sqlOrder($sort);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statusTxt = is_active_row($r) ? 'active' : 'inactive';
            $link = base_url().'/dl.php?id='.$r['id'];
            fputcsv($out, [
                $r['id'],
                $r['orig_name'],
                $r['size'],
                $r['downloads'],
                $r['max_downloads'],
                $r['expires_at'],
                $r['created_at'] ?? '',
                $statusTxt,
                $link,
            ]);
        }
        fclose($out);
        exit;
    }
}

/* ========= QUERY BUILDERS ========= */
function buildWhere(string $q, string $status): array {
    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(orig_name LIKE :q)";     // MIME removed
        $params[':q'] = "%$q%";
    }
    if ($status === 'active') {
        $where[] = "(expires_at > NOW()) AND (max_downloads IS NULL OR downloads < max_downloads)";
    } elseif ($status === 'expired') {
        $where[] = "(expires_at <= NOW())";
    } elseif ($status === 'limited') {
        $where[] = "(max_downloads IS NOT NULL AND downloads >= max_downloads)";
    }

    $sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    return [$sql, $params];
}
function sqlOrder(string $sort): string {
    return match($sort) {
        'id_asc'        => 'ORDER BY id ASC',
        'size_desc'     => 'ORDER BY size DESC',
        'size_asc'      => 'ORDER BY size ASC',
        'exp_asc'       => 'ORDER BY expires_at ASC',
        'exp_desc'      => 'ORDER BY expires_at DESC',
        'dl_desc'       => 'ORDER BY downloads DESC',
        'dl_asc'        => 'ORDER BY downloads ASC',
        'created_asc'   => 'ORDER BY created_at ASC, id ASC',
        'created_desc'  => 'ORDER BY created_at DESC, id DESC',
        default         => 'ORDER BY id DESC',
    };
}

/* ========= COUNTS & LIST ========= */
[$whereSql, $params] = buildWhere($q, $status);

$total = (int)$pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM files $whereSql");
$stmtCnt->execute($params);
$filteredCount = (int)$stmtCnt->fetchColumn();

$sumSize = (int)$pdo->query("SELECT COALESCE(SUM(size),0) FROM files")->fetchColumn();
$sumDownloads = (int)$pdo->query("SELECT COALESCE(SUM(downloads),0) FROM files")->fetchColumn();

$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE expires_at > NOW() AND (max_downloads IS NULL OR downloads < max_downloads)")->fetchColumn();
$expiredCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE expires_at <= NOW()")->fetchColumn();
$limitedCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE max_downloads IS NOT NULL AND downloads >= max_downloads")->fetchColumn();

// pagination
$offset = ($page - 1) * $perPage;

$sqlList = "SELECT id, orig_name, size, downloads, max_downloads, expires_at, created_at
            FROM files
            $whereSql
            ".sqlOrder($sort)."
            LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sqlList);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build base query string for links (without page)
$qsBase = http_build_query([
    'q'      => $q,
    'status' => $status,
    'sort'   => $sort,
    'pp'     => $perPage,
]);

/* ========= UI ========= */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin — Files</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#f3f4f6;        /* light gray page background */
    --card:#f9fafb;      /* soft gray cards */
    --text:#111827;      /* dark text */
    --muted:#6b7280;     /* muted text */
    --border:#e5e7eb;    /* light borders */
    --accent:#22c55e;    /* soft green */
    --accent-50:#ecfdf5; /* badge green bg */
    --danger-50:#fee2e2; /* badge red bg */
  }
  body{ background:var(--bg); color:var(--text); padding:16px; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; }
  .wrap{ width:min(1220px,100%); margin:0 auto; }
  .layout{ display:flex; gap:16px; }
  .main{ flex:1; min-width:0; }
  .aside{ width:320px; }
  .aside-sticky{ position:sticky; top:16px; }

  .ad-slot{ background:#ffffff; border:1px solid var(--border); border-radius:14px; color:#374151; box-shadow:0 8px 20px rgba(0,0,0,.05); display:flex; align-items:center; justify-content:center; text-align:center; }
  .ad-top{ height:90px; margin-bottom:14px; }
  .ad-right{ height:600px; }
  .ad-bottom{ height:250px; margin-top:16px; }
  .ad-title{ font-weight:700; color:#111827; }
  .ad-sub{ font-size:12px; color:#6b7280; }

  .panel{ background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:0 10px 26px rgba(0,0,0,.05); }
  .stat{ background:#f3f4f6; border:1px solid var(--border); border-radius:12px; padding:10px 12px; }
  .stat h6{ margin:0; font-weight:800; color:#111827; }
  .muted{ color:var(--muted); }

  table.table{ background:#fff; color:#111; border-radius:12px; overflow:hidden; }
  thead.table-light th{ background:#f3f4f6 !important; } /* subtle gray header */
  table.table th{ white-space:nowrap; }
  .status-badge{ border-radius:999px; padding:.2rem .5rem; font-size:.8rem; }
  .status-active{ background:var(--accent-50); color:#0b7a14; }
  .status-inactive{ background:var(--danger-50); color:#9b1c1c; }

  .searchbar input{ border-radius:999px; }
  .btn-pill{ border-radius:999px; }
  .btn{ box-shadow:none; }
  .btn-light{ border:1px solid var(--border); background:#fff; }
  .btn-outline-light{ border-color:var(--border); color:#374151; background:#fff; }
  .btn-outline-primary{ border-color:#93c5fd; color:#1d4ed8; background:#fff; }
</style>
</head>
<body>



  <div class="layout">
    <div class="main">
      <div class="panel mb-3">
        <div class="row g-2">
          <div class="col-md-2"><div class="stat"><h6>Total files</h6><div><?= (int)$total ?></div></div></div>
          <div class="col-md-2"><div class="stat"><h6>Active</h6><div><?= (int)$activeCount ?></div></div></div>
          <div class="col-md-2"><div class="stat"><h6>Expired</h6><div><?= (int)$expiredCount ?></div></div></div>
          <div class="col-md-2"><div class="stat"><h6>Limited</h6><div><?= (int)$limitedCount ?></div></div></div>
          <div class="col-md-2"><div class="stat"><h6>Total size</h6><div><?= human_size($sumSize) ?></div></div></div>
          <div class="col-md-2"><div class="stat"><h6>Total downloads</h6><div><?= (int)$sumDownloads ?></div></div></div>
        </div>
      </div>

      <div class="panel mb-3">
        <form class="row gy-2 gx-2 align-items-end">
          <div class="col-md-4 searchbar">
            <label class="form-label">Search (name)</label>
            <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="e.g. report.pdf">
          </div>
          <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['all'=>'All','active'=>'Active','expired'=>'Expired','limited'=>'Limited'] as $k=>$v): ?>
                <option value="<?=h($k)?>" <?= $status===$k?'selected':'' ?>><?=h($v)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Sort</label>
            <select name="sort" class="form-select">
              <?php
              $opts = [
                'id_desc'=>'Newest (ID ↓)','id_asc'=>'Oldest (ID ↑)',
                'created_desc'=>'Created ↓','created_asc'=>'Created ↑',
                'size_desc'=>'Size ↓','size_asc'=>'Size ↑',
                'exp_desc'=>'Expires ↓','exp_asc'=>'Expires ↑',
                'dl_desc'=>'Downloads ↓','dl_asc'=>'Downloads ↑',
              ];
              foreach ($opts as $k=>$v): ?>
                <option value="<?=h($k)?>" <?= $sort===$k?'selected':'' ?>><?=h($v)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-1">
            <label class="form-label">Per page</label>
            <input type="number" name="pp" value="<?= (int)$perPage ?>" min="10" max="200" class="form-control">
          </div>
          <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-light btn-pill w-100">Apply</button>
            <a class="btn btn-outline-light btn-pill w-100" href="admin.php">Reset</a>
          </div>
        </form>
      </div>

      <form method="post" class="panel mb-3">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <div class="d-flex flex-wrap gap-2 mb-2">
          <button name="action" value="delete_selected" class="btn btn-danger btn-sm btn-pill" onclick="return confirm('Delete selected rows? This cannot be undone.');">Delete selected</button>
          <button name="action" value="delete_expired" class="btn btn-warning btn-sm btn-pill" onclick="return confirm('Delete all expired rows?');">Delete expired</button>
          <button name="action" value="export_csv" class="btn btn-success btn-sm btn-pill">Export CSV (current filter)</button>
          <div class="ms-auto text-end">
            <span class="badge text-bg-light">Filtered: <?= (int)$filteredCount ?></span>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.rowchk').forEach(cb=>cb.checked=this.checked)"></th>
                <th>ID</th>
                <th>Name</th>
                <th>Size</th>
                <th>Downloads</th>
                <th>Expires</th>
                <th>Created</th>
                <th>Status</th>
                <th>Link</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted">No records</td></tr>
              <?php else: foreach ($rows as $r):
                $active = is_active_row($r);
                $badgeClass = $active ? 'status-active' : 'status-inactive';
                $badgeText  = $active ? 'Active' : 'Inactive';
                $link = base_url().'/dl.php?id='.$r['id'];
                ?>
                <tr>
                  <td><input type="checkbox" class="rowchk" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
                  <td><?= (int)$r['id'] ?></td>
                  <td class="text-truncate" style="max-width:260px" title="<?= h($r['orig_name']) ?>"><?= h($r['orig_name']) ?></td>
                  <td><?= human_size((int)$r['size']) ?></td>
                  <td><?= (int)$r['downloads'] ?><?= $r['max_downloads'] ? ' / '.(int)$r['max_downloads'] : '' ?></td>
                  <td><span title="<?= h($r['expires_at']) ?>"><?= h($r['expires_at']) ?></span></td>
                  <td><?= h($r['created_at'] ?? '') ?></td>
                  <td><span class="status-badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                  <td>
                    <a class="btn btn-outline-primary btn-sm btn-pill" href="<?= h($link) ?>" target="_blank">Open</a>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-pill" onclick="copyLink('<?= h($link) ?>', this)">Copy</button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php
        $totalPages = (int)ceil($filteredCount / $perPage);
        if ($totalPages > 1):
          $qs = $qsBase ? ($qsBase.'&') : '';
        ?>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
              <a class="page-link" href="?<?= $qs ?>page=<?= max(1,$page-1) ?>">Prev</a>
            </li>
            <?php
              $start = max(1, $page-2);
              $end   = min($totalPages, $page+2);
              for ($i=$start; $i<=$end; $i++):
            ?>
              <li class="page-item <?= $i===$page?'active':'' ?>">
                <a class="page-link" href="?<?= $qs ?>page=<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
              <a class="page-link" href="?<?= $qs ?>page=<?= min($totalPages,$page+1) ?>">Next</a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>

      </form>


<script>
function copyLink(link, btn){
  (navigator.clipboard?.writeText(link) || Promise.reject()).then(()=>{
    btn.textContent = 'Copied!';
    setTimeout(()=>btn.textContent='Copy', 1200);
  }).catch(()=>{
    const tmp = document.createElement('input');
    tmp.value = link; document.body.appendChild(tmp); tmp.select(); document.execCommand('copy'); tmp.remove();
    btn.textContent = 'Copied!'; setTimeout(()=>btn.textContent='Copy', 1200);
  });
}
</script>
</body>
</html>
