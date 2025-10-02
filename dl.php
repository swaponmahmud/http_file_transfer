<?php
// dl.php — Beautiful download page + ad slots + human file size
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function human_size(int $bytes, int $decimals = 1): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB','PB'];
    $pow = (int) floor(log($bytes, 1024));
    $pow = max(0, min($pow, count($units) - 1));
    $value = $bytes / (1024 ** $pow);
    $precision = ($value < 10 && $pow > 0) ? $decimals : 0;
    return number_format($value, $precision) . ' ' . $units[$pow];
}

function time_left_str(DateTime $now, DateTime $expires): string {
    if ($expires <= $now) return 'Expired';
    $diff = $now->diff($expires);
    $parts = [];
    if ($diff->d > 0) $parts[] = $diff->d . ' day' . ($diff->d>1?'s':'');
    if ($diff->h > 0) $parts[] = $diff->h . ' hr';
    if ($diff->i > 0 && count($parts) < 2) $parts[] = $diff->i . ' min';
    if (!$parts) $parts[] = 'less than a minute';
    return implode(' ', $parts);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $errorMsg = 'Invalid link';
} else {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = :id LIMIT 1");
    $stmt->execute([':id'=>$id]);
    $file = $stmt->fetch();
    if (!$file) {
        $errorMsg = 'Invalid or expired link';
    }
}

$now = new DateTime();
$expired = false;
$limitReached = false;

if (empty($errorMsg)) {
    $expires = new DateTime($file['expires_at']);
    if ($now > $expires) {
        $expired = true;
        $errorMsg = 'This link has expired.';
    }
    if (!$expired && $file['max_downloads'] !== null && (int)$file['downloads'] >= (int)$file['max_downloads']) {
        $limitReached = true;
        $errorMsg = 'Download limit reached for this file.';
    }
}

$csrf = csrf_token();
$sizeNice = isset($file['size']) ? human_size((int)$file['size']) : '—';

// Build share URL (this page)
$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$shareUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?id=' . urlencode((string)$id);

