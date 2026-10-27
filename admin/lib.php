<?php
/* نجوم الفاء — shared code for the PHP editor (login, sessions, files).
   Used by admin/index.php and editor-preview.php. Set your password in admin/config.php. */

if (!defined('ED_APP')) { http_response_code(404); exit; }

const ED_EDITABLE = ['index.html', 'css/styles.css', 'js/main.js', 'sitemap.xml'];
const ED_IMG_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'];
const ED_UPLOAD_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];   // no SVG: it can carry scripts
const ED_SESSION_HOURS = 12;
const ED_MAX_TRIES = 5;
const ED_LOCK_MINUTES = 15;

$ED_ROOT = dirname(__DIR__);
$ED_DATA = __DIR__ . '/data';
$ED_BACKUPS = __DIR__ . '/backups';

$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$ED_PASSWORD = is_array($cfg) && isset($cfg['password']) ? (string) $cfg['password'] : '';
$ED_CONFIGURED = strlen($ED_PASSWORD) >= 10 && $ED_PASSWORD !== 'CHANGE-THIS-PASSWORD';

function ed_https() {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

session_name('ed_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => ed_https(), 'samesite' => 'Strict']);
session_start();

function ed_logged_in() {
  return !empty($_SESSION['ed_until']) && $_SESSION['ed_until'] > time() && !empty($_SESSION['ed_user']);
}
// Logged-in person: ['id' => 0 for the owner (password in config.php) or users.id, 'name', 'username', 'role' => admin|employee]
function ed_user() { return $_SESSION['ed_user'] ?? null; }
function ed_is_admin() { return ed_logged_in() && ($_SESSION['ed_user']['role'] ?? '') === 'admin'; }

// Reject requests posted from other websites (CSRF).
function ed_same_origin() {
  $o = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
  if ($o === '') return true;
  $host = parse_url($o, PHP_URL_HOST);
  $port = parse_url($o, PHP_URL_PORT);
  if ($port) $host .= ':' . $port;
  return $host !== null && strcasecmp($host, $_SERVER['HTTP_HOST'] ?? '') === 0;
}

/* ---- failed-login lockout (per IP, stored in admin/data) ---- */
// Create a private folder (with its own deny rule, in case the parent .htaccess is ignored).
function ed_private_dir($dir) {
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied
");
  if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
}

function ed_attempts_file() {
  global $ED_DATA;
  ed_private_dir($ED_DATA);
  return $ED_DATA . '/attempts.json';
}
function ed_attempts_load() {
  $f = ed_attempts_file();
  $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
  return is_array($d) ? $d : [];
}
function ed_attempts_save($d) {
  $now = time();
  foreach ($d as $ip => $r) if (($r['until'] ?? 0) < $now && ($r['first'] ?? 0) < $now - ED_LOCK_MINUTES * 60) unset($d[$ip]);
  file_put_contents(ed_attempts_file(), json_encode($d), LOCK_EX);
}
function ed_locked($ip) {
  $d = ed_attempts_load();
  return isset($d[$ip]) && ($d[$ip]['until'] ?? 0) > time();
}
function ed_fail($ip) {
  $d = ed_attempts_load();
  $r = $d[$ip] ?? null;
  if (!$r || time() - ($r['first'] ?? 0) > ED_LOCK_MINUTES * 60) $r = ['count' => 0, 'until' => 0, 'first' => time()];
  $r['count']++;
  if ($r['count'] >= ED_MAX_TRIES) $r = ['count' => 0, 'until' => time() + ED_LOCK_MINUTES * 60, 'first' => time()];
  $d[$ip] = $r;
  ed_attempts_save($d);
}
function ed_clear_fails($ip) {
  $d = ed_attempts_load();
  unset($d[$ip]);
  ed_attempts_save($d);
}

// Owner: user name empty or "admin" + the password from config.php.  Employees: their own account (made by the owner in the dashboard).
function ed_try_login($username, $password) {
  global $ED_PASSWORD, $ED_CONFIGURED;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  $username = mb_strtolower(trim((string) $username));
  if (ed_locked($ip)) return 'محاولات كثيرة. حاول بعد ' . ED_LOCK_MINUTES . ' دقيقة.';
  if (!ed_same_origin()) return 'طلب غير مسموح.';

  $user = null;
  if ($username === '' || $username === 'admin') {
    if (!$ED_CONFIGURED) return 'لم يتم ضبط كلمة المرور بعد — عدّل الملف admin/config.php';
    if (hash_equals(hash('sha256', $ED_PASSWORD), hash('sha256', (string) $password))) {
      $user = ['id' => 0, 'name' => 'المدير', 'username' => 'admin', 'role' => 'admin'];
    }
  } else {
    ed_db();
    $row = db_one('SELECT * FROM users WHERE username = ?', [$username]);
    // same work whether the user exists or not (no hint which user names are real)
    $ok = password_verify((string) $password, $row['password_hash'] ?? '$2y$10$' . str_repeat('x', 53));
    if ($row && $ok && (int) $row['active'] === 1) {
      $user = ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username'], 'role' => $row['role']];
      db_run('UPDATE users SET last_login = ? WHERE id = ?', [now(), $row['id']]);
    }
  }
  if (!$user) {
    ed_fail($ip);
    return 'اسم المستخدم أو كلمة المرور غير صحيحة.';
  }
  ed_clear_fails($ip);
  session_regenerate_id(true);
  $_SESSION['ed_until'] = time() + ED_SESSION_HOURS * 3600;
  $_SESSION['ed_user'] = $user;
  $_SESSION['ed_csrf'] = bin2hex(random_bytes(32));
  return null;
}

// Database + helpers shared with the public pages (inc/app.php).
function ed_db() {
  if (!defined('SITE_APP')) define('SITE_APP', 1);
  require_once dirname(__DIR__) . '/inc/app.php';
  return db();
}

function ed_logout() {
  $_SESSION = [];
  $p = session_get_cookie_params();
  setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'secure' => $p['secure'], 'samesite' => 'Strict']);
  session_destroy();
}

function ed_json($code, $data) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ed_backup($rel) {
  global $ED_ROOT, $ED_BACKUPS;
  $src = $ED_ROOT . '/' . $rel;
  if (!is_file($src)) return;
  ed_private_dir($ED_BACKUPS);
  $stamp = gmdate('Y-m-d\TH-i-s') . '-' . substr((string) microtime(true), -4);
  copy($src, $ED_BACKUPS . '/' . $stamp . '__' . str_replace('/', '_', $rel));
}

function ed_safe_name($name) {
  global $ED_ROOT;
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $base = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($name, PATHINFO_FILENAME)), '-');
  if ($base === '') $base = 'image';
  $out = $base . '.' . $ext; $i = 1;
  while (file_exists($ED_ROOT . '/assets/img/' . $out)) $out = $base . '-' . ($i++) . '.' . $ext;
  return [$out, $ext];
}

// Preview copy of the page: scripts switched off so the DOM stays identical to the source file.
function ed_preview_html() {
  global $ED_ROOT;
  $html = (string) file_get_contents($ED_ROOT . '/index.html');
  return preg_replace_callback('/<script\b([^>]*)>/i', function ($m) {
    $attrs = $m[1];
    if (preg_match('/\stype\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $attrs, $t)) {
      $attrs = str_replace($t[0], ' data-ed-type=' . $t[1], $attrs);
    }
    return '<script type="text/x-ed-off" data-ed-script' . $attrs . '>';
  }, $html);
}
