<?php
/* نجوم الفاء — shared core for the dynamic pages (blog, ads, FAQ) and the content dashboard.
   Database: SQLite by default (file in admin/data, nothing to set up) or MySQL — see admin/config.php. */

if (!defined('SITE_APP')) { http_response_code(404); exit; }

date_default_timezone_set('Asia/Riyadh');
mb_internal_encoding('UTF-8');

const SITE_ROOT = __DIR__ . '/..';
const SITE_NAME = 'نجوم الفاء للتجارة';
const SITE_NAME_EN = 'Nujoom Al-Fa Trading';
const SITE_PHONE = '+966503516218';
const SITE_PHONE_TXT = '+966 50 351 6218';
const SITE_WA = '966503516218';
const SITE_LOCALE = 'ar_SA';
const SITE_LANG = 'ar-SA';
const DB_VERSION = 1;
const PER_PAGE = 9;

/* Cities we serve: [ISO 3166-2 region, latitude, longitude] — used for geo meta tags and schema.org Place data. */
const CITIES = [
  'الرياض'          => ['SA-01', 24.7136, 46.6753],
  'جدة'             => ['SA-02', 21.4858, 39.1925],
  'مكة المكرمة'     => ['SA-02', 21.3891, 39.8579],
  'المدينة المنورة' => ['SA-03', 24.5247, 39.5692],
  'الدمام'          => ['SA-04', 26.4207, 50.0888],
  'الخبر'           => ['SA-04', 26.2172, 50.1971],
  'الجبيل'          => ['SA-04', 27.0174, 49.6225],
  'ينبع'            => ['SA-03', 24.0895, 38.0618],
  'الطائف'          => ['SA-02', 21.2703, 40.4158],
  'القصيم'          => ['SA-05', 26.3592, 43.9818],
  'تبوك'            => ['SA-07', 28.3835, 36.5662],
  'أبها'            => ['SA-14', 18.2164, 42.5053],
  'جازان'           => ['SA-09', 16.8892, 42.5511],
];
const AD_CATEGORIES = ['ونشات ورافعات', 'مان لفت', 'حفارات وشيولات', 'رافعات شوكية وتيليهندل', 'معدات أخرى'];
const AD_DEALS = ['rent' => 'للإيجار', 'sale' => 'للبيع'];
const PRICE_UNITS = ['day' => 'يومي', 'week' => 'أسبوعي', 'month' => 'شهري', 'year' => 'سنوي', 'hour' => 'بالساعة', 'once' => 'سعر نهائي'];
const AD_CONDITIONS = ['' => '—', 'new' => 'جديدة', 'used' => 'مستعملة'];

function site_config() {
  static $cfg = null;
  if ($cfg === null) {
    $f = SITE_ROOT . '/admin/config.php';
    $cfg = is_file($f) ? require $f : [];
    if (!is_array($cfg)) $cfg = [];
  }
  return $cfg;
}

/* ---------------- URLs ---------------- */
function base_url() {
  $u = (string) (site_config()['site_url'] ?? '');
  if ($u === '') {   // fallback for local testing only — set site_url in admin/config.php on the live site
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $u = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
  }
  return rtrim($u, '/');
}
function url($path = '/') { return base_url() . $path; }
function post_path($slug) { return '/blog/' . rawurlencode($slug); }
function ad_path($slug) { return '/ads/' . rawurlencode($slug); }
function abs_asset($p) {
  if ($p === '' || $p === null) return '';
  if (preg_match('~^https?://~i', $p)) return $p;
  return url('/' . ltrim($p, '/'));
}
function query_path($path, array $params) {
  $params = array_filter($params, function ($v) { return $v !== '' && $v !== null && $v !== 1; });
  return $path . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

// One address per page: send /blog → /blog/, /blog.php?slug=x → /blog/x, etc. (avoids duplicate pages in Google)
function enforce_path($canonicalPath) {
  if (PHP_SAPI === 'cli') return;
  $reqPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
  $want = rawurldecode((string) parse_url($canonicalPath, PHP_URL_PATH));
  if ($reqPath !== $want) {
    $qs = $_GET; unset($qs['slug']);
    header('Location: ' . query_path((string) parse_url($canonicalPath, PHP_URL_PATH), $qs), true, 301);
    exit;
  }
}

/* ---------------- database ---------------- */
function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = site_config()['db'] ?? [];
  $driver = $c['driver'] ?? 'sqlite';
  $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
  if ($driver === 'mysql') {
    $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost') . (empty($c['port']) ? '' : ';port=' . $c['port']) . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', $opts);
  } else {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      throw new RuntimeException('SQLite is not enabled on this server — set the MySQL details in admin/config.php');
    }
    $dir = SITE_ROOT . '/admin/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    $pdo = new PDO('sqlite:' . $dir . '/site.sqlite', null, null, $opts);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 4000;');
  }
  db_migrate($pdo, $driver);
  return $pdo;
}
function db_is_mysql() { return (site_config()['db']['driver'] ?? 'sqlite') === 'mysql'; }

