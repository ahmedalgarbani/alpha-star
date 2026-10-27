<?php
/* نجوم الفاء — website editor for PHP web hosting.
   Open  https://your-domain.com/admin/  and log in with the password from admin/config.php. */

define('ED_APP', 1);
require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

/* ---------------- login / logout ---------------- */
if (isset($_GET['logout'])) {
  ed_logout();
  header('Location: index.php');
  exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
  $err = ed_try_login($_POST['username'] ?? '', $_POST['password']);
  if ($err === null) { header('Location: ' . (ed_is_admin() ? 'index.php' : 'content.php')); exit; }
  $loginError = $err;
  http_response_code(401);
}

if (!ed_logged_in()) {
  if (isset($_GET['api'])) ed_json(401, ['error' => 'Please log in again']);
  if (!$ED_CONFIGURED) $loginError = 'لم يتم ضبط كلمة المرور بعد — افتح الملف admin/config.php وضع كلمة مرور (١٠ أحرف على الأقل).';
  header('Cache-Control: no-store');
  header('X-Frame-Options: DENY');
  ?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>دخول المحرر</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0e1116;color:#e8ecf1;font:15px system-ui,'Segoe UI',sans-serif}
  form{background:#151a21;border:1px solid #2a313c;border-radius:14px;padding:28px;width:min(340px,100% - 32px);box-sizing:border-box}
  h1{font-size:18px;margin:0 0 18px}
  input{width:100%;box-sizing:border-box;padding:11px;border-radius:8px;border:1px solid #2a313c;background:#0f1318;color:inherit;font:inherit;margin-bottom:12px}
  button{width:100%;padding:11px;border:0;border-radius:8px;background:#F6B100;color:#1a1400;font:inherit;font-weight:700;cursor:pointer}
  p{color:#fca5a5;margin:0 0 12px;line-height:1.6}
</style></head><body>
<form method="post" action="index.php">
  <h1>🔒 محرر الموقع</h1>
  <?php if ($loginError) echo '<p>' . htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') . '</p>'; ?>
  <input type="text" name="username" placeholder="اسم المستخدم (اتركه فارغاً للمدير)" autocomplete="username" dir="auto" value="<?= htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
  <input type="password" name="password" placeholder="كلمة المرور" autofocus required autocomplete="current-password">
  <button>دخول</button>
</form>
</body></html>
<?php
  exit;
}

// The page designer is for the owner/admins; employees work in the content dashboard (blog, ads).
if (!ed_is_admin()) {
  if (isset($_GET['api'])) ed_json(403, ['error' => 'Admins only']);
  header('Location: content.php');
  exit;
}

/* ---------------- API (logged in only) ---------------- */
if (isset($_GET['api'])) {
  session_write_close();   // don't block parallel requests
  $method = $_SERVER['REQUEST_METHOD'];
  if ($method === 'POST' && !ed_same_origin()) ed_json(403, ['error' => 'Forbidden']);
  $api = $_GET['api'];

  if ($api === 'file') {
    $rel = (string) ($_GET['path'] ?? '');
    if (!in_array($rel, ED_EDITABLE, true)) ed_json(400, ['error' => 'File not editable']);
    $file = $ED_ROOT . '/' . $rel;
    if ($method === 'GET') {
      header('Content-Type: text/plain; charset=utf-8');
      header('Cache-Control: no-store');
      readfile($file);
      exit;
    }
    if ($method === 'POST') {
      $body = (string) file_get_contents('php://input');
      if (trim($body) === '') ed_json(400, ['error' => 'Empty content']);
      ed_backup($rel);
      if (file_put_contents($file, $body, LOCK_EX) === false) ed_json(500, ['error' => 'Could not write ' . $rel . ' — check file permissions']);
      ed_json(200, ['ok' => true]);
    }
  }

  if ($api === 'images' && $method === 'GET') {
    $list = [];
    foreach (scandir($ED_ROOT . '/assets/img') as $f) {
      if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ED_IMG_EXT, true)) $list[] = 'assets/img/' . $f;
    }
    ed_json(200, $list);
  }

  if ($api === 'upload' && $method === 'POST') {
    list($name, $ext) = ed_safe_name((string) ($_GET['name'] ?? 'image.jpg'));
    if (!in_array($ext, ED_UPLOAD_EXT, true)) ed_json(400, ['error' => 'Only JPG, PNG, WEBP, GIF or AVIF images']);
    $data = (string) file_get_contents('php://input');
    if ($data === '' ) ed_json(400, ['error' => 'Empty file (it may be bigger than the hosting upload limit)']);
    if (strlen($data) > 15 * 1024 * 1024) ed_json(400, ['error' => 'Image is larger than 15 MB']);
    if ($ext !== 'avif' && @getimagesizefromstring($data) === false) ed_json(400, ['error' => 'That file is not a valid image']);
    if (file_put_contents($ED_ROOT . '/assets/img/' . $name, $data) === false) ed_json(500, ['error' => 'Could not save image — check folder permissions']);
    ed_json(200, ['path' => 'assets/img/' . $name]);
  }

  ed_json(404, ['error' => 'Unknown request']);
}

/* ---------------- editor page ---------------- */
$cfg = [
  'root' => '../', 'preview' => '../editor-preview.php', 'file' => 'index.php?api=file&path=',
  'images' => 'index.php?api=images', 'upload' => 'index.php?api=upload&name=',
  'login' => 'index.php', 'logout' => 'index.php?logout=1', 'content' => 'content.php'
];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
echo str_replace('<!--ED_CONFIG-->', '<script>window.ED = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';</script>',
  (string) file_get_contents(__DIR__ . '/editor.html'));