// Remaining downloads (if capped)
$remaining = null;
if (!empty($file) && $file['max_downloads'] !== null) {
    $remaining = max(0, (int)$file['max_downloads'] - (int)$file['downloads']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= isset($file['orig_name']) ? h($file['orig_name']) . ' — ' : '' ?>Download</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Bootstrap (optional for base components) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ===== Page theme (gray gradient) ===== */
    :root{
      --bg1:#808896;   /* dark gray */
      --bg2:#aaaaaa;   /* light gray */
      --panel:#2e726b; /* panel background */
      --white:#ffffff;
      --soft:#e0e0e0;
      --ink:#111111;
    }
    html,body{height:100%;}
    body{
      margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: radial-gradient(1200px 600px at 50% -10%, var(--bg2) 0, var(--bg1) 60%);
      color:#fff;
      padding:16px;
    }

    /* ===== Layout: main + right aside ===== */
    .wrap{width:min(1140px, 100%); margin:0 auto;}
    .layout{display:flex; gap:16px; align-items:stretch;}
    .main{flex:1; min-width:0;}
    .aside{width:320px; max-width:100%;}
    .aside-sticky{ position: sticky; top:16px; }

    /* ===== Ad slots (white) ===== */
    .ad-slot{
      display:flex; align-items:center; justify-content:center;
      background:#ffffff;
      border:1px solid rgba(0,0,0,.08);
      border-radius:14px;
      text-align:center;
      color:#374151;
      box-shadow:0 4px 16px rgba(0,0,0,.08);
    }
    .ad-top{ height:90px; margin-bottom:14px; }
    .ad-right{ height:600px; }
    .ad-bottom{ height:250px; margin-top:16px; }
    .ad-title{ font-weight:700; color:#111827; }
    .ad-sub{ font-size:12px; color:#6b7280; }

    /* ===== Panel (file card) ===== */
    .panel{
      background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(0,0,0,.12)), var(--panel);
      border-radius: 22px; padding: 24px 22px; box-shadow: 0 20px 60px rgba(0,0,0,.35);
      border: 1px solid rgba(255,255,255,.15);
    }
    .file-head{
      display:flex; gap:14px; align-items:center; margin-bottom:10px;
    }
    .file-icon{
      width:52px; height:52px; border-radius:12px; background:rgba(255,255,255,.15);
      display:flex; align-items:center; justify-content:center; font-weight:900; color:#222;
      background-image: linear-gradient(#fff,#ddd);
      border:1px solid rgba(0,0,0,.08);
      box-shadow: 0 6px 16px rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.7);
    }
    .file-title{ margin:0; font-size:20px; font-weight:800; letter-spacing:.2px; }
    .muted{ color:var(--soft); font-size:12px; }

    .info-grid{
      display:grid; grid-template-columns: 1fr 1fr; gap:10px 16px; margin:12px 0 18px;
    }
    .info-grid .k{opacity:.85;}
    .info-grid .v{font-weight:600;}

    .btn-pill{ border-radius:999px; font-weight:700; padding:.6rem 1.2rem; }

    /* ===== Download button: BLUE gradient to stand out ===== */
    .btn-download{
      color:#fff;
      background: linear-gradient(#4facfe,#00f2fe); /* blue gradient */
      border:0;
      box-shadow: 0 8px 20px rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.4);
    }
    .btn-download:hover{ filter:brightness(1.08); }

    .btn-outline{
      color:#fff; border:2px solid rgba(255,255,255,.5); background:transparent;
    }

    .sharebox{ display:flex; gap:8px; margin-top:12px; align-items:stretch; }
    .sharebox input{
      flex:1; border-radius:10px; padding:10px 12px; border:none; background:rgba(255,255,255,.92); color:#111;
    }
    .sharebox .btn{
      height:100%;
    }

    .alert-soft{
      background: rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25); color:#fff;
      border-radius:12px; padding:10px 12px; margin-bottom:12px;
    }

    /* ===== Error card ===== */
    .error-card{
      background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(0,0,0,.12)), #444;
      border:1px solid rgba(255,255,255,.18);
      border-radius:22px; padding:24px 22px; box-shadow:0 20px 60px rgba(0,0,0,.35);
    }

    @media (max-width: 992px){
      .layout{ flex-direction: column; }
      .aside{ width:auto; }
      .aside-sticky{ position: static; }
      .info-grid{ grid-template-columns: 1fr; }
      .sharebox{ flex-direction:column; }
      .sharebox .btn{ width:100%; }
    }
  </style>
</head>
<body>

<div class="wrap">

  <!-- ===== Top Banner Ad Slot ===== -->
  <div class="ad-slot ad-top" id="adTop">
    <div>
      <div class="ad-title">Ad Slot — Top Banner</div>
      <div class="ad-sub">728×90 / 320×100</div>
    </div>
  </div>

  <div class="layout">

    <div class="main">
      <?php if (!empty($errorMsg)): ?>
        <div class="error-card">
          <h4 class="mb-2 fw-bold">Download unavailable</h4>
          <div class="mb-2"><?= h($errorMsg) ?></div>
          <div class="muted">If you believe this is a mistake, ask the sender to re-share the file.</div>
        </div>

        <div class="ad-slot ad-bottom mt-3" id="adBottom">
          <div>
            <div class="ad-title">Ad Slot — Bottom</div>
            <div class="ad-sub">300×250</div>
          </div>
        </div>
      <?php else: ?>
        <div class="panel">
          <div class="file-head">
            <div class="file-icon"><?= strtoupper(substr((string)$file['orig_name'], -3)) ?></div>
            <div>
              <h1 class="file-title"><?= h($file['orig_name']) ?></h1>
              <div class="muted">Private download link • Generated for sharing</div>
            </div>
          </div>

          <?php
            $expiresAtStr = h($file['expires_at']);
            $leftStr = time_left_str($now, new DateTime($file['expires_at']));
            $downloadsStr = (int)$file['downloads'];
            $maxStr = $file['max_downloads'] ? '/ ' . (int)$file['max_downloads'] : '';
            $remainStr = ($remaining !== null) ? " ({$remaining} left)" : '';
          ?>

          <div class="info-grid">
            <div><span class="k">File Size</span><br><span class="v"><?= h($sizeNice) ?></span></div>
            <div><span class="k">Expires</span><br><span class="v"><?= $expiresAtStr ?> <span class="muted">• in <?= h($leftStr) ?></span></span></div>
            <div><span class="k">Total Downloads</span><br><span class="v"><?= $downloadsStr ?> <?= $maxStr ?><?= $remainStr ?></span></div>
          </div>

          <?php if ($expired || $limitReached): ?>
            <div class="alert-soft mb-3"><?= h($errorMsg) ?></div>
          <?php endif; ?>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <!-- Download -->
            <form action="download.php" method="post" class="m-0">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= h((string)$id) ?>">
              <button class="btn btn-download btn-pill" <?= ($expired || $limitReached) ? 'disabled' : '' ?>>
                <?= ($expired || $limitReached) ? 'Download disabled' : 'Download' ?>
              </button>
            </form>

            <!-- Share link (hidden by default) -->
            <div class="sharebox ms-2">
              <input id="shareLink" type="password" readonly value="<?= h($shareUrl) ?>" aria-label="Share link">
              <button id="btnToggle" type="button" class="btn btn-outline btn-pill" aria-pressed="false" aria-label="Show link">👁 Show</button>
              <button id="btnCopy" type="button" class="btn btn-outline btn-pill" aria-label="Copy link">Copy</button>
            </div>
          </div>
        </div>

        <div class="ad-slot ad-bottom" id="adBottom" style="margin-top:16px;">
          <div>
            <div class="ad-title">Ad Slot — Bottom</div>
            <div class="ad-sub">300×250</div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <aside class="aside">
      <div class="aside-sticky">
        <div class="ad-slot ad-right" id="adRight">
          <div>
            <div class="ad-title">Ad Slot — Right</div>
            <div class="ad-sub">300×600 (Half Page)</div>
          </div>
        </div>
      </div>
    </aside>

  </div>
</div>

<script>
(function(){
  const btnCopy   = document.getElementById('btnCopy');
  const btnToggle = document.getElementById('btnToggle');
  const shareInp  = document.getElementById('shareLink');

  // Toggle show/hide for the share link
  if (btnToggle && shareInp){
    btnToggle.addEventListener('click', ()=>{
      const isHidden = (shareInp.type === 'password');
      shareInp.type  = isHidden ? 'text' : 'password';
      btnToggle.textContent = isHidden ? '🙈 Hide' : '👁 Show';
      btnToggle.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
      btnToggle.setAttribute('aria-label', isHidden ? 'Hide link' : 'Show link');
    });
  }

  // Copy link (temporarily switch to text to ensure selection works reliably)
  if (btnCopy && shareInp){
    btnCopy.addEventListener('click', ()=>{
      const wasPassword = (shareInp.type === 'password');
      if (wasPassword) shareInp.type = 'text';
      shareInp.select();
      shareInp.setSelectionRange(0, 99999);
      const ok = document.execCommand('copy');
      btnCopy.textContent = ok ? 'Copied!' : 'Copy failed';
      setTimeout(()=> btnCopy.textContent = 'Copy', 1200);
      // restore previous visibility state
      shareInp.type = (wasPassword ? 'password' : 'text');
    });
  }
})();
</script>
</body>
</html>