function db_migrate(PDO $pdo, $driver) {
  $my = $driver === 'mysql';
  $id = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  $txt = $my ? 'MEDIUMTEXT' : 'TEXT';
  $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
  $pdo->exec("CREATE TABLE IF NOT EXISTS site_meta (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255) NOT NULL)$tail");
  $ver = (int) $pdo->query("SELECT v FROM site_meta WHERE k = 'db_version'")->fetchColumn();
  if ($ver >= DB_VERSION) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id $id, username VARCHAR(60) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, bio VARCHAR(500) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'employee', active SMALLINT NOT NULL DEFAULT 1,
    created_at VARCHAR(19) NOT NULL, last_login VARCHAR(19) NULL)$tail");
  $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
    id $id, slug VARCHAR(190) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, excerpt VARCHAR(600) NOT NULL DEFAULT '',
    content $txt NOT NULL, cover VARCHAR(255) NOT NULL DEFAULT '', cover_alt VARCHAR(255) NOT NULL DEFAULT '',
    category VARCHAR(100) NOT NULL DEFAULT '', tags VARCHAR(255) NOT NULL DEFAULT '', city VARCHAR(60) NOT NULL DEFAULT '',
    meta_title VARCHAR(255) NOT NULL DEFAULT '', meta_desc VARCHAR(400) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft', author_id INT NOT NULL DEFAULT 0,
    published_at VARCHAR(19) NULL, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)$tail");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ads (
    id $id, slug VARCHAR(190) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, description $txt NOT NULL,
    deal VARCHAR(10) NOT NULL DEFAULT 'rent', category VARCHAR(100) NOT NULL DEFAULT '', city VARCHAR(60) NOT NULL DEFAULT '',
    price DECIMAL(12,2) NULL, price_unit VARCHAR(10) NOT NULL DEFAULT 'day', item_condition VARCHAR(10) NOT NULL DEFAULT '',
    images $txt NOT NULL, phone VARCHAR(30) NOT NULL DEFAULT '', meta_desc VARCHAR(400) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft', author_id INT NOT NULL DEFAULT 0,
    published_at VARCHAR(19) NULL, expires_at VARCHAR(19) NULL, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)$tail");
  $pdo->exec("CREATE TABLE IF NOT EXISTS faqs (
    id $id, question VARCHAR(400) NOT NULL, answer $txt NOT NULL, category VARCHAR(100) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'published',
    created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)$tail");
  foreach (['posts' => 'status, published_at', 'ads' => 'status, published_at'] as $t => $cols) {
    try { $pdo->exec("CREATE INDEX idx_{$t}_pub ON $t ($cols)"); } catch (PDOException $e) { /* already there */ }
  }

  if ($ver === 0 && !(int) $pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn()) faq_seed($pdo);
  $sql = $my ? "REPLACE INTO site_meta (k, v) VALUES ('db_version', ?)" : "INSERT OR REPLACE INTO site_meta (k, v) VALUES ('db_version', ?)";
  $pdo->prepare($sql)->execute([(string) DB_VERSION]);
}

// Start the FAQ page with the questions already on the home page.
function faq_seed(PDO $pdo) {
  $now = now();
  $rows = [
    ['الأسعار والعقود', 'كم سعر تأجير ونش أو رافعة في الرياض؟', 'يعتمد السعر على حمولة الرافعة ومدة الإيجار (يومي، أسبوعي، شهري، سنوي) وموقع المشروع وهل تحتاج مشغلاً. جهّز طلبك من نموذج عرض السعر أو راسلنا واتساب ونرسل لك عرضاً واضحاً يشمل النقل والتشغيل خلال ساعة.'],
    ['المعدات', 'ما أحجام الرافعات المتوفرة للإيجار؟', 'نوفر ونشات ورافعات متحركة (كرين) من ٥ إلى ١٧٥ طن من ماركات مثل SANY وXCMG وDEMAG، إضافة إلى مان لفت بارتفاعات من ١٢ إلى ٤٥ متراً، ورافعات شوكية من ٣ إلى ١٦ طن.'],
    ['المعدات', 'هل تؤجرون المعدات مع مشغل؟', 'نعم، يمكنك استئجار المعدة مع مشغل معتمد بشهادات سلامة وخبرة ميدانية، أو بدون مشغل حسب احتياج مشروعك.'],
    ['مناطق الخدمة', 'هل توصلون المعدات خارج الرياض؟', 'نعم، نخدم الرياض وجميع مناطق المملكة: جدة ومكة والمدينة والدمام والخبر والجبيل وينبع والطائف والقصيم وتبوك وأبها وجازان، وننقل المعدة حتى موقع مشروعك.'],
    ['الأسعار والعقود', 'ما أقل مدة لتأجير المعدات؟', 'نوفر عقوداً مرنة تبدأ من الإيجار اليومي، إلى جانب الإيجار الأسبوعي والشهري والسنوي للمشاريع الطويلة.'],
    ['الصيانة والدعم', 'ماذا يحدث لو تعطلت المعدة أثناء العمل؟', 'لدينا فريق صيانة ميدانية متنقل يعمل ٢٤/٧، وإذا تعذر الإصلاح في الموقع نوفر معدة بديلة خلال ساعات حتى لا يتوقف مشروعك.'],
  ];
  $st = $pdo->prepare('INSERT INTO faqs (category, question, answer, sort_order, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
  foreach ($rows as $i => $r) $st->execute([$r[0], $r[1], $r[2], ($i + 1) * 10, 'published', $now, $now]);
}

function db_all($sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function db_one($sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r === false ? null : $r; }
function db_val($sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function db_run($sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->rowCount(); }

/* ---------------- shared queries ---------------- */
const POST_LIVE = "status = 'published' AND published_at IS NOT NULL AND published_at <= ?";
const AD_LIVE = "status = 'published' AND published_at IS NOT NULL AND published_at <= ? AND (expires_at IS NULL OR expires_at > ?)";

function author_of($id) {
  static $cache = [];
  $id = (int) $id;
  if (!array_key_exists($id, $cache)) {
    $cache[$id] = $id ? db_one('SELECT id, name, bio FROM users WHERE id = ?', [$id]) : null;
  }
  return $cache[$id];
}
function ad_images($ad) {
  $list = json_decode((string) ($ad['images'] ?? '[]'), true);
  return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}
function ad_price_text($ad) {
  if ($ad['price'] === null || (float) $ad['price'] <= 0) return 'السعر عند التواصل';
  $n = ar_num(number_format((float) $ad['price'], fmod((float) $ad['price'], 1) ? 2 : 0, '.', ','));
  $unit = PRICE_UNITS[$ad['price_unit']] ?? '';
  return $n . ' ريال' . ($ad['deal'] === 'rent' && $unit && $ad['price_unit'] !== 'once' ? ' / ' . $unit : '');
}
function ad_phone($ad) {
  $p = preg_replace('/[^0-9+]/', '', (string) ($ad['phone'] ?? ''));
  return $p !== '' ? $p : SITE_PHONE;
}
function wa_number($phone) {
  $d = preg_replace('/\D/', '', $phone);
  if (strpos($d, '05') === 0 && strlen($d) === 10) $d = '966' . substr($d, 1);
  return $d;
}

/* ---------------- text helpers ---------------- */
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now() { return date('Y-m-d H:i:s'); }
function iso_date($dt) { return $dt ? date('c', strtotime($dt)) : ''; }

function ar_num($s) { return strtr((string) $s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', ',' => '٬', '.' => '٫']); }
function ar_date($dt) {
  if (!$dt) return '';
  $m = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
  $t = strtotime($dt);
  return ar_num(date('j', $t)) . ' ' . $m[(int) date('n', $t) - 1] . ' ' . ar_num(date('Y', $t));
}

function slugify($text) {
  $s = mb_strtolower(trim((string) $text));
  $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);            // tashkeel + tatweel
  $s = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', $s);
  $s = trim(preg_replace('/-+/', '-', $s), '-');
  if (mb_strlen($s) > 80) $s = rtrim(mb_substr($s, 0, 80), '-');
  return $s;
}
function unique_slug($table, $slug, $exceptId = 0) {
  $base = $slug !== '' ? $slug : 'item';
  $s = $base; $i = 2;
  while (db_val("SELECT COUNT(*) FROM $table WHERE slug = ? AND id <> ?", [$s, (int) $exceptId])) $s = $base . '-' . $i++;
  return $s;
}

function plain_text($html) {
  $t = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/h[1-6]|/li|/blockquote)[^>]*>~i', "$0 ", (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim(preg_replace('/\s+/u', ' ', $t));
}
function cut($text, $len) {
  $text = trim((string) $text);
  if (mb_strlen($text) <= $len) return $text;
  $s = mb_substr($text, 0, $len);
  $sp = mb_strrpos($s, ' ');
  return rtrim($sp > $len * .6 ? mb_substr($s, 0, $sp) : $s, " ،,.-") . '…';
}
function reading_minutes($html) {
  $words = count(preg_split('/\s+/u', plain_text($html), -1, PREG_SPLIT_NO_EMPTY));
  return max(1, (int) round($words / 200));
}
function nl2p($text) {
  $parts = preg_split('/\n\s*\n/u', str_replace("\r", '', trim((string) $text)));
  $out = '';
  foreach ($parts as $p) if (trim($p) !== '') $out .= '<p>' . nl2br(e(trim($p)), false) . '</p>';
  return $out;
}

// Image size from a local file (for width/height attributes → no layout shift, better Core Web Vitals).
function img_size($path) {
  if ($path === '' || preg_match('~^https?://~i', $path)) return null;
  $f = SITE_ROOT . '/' . ltrim($path, '/');
  if (!is_file($f)) return null;
  $s = @getimagesize($f);
  return $s ? [$s[0], $s[1]] : null;
}
