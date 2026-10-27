<?php
/* سرعة الإنجاز الدولية — content dashboard: blog posts, equipment ads, FAQ, employee accounts.
   Owner (admin):  everything + employee accounts.   Employees: their own blog posts and ads.
   Open  /admin/  and log in — employees are sent here automatically. */

define('ED_APP', 1);
require __DIR__ . '/lib.php';
ed_db();
require dirname(__DIR__) . '/inc/sanitize.php';

const ASSET_VER_CMS = '20261027';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (!ed_logged_in()) { header('Location: index.php'); exit; }

$me = ed_user();
if ($me['id']) {   // an employee: make sure the account is still active, pick up role changes
  $row = db_one('SELECT * FROM users WHERE id = ?', [$me['id']]);
  if (!$row || !(int) $row['active']) { ed_logout(); header('Location: index.php'); exit; }
  $me = ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username'], 'role' => $row['role']];
  $_SESSION['ed_user'] = $me;
}
$isAdmin = $me['role'] === 'admin';
if (empty($_SESSION['ed_csrf'])) $_SESSION['ed_csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['ed_csrf'];

$s = (string) ($_GET['s'] ?? 'dash');
$a = (string) ($_GET['a'] ?? 'list');
$POST = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($POST) {
  $token = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
  if (!ed_same_origin() || !hash_equals($CSRF, $token)) {
    if ($s === 'upload') ed_json(403, ['error' => 'انتهت الجلسة — حدّث الصفحة']);
    flash('err', 'انتهت صلاحية الصفحة — أعد المحاولة.');
    go('?s=' . rawurlencode($s));
  }
}
if (in_array($s, ['faq', 'users'], true) && !$isAdmin) go('?s=dash');

/* ================= helpers ================= */
function go($q) { header('Location: content.php' . $q); exit; }
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function can_edit($row) { global $me, $isAdmin; return $isAdmin || (int) $row['author_id'] === (int) $me['id']; }
function p($k, $d = '') { return trim((string) ($_POST[$k] ?? $d)); }
function dt_in($v) {           // <input type=datetime-local> → DB
  $v = trim((string) $v);
  if ($v === '') return null;
  $t = strtotime(str_replace('T', ' ', $v));
  return $t ? date('Y-m-d H:i:s', $t) : null;
}
function dt_out($v) { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; }
function img_path_ok($p) { return $p === '' || (preg_match('~^assets/(img|uploads)/[A-Za-z0-9/_.-]+\.(jpe?g|png|webp|gif|avif)$~i', $p) && strpos($p, '..') === false); }
function status_badge($row, $isAd = false) {
  $now = now();
  if ($row['status'] !== 'published') return '<span class="badge">مسودة</span>';
  if ($row['published_at'] > $now) return '<span class="badge badge--blue">مجدول ' . e(substr($row['published_at'], 0, 16)) . '</span>';
  if ($isAd && $row['expires_at'] && $row['expires_at'] <= $now) return '<span class="badge badge--red">منتهي</span>';
  return '<span class="badge badge--green">منشور</span>';
}
function author_name($id) { $a = author_of($id); return $a ? $a['name'] : 'المدير'; }
function sel($a, $b) { return (string) $a === (string) $b ? ' selected' : ''; }
function save_row($table, $id, array $f) {
  if ($id) {
    $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($f)));
    db_run("UPDATE $table SET $set WHERE id = ?", array_merge(array_values($f), [$id]));
    return $id;
  }
  db_run("INSERT INTO $table (" . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_fill(0, count($f), '?')) . ')', array_values($f));
  return (int) db()->lastInsertId();
}

/* ================= image upload / library (JSON) ================= */
if ($s === 'upload' && $POST) {
  session_write_close();
  $name = (string) ($_GET['name'] ?? 'image.jpg');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === 'jpeg') $ext = 'jpg';
  if (!in_array($ext, ['jpg', 'png', 'webp', 'gif', 'avif'], true)) ed_json(400, ['error' => 'الصيغ المسموحة: JPG, PNG, WEBP, GIF, AVIF']);
  $data = (string) file_get_contents('php://input');
  if ($data === '') ed_json(400, ['error' => 'الملف فارغ (ربما أكبر من حد الرفع في الاستضافة)']);
  if (strlen($data) > 15 * 1024 * 1024) ed_json(400, ['error' => 'الصورة أكبر من ١٥ ميجابايت']);
  if ($ext !== 'avif' && @getimagesizefromstring($data) === false) ed_json(400, ['error' => 'الملف ليس صورة صالحة']);
  $data = shrink_image($data, $ext);
  $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME))), '-') ?: 'image';
  $rel = 'assets/uploads/' . date('Y/m') . '/' . substr($base, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
  $dir = dirname(SITE_ROOT . '/' . $rel);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) ed_json(500, ['error' => 'تعذر إنشاء مجلد الصور — تحقق من صلاحيات assets/']);
  if (file_put_contents(SITE_ROOT . '/' . $rel, $data) === false) ed_json(500, ['error' => 'تعذر حفظ الصورة']);
  ed_json(200, ['path' => $rel]);
}
if ($s === 'library') {
  session_write_close();
  $list = [];
  foreach (['assets/uploads', 'assets/img'] as $d) {
    if (!is_dir(SITE_ROOT . '/' . $d)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SITE_ROOT . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if (!preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $f->getFilename())) continue;
      $rel = $d . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen(SITE_ROOT . '/' . $d) + 1));
      $list[] = ['path' => $rel, 't' => $f->getMTime()];
    }
  }
  usort($list, function ($x, $y) { return $y['t'] - $x['t']; });
  ed_json(200, array_column(array_slice($list, 0, 300), 'path'));
}

// Big phone photos slow the site down (bad for Google): resize to max 1800px wide and re-compress. Also drops GPS/EXIF data.
function shrink_image($data, $ext) {
  if (!function_exists('imagecreatefromstring') || in_array($ext, ['gif', 'avif'], true)) return $data;
  $im = @imagecreatefromstring($data);
  if (!$im) return $data;
  $w = imagesx($im); $h = imagesy($im);
  $max = 1800;
  if ($w > $max) {
    $nh = (int) round($h * $max / $w);
    $dst = imagecreatetruecolor($max, $nh);
    if ($ext !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $max, $nh, $w, $h);
    imagedestroy($im);
    $im = $dst;
  } elseif ($ext === 'png' || strlen($data) < 400 * 1024) {
    imagedestroy($im);
    return $data;
  }
  ob_start();
  if ($ext === 'jpg') { imageinterlace($im, true); imagejpeg($im, null, 82); }
  elseif ($ext === 'webp' && function_exists('imagewebp')) imagewebp($im, null, 82);
  else imagepng($im, null, 7);
  $out = ob_get_clean();
  imagedestroy($im);
  return $out !== '' && ($w > $max || strlen($out) < strlen($data)) ? $out : $data;
}

/* ================= actions (POST) ================= */
if ($POST && $s === 'posts') {
  $id = (int) ($_POST['id'] ?? 0);
  $row = $id ? db_one('SELECT * FROM posts WHERE id = ?', [$id]) : null;
  if ($id && (!$row || !can_edit($row))) { flash('err', 'لا تملك صلاحية تعديل هذا المقال.'); go('?s=posts'); }

  if ($a === 'delete') {
    db_run('DELETE FROM posts WHERE id = ?', [$id]);
    flash('ok', 'تم حذف المقال.');
    go('?s=posts');
  }
  $title = cut(p('title'), 250);
  $content = sanitize_html($_POST['content'] ?? '');
  $status = p('status') === 'published' ? 'published' : 'draft';
  $cover = p('cover');
  $city = p('city');
  $_SESSION['form'] = $_POST;
  $back = '?s=posts&a=edit' . ($id ? '&id=' . $id : '');
  if ($title === '') { flash('err', 'اكتب عنوان المقال.'); go($back); }
  if ($status === 'published' && mb_strlen(plain_text($content)) < 50) { flash('err', 'محتوى المقال قصير جداً للنشر.'); go($back); }
  if (!img_path_ok($cover)) $cover = '';
  if (!isset(CITIES[$city])) $city = '';
  $pub = dt_in(p('published_at')) ?? ($row['published_at'] ?? null);
  if ($status === 'published' && !$pub) $pub = now();
  $tags = implode(', ', array_slice(array_filter(array_map('trim', preg_split('/[,،]/u', p('tags')))), 0, 10));
  $f = [
    'slug' => unique_slug('posts', slugify(p('slug') ?: $title), $id), 'title' => $title, 'excerpt' => cut(p('excerpt'), 590), 'content' => $content,
    'cover' => $cover, 'cover_alt' => cut(p('cover_alt'), 250), 'category' => cut(p('category'), 90), 'tags' => $tags, 'city' => $city,
    'meta_title' => cut(p('meta_title'), 250), 'meta_desc' => cut(p('meta_desc'), 390), 'status' => $status, 'published_at' => $pub, 'updated_at' => now(),
  ];
  if (!$id) $f += ['author_id' => $me['id'], 'created_at' => now()];
  $id = save_row('posts', $id, $f);
  unset($_SESSION['form']);
  flash('ok', $status === 'published' ? 'تم نشر المقال ✓' : 'تم حفظ المسودة.');
  go('?s=posts&a=edit&id=' . $id);
}

if ($POST && $s === 'ads') {
  $id = (int) ($_POST['id'] ?? 0);
  $row = $id ? db_one('SELECT * FROM ads WHERE id = ?', [$id]) : null;
  if ($id && (!$row || !can_edit($row))) { flash('err', 'لا تملك صلاحية تعديل هذا الإعلان.'); go('?s=ads'); }

  if ($a === 'delete') {
    db_run('DELETE FROM ads WHERE id = ?', [$id]);
    flash('ok', 'تم حذف الإعلان.');
    go('?s=ads');
  }
  $title = cut(p('title'), 250);
  $status = p('status') === 'published' ? 'published' : 'draft';
  $_SESSION['form'] = $_POST;
  $back = '?s=ads&a=edit' . ($id ? '&id=' . $id : '');
  if ($title === '') { flash('err', 'اكتب عنوان الإعلان.'); go($back); }
  if (p('description') === '') { flash('err', 'اكتب وصف الإعلان.'); go($back); }
  $images = [];
  foreach ((array) ($_POST['images'] ?? []) as $im) { $im = trim((string) $im); if ($im !== '' && img_path_ok($im)) $images[] = $im; }
  $price = str_replace([',', '٬', ' '], '', strtr(p('price'), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']));
  $exp = p('expires_at');
  $pub = dt_in(p('published_at')) ?? ($row['published_at'] ?? null);
  if ($status === 'published' && !$pub) $pub = now();
  $f = [
    'slug' => unique_slug('ads', slugify(p('slug') ?: $title), $id), 'title' => $title, 'description' => cut(p('description'), 8000),
    'deal' => isset(AD_DEALS[p('deal')]) ? p('deal') : 'rent', 'category' => in_array(p('category'), AD_CATEGORIES, true) ? p('category') : AD_CATEGORIES[0],
    'city' => isset(CITIES[p('city')]) ? p('city') : '', 'price' => is_numeric($price) && (float) $price > 0 ? round((float) $price, 2) : null,
    'price_unit' => isset(PRICE_UNITS[p('price_unit')]) ? p('price_unit') : 'day', 'item_condition' => isset(AD_CONDITIONS[p('item_condition')]) ? p('item_condition') : '',
    'images' => json_encode(array_slice(array_values(array_unique($images)), 0, 10), JSON_UNESCAPED_SLASHES),
    'phone' => substr(preg_replace('/[^0-9+]/', '', p('phone')), 0, 20), 'meta_desc' => cut(p('meta_desc'), 390),
    'status' => $status, 'published_at' => $pub,
    'expires_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp . ' 23:59:59' : null, 'updated_at' => now(),
  ];
  if (!$id) $f += ['author_id' => $me['id'], 'created_at' => now()];
  $id = save_row('ads', $id, $f);
  unset($_SESSION['form']);
  flash('ok', $status === 'published' ? 'تم نشر الإعلان ✓' : 'تم حفظ الإعلان كمسودة.');
  go('?s=ads&a=edit&id=' . $id);
}

if ($POST && $s === 'faq') {
  $id = (int) ($_POST['id'] ?? 0);
  if ($a === 'delete') { db_run('DELETE FROM faqs WHERE id = ?', [$id]); flash('ok', 'تم حذف السؤال.'); go('?s=faq'); }
  $q = cut(p('question'), 390); $ans = p('answer');
  if ($q === '' || $ans === '') { $_SESSION['form'] = $_POST; flash('err', 'اكتب السؤال والإجابة.'); go('?s=faq&a=edit' . ($id ? '&id=' . $id : '')); }
  $f = ['question' => $q, 'answer' => cut($ans, 5000), 'category' => cut(p('category'), 90), 'sort_order' => (int) p('sort_order', '0'),
        'status' => p('status') === 'draft' ? 'draft' : 'published', 'updated_at' => now()];
  if (!$id) $f['created_at'] = now();
  save_row('faqs', $id, $f);
  flash('ok', 'تم حفظ السؤال ✓');
  go('?s=faq');
}

if ($POST && $s === 'users') {
  $id = (int) ($_POST['id'] ?? 0);
  if ($a === 'delete') {
    if ($id === $me['id']) { flash('err', 'لا يمكنك حذف حسابك.'); go('?s=users'); }
    db_run('DELETE FROM users WHERE id = ?', [$id]);
    flash('ok', 'تم حذف الحساب. مقالاته وإعلاناته باقية وتظهر باسم الشركة.');
    go('?s=users');
  }
  $username = mb_strtolower(p('username'));
  $name = cut(p('name'), 110);
  $pass = (string) ($_POST['password'] ?? '');
  $role = p('role') === 'admin' ? 'admin' : 'employee';
  $active = isset($_POST['active']) ? 1 : 0;
  $_SESSION['form'] = $_POST; unset($_SESSION['form']['password']);
  $back = '?s=users&a=edit' . ($id ? '&id=' . $id : '');
  if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username) || $username === 'admin') { flash('err', 'اسم المستخدم: ٣–٣٠ حرفاً إنجليزياً صغيراً أو أرقام (بدون مسافات)، وغير «admin».'); go($back); }
  if ($name === '') { flash('err', 'اكتب اسم الموظف.'); go($back); }
  if (db_val('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$username, $id])) { flash('err', 'اسم المستخدم مستخدم من قبل.'); go($back); }
  if ((!$id || $pass !== '') && mb_strlen($pass) < 8) { flash('err', 'كلمة المرور ٨ أحرف على الأقل.'); go($back); }
  if ($id && $id === $me['id']) { $role = 'admin'; $active = 1; }   // don't lock yourself out
  $f = ['username' => $username, 'name' => $name, 'bio' => cut(p('bio'), 490), 'role' => $role, 'active' => $active];
  if ($pass !== '') $f['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
  if (!$id) $f['created_at'] = now();
  save_row('users', $id, $f);
  unset($_SESSION['form']);
  flash('ok', 'تم حفظ حساب ' . $name . ' ✓');
  go('?s=users');
}

if ($POST && $s === 'account') {
  if (!$me['id']) go('?s=account');
  $row = db_one('SELECT * FROM users WHERE id = ?', [$me['id']]);
  $new = (string) ($_POST['new'] ?? '');
  db_run('UPDATE users SET bio = ? WHERE id = ?', [cut(p('bio'), 490), $me['id']]);
  if ($new === '') flash('ok', 'تم حفظ النبذة ✓');
  elseif (!password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) flash('err', 'كلمة المرور الحالية غير صحيحة.');
  elseif (mb_strlen($new) < 8) flash('err', 'كلمة المرور الجديدة ٨ أحرف على الأقل.');
  elseif ($new !== (string) ($_POST['new2'] ?? '')) flash('err', 'تأكيد كلمة المرور غير مطابق.');
  else {
    db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
    flash('ok', 'تم تغيير كلمة المرور ✓');
  }
  go('?s=account');
}

/* ================= pages ================= */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$old = $_SESSION['form'] ?? null; unset($_SESSION['form']);
function v($row, $k, $d = '') { global $old; if (is_array($old) && array_key_exists($k, $old)) return $old[$k]; return $row[$k] ?? $d; }

function start($title, $section) {
  global $me, $isAdmin, $flash, $CSRF;
  $nav = ['dash' => ['🏠', 'الرئيسية'], 'posts' => ['📝', 'المدونة'], 'ads' => ['📢', 'الإعلانات']];
  if ($isAdmin) $nav += ['faq' => ['❓', 'الأسئلة الشائعة'], 'users' => ['👥', 'الموظفون']];
  $nav['account'] = ['🔑', 'حسابي'];
  ?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="csrf" content="<?= e($CSRF) ?>">
<title><?= e($title) ?> — لوحة المحتوى</title>
<link rel="stylesheet" href="assets/cms.css?v=<?= ASSET_VER_CMS ?>">
</head><body>
<aside class="side">
  <div class="logo"><i></i> لوحة المحتوى</div>
  <nav>
<?php foreach ($nav as $k => $n): ?>    <a href="?s=<?= $k ?>"<?= $k === $section ? ' class="on"' : '' ?>><span><?= $n[0] ?></span> <?= e($n[1]) ?></a>
<?php endforeach; if ($isAdmin): ?>    <a href="index.php"><span>🎨</span> تصميم الصفحة الرئيسية</a>
<?php endif; ?>
  </nav>
  <div class="who">
    <strong><?= e($me['name']) ?></strong>
    <small><?= $isAdmin ? 'مدير' : 'موظف' ?></small>
    <a href="/" target="_blank">عرض الموقع ↗</a>
    <a href="index.php?logout=1">تسجيل الخروج</a>
  </div>
</aside>
<main class="main">
<?php if ($flash): ?>  <div class="flash flash--<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif;
}
function finish() {
  ?>
</main>
<script src="assets/cms.js?v=<?= ASSET_VER_CMS ?>"></script>
</body></html>
<?php
  exit;
}
function csrf_field() { global $CSRF; return '<input type="hidden" name="csrf" value="' . e($CSRF) . '">'; }
function delete_btn($s, $id, $what) {
  return '<form method="post" action="?s=' . $s . '&amp;a=delete" class="inline" data-confirm="حذف ' . e($what) . ' نهائياً؟">' . csrf_field() .
         '<input type="hidden" name="id" value="' . (int) $id . '"><button class="btn btn--sm btn--danger">حذف</button></form>';
}
function seo_panel($base) {
  ?>
      <section class="panel">
        <h3>محركات البحث (SEO)</h3>
        <?php if ($base === '/blog/'): ?><label class="f"><span>عنوان جوجل (اختياري)</span><input name="meta_title" id="fMetaTitle" maxlength="250" value="<?= e(v($GLOBALS['row'], 'meta_title')) ?>" data-count="60"></label><?php endif; ?>
        <label class="f"><span>وصف جوجل (اختياري — يُكتب تلقائياً إن تركته فارغاً)</span><textarea name="meta_desc" id="fMetaDesc" rows="3" maxlength="390" data-count="160"><?= e(v($GLOBALS['row'], 'meta_desc')) ?></textarea></label>
        <p class="muted small">معاينة نتيجة جوجل:</p>
        <div class="serp" id="serp" data-base="<?= e(base_url() . $base) ?>"><cite></cite><b></b><p></p></div>
        <ul class="checks" id="checks"></ul>
      </section>
<?php
}

/* ---------- dashboard ---------- */
if ($s === 'dash') {
  $now = now();
  $mine = $isAdmin ? '' : ' AND author_id = ' . (int) $me['id'];
  $c = [
    'posts' => db_val('SELECT COUNT(*) FROM posts WHERE ' . POST_LIVE . $mine, [$now]),
    'drafts' => db_val("SELECT COUNT(*) FROM posts WHERE status = 'draft'" . $mine),
    'ads' => db_val('SELECT COUNT(*) FROM ads WHERE ' . AD_LIVE . $mine, [$now, $now]),
    'expired' => db_val("SELECT COUNT(*) FROM ads WHERE status = 'published' AND expires_at IS NOT NULL AND expires_at <= ?" . $mine, [$now]),
    'faqs' => db_val("SELECT COUNT(*) FROM faqs WHERE status = 'published'"),
  ];
  start('الرئيسية', 'dash');
  ?>
  <h1>أهلاً <?= e($me['name']) ?> 👋</h1>
  <div class="quick">
    <a class="btn btn--primary btn--lg" href="?s=posts&amp;a=edit">📝 مقال جديد</a>
    <a class="btn btn--primary btn--lg" href="?s=ads&amp;a=edit">📢 إعلان جديد</a>
<?php if ($isAdmin): ?>    <a class="btn btn--lg" href="?s=faq&amp;a=edit">❓ سؤال جديد</a>
    <a class="btn btn--lg" href="?s=users&amp;a=edit">👤 إضافة موظف</a>
<?php endif; ?>
  </div>
  <div class="stats">
    <a href="?s=posts" class="stat"><b><?= (int) $c['posts'] ?></b><span><?= $isAdmin ? 'مقال منشور' : 'مقالاتك المنشورة' ?></span></a>
    <a href="?s=posts" class="stat"><b><?= (int) $c['drafts'] ?></b><span>مسودات</span></a>
    <a href="?s=ads" class="stat"><b><?= (int) $c['ads'] ?></b><span>إعلان نشط</span></a>
    <a href="?s=ads" class="stat<?= $c['expired'] ? ' stat--warn' : '' ?>"><b><?= (int) $c['expired'] ?></b><span>إعلان منتهي</span></a>
<?php if ($isAdmin): ?>    <a href="?s=faq" class="stat"><b><?= (int) $c['faqs'] ?></b><span>سؤال شائع</span></a>
<?php endif; ?>
  </div>
  <section class="panel tips">
    <h2>نصائح لظهور أفضل في جوجل ومحركات الذكاء الاصطناعي</h2>
    <ul>
      <li>اكتب عنواناً يحتوي ما يبحث عنه العميل فعلاً، مثل: <em>«تأجير ونش ٥٠ طن في جدة»</em>.</li>
      <li>اختر <b>المدينة</b> في كل مقال وإعلان — هذا يربط الصفحة بالبحث المحلي (GEO).</li>
      <li>في المقالات: ٣٠٠ كلمة على الأقل، وعناوين فرعية (H2) على شكل أسئلة، وإجابة مباشرة في أول فقرة.</li>
      <li>أضف صورة حقيقية مع وصف (Alt) لكل مقال وإعلان، واربط المقال بصفحة إعلان أو بالرئيسية.</li>
      <li>حدّث الإعلانات المنتهية أو احذفها — الصفحات القديمة تضعف ثقة جوجل.</li>
    </ul>
    <p class="muted">ملفات تُحدَّث تلقائياً: <a href="/sitemap-content.xml" target="_blank">خريطة الموقع</a> · <a href="/blog/feed.xml" target="_blank">RSS</a> · <a href="/llms.txt" target="_blank">llms.txt</a></p>
  </section>
<?php
  finish();
}

/* ---------- posts ---------- */
if ($s === 'posts' && $a === 'list') {
  $q = trim((string) ($_GET['q'] ?? ''));
  $where = '1=1'; $args = [];
  if (!$isAdmin) { $where .= ' AND author_id = ?'; $args[] = $me['id']; }
  if ($q !== '') { $where .= ' AND title LIKE ?'; $args[] = '%' . $q . '%'; }
  $rows = db_all("SELECT id, slug, title, status, category, city, author_id, published_at, updated_at FROM posts WHERE $where ORDER BY updated_at DESC LIMIT 200", $args);
  start('المدونة', 'posts');
  ?>
  <div class="head"><h1>المدونة</h1><a class="btn btn--primary" href="?s=posts&amp;a=edit">+ مقال جديد</a></div>
  <form class="search" method="get"><input type="hidden" name="s" value="posts"><input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث في العناوين…"></form>
  <?php if (!$rows): ?><p class="empty">لا توجد مقالات بعد. ابدأ بأول مقال!</p><?php else: ?>
  <table class="table">
    <thead><tr><th>العنوان</th><th>الحالة</th><th>التصنيف</th><?= $isAdmin ? '<th>الكاتب</th>' : '' ?><th>آخر تعديل</th><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="?s=posts&amp;a=edit&amp;id=<?= $r['id'] ?>"><strong><?= e($r['title']) ?></strong></a><?= $r['city'] ? ' <small class="muted">📍' . e($r['city']) . '</small>' : '' ?></td>
        <td><?= status_badge($r) ?></td>
        <td><?= e($r['category']) ?></td>
        <?= $isAdmin ? '<td>' . e(author_name($r['author_id'])) . '</td>' : '' ?>
        <td class="muted"><?= e(substr($r['updated_at'], 0, 16)) ?></td>
        <td class="actions">
          <?php if ($r['status'] === 'published'): ?><a class="btn btn--sm" href="<?= e(post_path($r['slug'])) ?>" target="_blank">عرض</a><?php endif; ?>
          <a class="btn btn--sm" href="?s=posts&amp;a=edit&amp;id=<?= $r['id'] ?>">تعديل</a>
          <?= delete_btn('posts', $r['id'], 'المقال') ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <?php endif;
  finish();
}

if ($s === 'posts' && $a === 'edit') {
  $id = (int) ($_GET['id'] ?? 0);
  $row = $id ? db_one('SELECT * FROM posts WHERE id = ?', [$id]) : [];
  if ($id && (!$row || !can_edit($row))) { flash('err', 'المقال غير موجود أو لا تملك صلاحية تعديله.'); go('?s=posts'); }
  $cats = array_unique(array_merge(array_column(db_all("SELECT DISTINCT category FROM posts WHERE category <> ''"), 'category'),
    ['دليل التأجير', 'الرافعات والونشات', 'المان لفت', 'الحفارات والشيولات', 'السلامة في المواقع', 'أخبار الشركة']));
  $content = is_array($old) && isset($old['content']) ? sanitize_html($old['content']) : ($row['content'] ?? '');
  start($id ? 'تعديل مقال' : 'مقال جديد', 'posts');
  ?>
  <div class="head">
    <h1><?= $id ? 'تعديل مقال' : 'مقال جديد' ?></h1>
    <div><?php if ($id): ?><?= status_badge($row) ?> <?php if ($row['status'] === 'published'): ?><a class="btn" href="<?= e(post_path($row['slug'])) ?>" target="_blank">عرض المقال ↗</a><?php endif; endif; ?>
    <a class="btn" href="?s=posts">← كل المقالات</a></div>
  </div>
  <form method="post" action="?s=posts&amp;a=save" class="editor" id="postForm" data-kind="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="col-main">
      <label class="f"><span>العنوان <em>*</em></span><input name="title" id="fTitle" required maxlength="250" value="<?= e(v($row, 'title')) ?>" placeholder="مثال: كم سعر تأجير ونش ٥٠ طن في الرياض؟" data-count="65"></label>
      <label class="f"><span>رابط الصفحة</span><span class="slug"><span dir="ltr"><?= e(base_url()) ?>/blog/</span><input name="slug" id="fSlug" value="<?= e(v($row, 'slug')) ?>" placeholder="يُنشأ تلقائياً من العنوان" dir="auto"<?= $id ? '' : ' data-auto="1"' ?>></span></label>
      <label class="f"><span>مقدمة قصيرة (تظهر تحت العنوان وفي بطاقة المقال)</span><textarea name="excerpt" id="fExcerpt" rows="2" maxlength="590" data-count="200"><?= e(v($row, 'excerpt')) ?></textarea></label>
      <div class="f"><span>المحتوى <em>*</em></span>
        <div class="rte">
          <div class="rte__bar">
            <button type="button" data-cmd="formatBlock" data-val="h2" title="عنوان رئيسي">H2</button>
            <button type="button" data-cmd="formatBlock" data-val="h3" title="عنوان فرعي">H3</button>
            <button type="button" data-cmd="formatBlock" data-val="p" title="فقرة">¶</button>
            <i></i>
            <button type="button" data-cmd="bold" title="عريض"><b>B</b></button>
            <button type="button" data-cmd="italic" title="مائل"><em>I</em></button>
            <button type="button" data-cmd="insertUnorderedList" title="قائمة نقطية">• قائمة</button>
            <button type="button" data-cmd="insertOrderedList" title="قائمة مرقمة">١. قائمة</button>
            <button type="button" data-cmd="formatBlock" data-val="blockquote" title="اقتباس">❝</button>
            <i></i>
            <button type="button" data-cmd="link" title="رابط">🔗 رابط</button>
            <button type="button" data-cmd="unlink" title="إزالة الرابط">إزالة الرابط</button>
            <button type="button" data-cmd="image" title="صورة">🖼 صورة</button>
            <button type="button" data-cmd="removeFormat" title="إزالة التنسيق">⨯ تنسيق</button>
            <span class="sp"></span>
            <button type="button" data-cmd="source" title="HTML">&lt;/&gt;</button>
          </div>
          <div class="rte__area prose" contenteditable="true" data-placeholder="ابدأ الكتابة… استخدم H2 للعناوين الرئيسية."><?= $content ?></div>
          <textarea class="rte__src" hidden dir="ltr"></textarea>
          <textarea name="content" hidden></textarea>
        </div>
      </div>
    </div>
    <div class="col-side">
      <section class="panel">
        <h3>النشر</h3>
        <label class="f"><span>تاريخ النشر (فارغ = الآن، أو وقت لاحق للجدولة)</span><input type="datetime-local" name="published_at" value="<?= e(dt_out(v($row, 'published_at'))) ?>"></label>
        <div class="row2">
          <button class="btn btn--block" name="status" value="draft">💾 مسودة</button>
          <button class="btn btn--primary btn--block" name="status" value="published"><?= ($row['status'] ?? '') === 'published' ? '✓ تحديث' : '🚀 نشر' ?></button>
        </div>
      </section>
      <section class="panel">
        <h3>التصنيف والمكان</h3>
        <label class="f"><span>التصنيف</span><input name="category" list="cats" value="<?= e(v($row, 'category')) ?>"><datalist id="cats"><?php foreach ($cats as $c) echo '<option value="' . e($c) . '">'; ?></datalist></label>
        <label class="f"><span>المدينة المستهدفة (GEO)</span><select name="city" id="fCity"><option value="">— كل المملكة —</option><?php foreach (CITIES as $c => $_) echo '<option' . sel($c, v($row, 'city')) . '>' . e($c) . '</option>'; ?></select></label>
        <label class="f"><span>كلمات مفتاحية (افصل بفاصلة)</span><input name="tags" value="<?= e(v($row, 'tags')) ?>" placeholder="تأجير ونش، كرين الرياض"></label>
      </section>
      <section class="panel">
        <h3>صورة الغلاف</h3>
        <div class="imgpick">
          <input type="hidden" name="cover" id="fCover" value="<?= e(v($row, 'cover')) ?>">
          <div class="imgpick__preview"></div>
          <div class="row2"><button type="button" class="btn btn--sm" data-pick="upload">⬆ رفع صورة</button><button type="button" class="btn btn--sm" data-pick="library">🗂 من المكتبة</button></div>
        </div>
        <label class="f"><span>وصف الصورة (Alt) — مهم لبحث الصور</span><input name="cover_alt" id="fAlt" value="<?= e(v($row, 'cover_alt')) ?>" placeholder="مثال: ونش ساني ٥٠ طن يرفع خرسانة في الرياض"></label>
      </section>
      <?php seo_panel('/blog/'); ?>
    </div>
  </form>
  <?php
  finish();
}

/* ---------- ads ---------- */
if ($s === 'ads' && $a === 'list') {
  $q = trim((string) ($_GET['q'] ?? ''));
  $where = '1=1'; $args = [];
  if (!$isAdmin) { $where .= ' AND author_id = ?'; $args[] = $me['id']; }
  if ($q !== '') { $where .= ' AND title LIKE ?'; $args[] = '%' . $q . '%'; }
  $rows = db_all("SELECT * FROM ads WHERE $where ORDER BY updated_at DESC LIMIT 200", $args);
  start('الإعلانات', 'ads');
  ?>
  <div class="head"><h1>إعلانات المعدات</h1><a class="btn btn--primary" href="?s=ads&amp;a=edit">+ إعلان جديد</a></div>
  <form class="search" method="get"><input type="hidden" name="s" value="ads"><input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث في الإعلانات…"></form>
  <?php if (!$rows): ?><p class="empty">لا توجد إعلانات بعد. أضف أول إعلان لمعدة متاحة!</p><?php else: ?>
  <table class="table">
    <thead><tr><th></th><th>الإعلان</th><th>الحالة</th><th>المدينة</th><th>السعر</th><?= $isAdmin ? '<th>أضافه</th>' : '' ?><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): $imgs = ad_images($r); ?>
      <tr>
        <td class="thumb"><?php if ($imgs): ?><img src="/<?= e($imgs[0]) ?>" alt="" loading="lazy"><?php endif; ?></td>
        <td><a href="?s=ads&amp;a=edit&amp;id=<?= $r['id'] ?>"><strong><?= e($r['title']) ?></strong></a><br><small class="muted"><?= e(AD_DEALS[$r['deal']] ?? '') ?> · <?= e($r['category']) ?></small></td>
        <td><?= status_badge($r, true) ?><?= $r['expires_at'] && $r['expires_at'] > now() ? '<br><small class="muted">حتى ' . e(substr($r['expires_at'], 0, 10)) . '</small>' : '' ?></td>
        <td><?= e($r['city']) ?></td>
        <td><?= e(ad_price_text($r)) ?></td>
        <?= $isAdmin ? '<td>' . e(author_name($r['author_id'])) . '</td>' : '' ?>
        <td class="actions">
          <?php if ($r['status'] === 'published'): ?><a class="btn btn--sm" href="<?= e(ad_path($r['slug'])) ?>" target="_blank">عرض</a><?php endif; ?>
          <a class="btn btn--sm" href="?s=ads&amp;a=edit&amp;id=<?= $r['id'] ?>">تعديل</a>
          <?= delete_btn('ads', $r['id'], 'الإعلان') ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <?php endif;
  finish();
}

if ($s === 'ads' && $a === 'edit') {
  $id = (int) ($_GET['id'] ?? 0);
  $row = $id ? db_one('SELECT * FROM ads WHERE id = ?', [$id]) : [];
  if ($id && (!$row || !can_edit($row))) { flash('err', 'الإعلان غير موجود أو لا تملك صلاحية تعديله.'); go('?s=ads'); }
  $images = is_array($old) && isset($old['images']) ? array_values(array_filter((array) $old['images'], 'is_string')) : ($row ? ad_images($row) : []);
  $exp = v($row, 'expires_at');
  $price = v($row, 'price');
  if ($price !== null && $price !== '' && strpos((string) $price, '.') !== false) $price = rtrim(rtrim((string) $price, '0'), '.');
  start($id ? 'تعديل إعلان' : 'إعلان جديد', 'ads');
  ?>
  <div class="head">
    <h1><?= $id ? 'تعديل إعلان' : 'إعلان جديد' ?></h1>
    <div><?php if ($id): ?><?= status_badge($row, true) ?> <?php if ($row['status'] === 'published'): ?><a class="btn" href="<?= e(ad_path($row['slug'])) ?>" target="_blank">عرض الإعلان ↗</a><?php endif; endif; ?>
    <a class="btn" href="?s=ads">← كل الإعلانات</a></div>
  </div>
  <form method="post" action="?s=ads&amp;a=save" class="editor" id="adForm" data-kind="ad">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="col-main">
      <label class="f"><span>عنوان الإعلان <em>*</em></span><input name="title" id="fTitle" required maxlength="250" value="<?= e(v($row, 'title')) ?>" placeholder="مثال: ونش ساني ٥٠ طن مع مشغل" data-count="65"></label>
      <label class="f"><span>رابط الصفحة</span><span class="slug"><span dir="ltr"><?= e(base_url()) ?>/ads/</span><input name="slug" id="fSlug" value="<?= e(v($row, 'slug')) ?>" placeholder="يُنشأ تلقائياً من العنوان" dir="auto"<?= $id ? '' : ' data-auto="1"' ?>></span></label>
      <div class="grid3">
        <label class="f"><span>نوع الإعلان</span><select name="deal" id="fDeal"><?php foreach (AD_DEALS as $k => $d) echo '<option value="' . $k . '"' . sel($k, v($row, 'deal', 'rent')) . '>' . e($d) . '</option>'; ?></select></label>
        <label class="f"><span>نوع المعدة</span><select name="category"><?php foreach (AD_CATEGORIES as $c) echo '<option' . sel($c, v($row, 'category')) . '>' . e($c) . '</option>'; ?></select></label>
        <label class="f"><span>المدينة (GEO) <em>*</em></span><select name="city" id="fCity" required><option value="">اختر المدينة</option><?php foreach (CITIES as $c => $_) echo '<option' . sel($c, v($row, 'city')) . '>' . e($c) . '</option>'; ?></select></label>
      </div>
      <div class="grid3">
        <label class="f"><span>السعر بالريال (فارغ = عند التواصل)</span><input name="price" inputmode="decimal" value="<?= e((string) $price) ?>"></label>
        <label class="f"><span>السعر لكل</span><select name="price_unit"><?php foreach (PRICE_UNITS as $k => $u) echo '<option value="' . $k . '"' . sel($k, v($row, 'price_unit', 'day')) . '>' . e($u) . '</option>'; ?></select></label>
        <label class="f"><span>حالة المعدة</span><select name="item_condition"><?php foreach (AD_CONDITIONS as $k => $c) echo '<option value="' . $k . '"' . sel($k, v($row, 'item_condition')) . '>' . e($c) . '</option>'; ?></select></label>
      </div>
      <label class="f"><span>وصف الإعلان <em>*</em> — المواصفات، الحمولة، الارتفاع، الموديل، مع مشغل أو بدون…</span><textarea name="description" id="fDesc" rows="10" required><?= e(v($row, 'description')) ?></textarea></label>
      <div class="f"><span>صور الإعلان (الأولى هي الصورة الرئيسية — حتى ١٠ صور)</span>
        <div class="gallery">
<?php foreach ($images as $im): ?>          <div class="gallery__item"><img src="/<?= e($im) ?>" alt=""><input type="hidden" name="images[]" value="<?= e($im) ?>"><button type="button" data-act="first" title="اجعلها الرئيسية">★</button><button type="button" data-act="remove" title="إزالة">×</button></div>
<?php endforeach; ?>
          <div class="gallery__add"><button type="button" class="btn btn--sm" data-pick="upload" data-multiple="1">⬆ رفع صور</button><button type="button" class="btn btn--sm" data-pick="library">🗂 من المكتبة</button></div>
        </div>
      </div>
    </div>
    <div class="col-side">
      <section class="panel">
        <h3>النشر</h3>
        <label class="f"><span>تاريخ النشر (فارغ = الآن)</span><input type="datetime-local" name="published_at" value="<?= e(dt_out(v($row, 'published_at'))) ?>"></label>
        <label class="f"><span>ينتهي الإعلان في (اختياري)</span><input type="date" name="expires_at" value="<?= e($exp ? substr($exp, 0, 10) : '') ?>"></label>
        <div class="row2">
          <button class="btn btn--block" name="status" value="draft">💾 مسودة</button>
          <button class="btn btn--primary btn--block" name="status" value="published"><?= ($row['status'] ?? '') === 'published' ? '✓ تحديث' : '🚀 نشر' ?></button>
        </div>
      </section>
      <section class="panel">
        <h3>التواصل</h3>
        <label class="f"><span>جوال/واتساب خاص بالإعلان (فارغ = رقم الشركة)</span><input name="phone" dir="ltr" value="<?= e(v($row, 'phone')) ?>" placeholder="<?= SITE_PHONE ?>"></label>
      </section>
      <?php seo_panel('/ads/'); ?>
    </div>
  </form>
  <?php
  finish();
}

/* ---------- FAQ (admin) ---------- */
if ($s === 'faq' && $a === 'list') {
  $rows = db_all('SELECT * FROM faqs ORDER BY sort_order, id');
  start('الأسئلة الشائعة', 'faq');
  ?>
  <div class="head"><h1>الأسئلة الشائعة</h1><div><a class="btn" href="/faq/" target="_blank">عرض الصفحة ↗</a> <a class="btn btn--primary" href="?s=faq&amp;a=edit">+ سؤال جديد</a></div></div>
  <p class="muted">تظهر في صفحة <b>/faq/</b> مع بيانات FAQPage لجوجل. الترتيب: الرقم الأصغر أولاً. (أسئلة الصفحة الرئيسية تُعدَّل من «تصميم الصفحة الرئيسية».)</p>
  <?php if (!$rows): ?><p class="empty">لا توجد أسئلة.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>#</th><th>السؤال</th><th>القسم</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td class="muted"><?= (int) $r['sort_order'] ?></td>
        <td><a href="?s=faq&amp;a=edit&amp;id=<?= $r['id'] ?>"><strong><?= e($r['question']) ?></strong></a><br><small class="muted"><?= e(cut($r['answer'], 120)) ?></small></td>
        <td><?= e($r['category']) ?></td>
        <td><?= $r['status'] === 'published' ? '<span class="badge badge--green">منشور</span>' : '<span class="badge">مخفي</span>' ?></td>
        <td class="actions"><a class="btn btn--sm" href="?s=faq&amp;a=edit&amp;id=<?= $r['id'] ?>">تعديل</a> <?= delete_btn('faq', $r['id'], 'السؤال') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <?php endif;
  finish();
}

if ($s === 'faq' && $a === 'edit') {
  $id = (int) ($_GET['id'] ?? 0);
  $row = $id ? db_one('SELECT * FROM faqs WHERE id = ?', [$id]) : ['sort_order' => (int) db_val('SELECT COALESCE(MAX(sort_order), 0) FROM faqs') + 10, 'status' => 'published'];
  if (!$row) go('?s=faq');
  $cats = array_unique(array_merge(array_column(db_all("SELECT DISTINCT category FROM faqs WHERE category <> ''"), 'category'), ['الأسعار والعقود', 'المعدات', 'مناطق الخدمة', 'الصيانة والدعم']));
  start($id ? 'تعديل سؤال' : 'سؤال جديد', 'faq');
  ?>
  <div class="head"><h1><?= $id ? 'تعديل سؤال' : 'سؤال جديد' ?></h1><a class="btn" href="?s=faq">← كل الأسئلة</a></div>
  <form method="post" action="?s=faq&amp;a=save" class="panel narrow">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label class="f"><span>السؤال <em>*</em> — كما يكتبه العميل في جوجل</span><input name="question" required maxlength="390" value="<?= e(v($row, 'question')) ?>" placeholder="مثال: كم سعر تأجير مان لفت في الرياض؟"></label>
    <label class="f"><span>الإجابة <em>*</em> — ابدأ بالجواب المباشر في أول جملة</span><textarea name="answer" rows="7" required><?= e(v($row, 'answer')) ?></textarea></label>
    <div class="grid3">
      <label class="f"><span>القسم</span><input name="category" list="fcats" value="<?= e(v($row, 'category')) ?>"><datalist id="fcats"><?php foreach ($cats as $c) echo '<option value="' . e($c) . '">'; ?></datalist></label>
      <label class="f"><span>الترتيب</span><input type="number" name="sort_order" value="<?= (int) v($row, 'sort_order', 0) ?>"></label>
      <label class="f"><span>الحالة</span><select name="status"><option value="published"<?= sel('published', v($row, 'status')) ?>>منشور</option><option value="draft"<?= sel('draft', v($row, 'status')) ?>>مخفي</option></select></label>
    </div>
    <button class="btn btn--primary btn--lg">💾 حفظ</button>
  </form>
  <?php
  finish();
}

/* ---------- employees (admin) ---------- */
if ($s === 'users' && $a === 'list') {
  $rows = db_all('SELECT u.*, (SELECT COUNT(*) FROM posts WHERE author_id = u.id) AS np, (SELECT COUNT(*) FROM ads WHERE author_id = u.id) AS na FROM users u ORDER BY u.name');
  start('الموظفون', 'users');
  ?>
  <div class="head"><h1>الموظفون</h1><a class="btn btn--primary" href="?s=users&amp;a=edit">+ إضافة موظف</a></div>
  <p class="muted">الموظف يدخل من <b>/admin/</b> باسم المستخدم وكلمة المرور، ويستطيع إضافة وتعديل <b>مقالاته وإعلاناته فقط</b>. المدير يرى ويعدّل كل شيء.</p>
  <?php if (!$rows): ?><p class="empty">لا يوجد موظفون بعد.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>الاسم</th><th>اسم المستخدم</th><th>الصلاحية</th><th>المحتوى</th><th>آخر دخول</th><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="?s=users&amp;a=edit&amp;id=<?= $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a><?= (int) $r['active'] ? '' : ' <span class="badge badge--red">موقوف</span>' ?></td>
        <td dir="ltr" class="ltr"><?= e($r['username']) ?></td>
        <td><?= $r['role'] === 'admin' ? 'مدير' : 'موظف' ?></td>
        <td class="muted"><?= (int) $r['np'] ?> مقال · <?= (int) $r['na'] ?> إعلان</td>
        <td class="muted"><?= e($r['last_login'] ? substr($r['last_login'], 0, 16) : '—') ?></td>
        <td class="actions"><a class="btn btn--sm" href="?s=users&amp;a=edit&amp;id=<?= $r['id'] ?>">تعديل</a> <?= (int) $r['id'] !== $me['id'] ? delete_btn('users', $r['id'], 'حساب ' . $r['name']) : '' ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <?php endif;
  finish();
}

if ($s === 'users' && $a === 'edit') {
  $id = (int) ($_GET['id'] ?? 0);
  $row = $id ? db_one('SELECT * FROM users WHERE id = ?', [$id]) : ['role' => 'employee', 'active' => 1];
  if (!$row) go('?s=users');
  start($id ? 'تعديل موظف' : 'إضافة موظف', 'users');
  ?>
  <div class="head"><h1><?= $id ? 'تعديل حساب' : 'إضافة موظف' ?></h1><a class="btn" href="?s=users">← الموظفون</a></div>
  <form method="post" action="?s=users&amp;a=save" class="panel narrow" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid2">
      <label class="f"><span>الاسم الظاهر <em>*</em> (يظهر ككاتب للمقالات)</span><input name="name" required maxlength="110" value="<?= e(v($row, 'name')) ?>"></label>
      <label class="f"><span>اسم المستخدم للدخول <em>*</em> (إنجليزي صغير)</span><input name="username" required dir="ltr" pattern="[a-z0-9._\-]{3,30}" value="<?= e(v($row, 'username')) ?>" autocomplete="off"></label>
    </div>
    <label class="f"><span>نبذة قصيرة (تظهر أسفل مقالاته — تزيد ثقة جوجل بالكاتب)</span><textarea name="bio" rows="2" maxlength="490"><?= e(v($row, 'bio')) ?></textarea></label>
    <div class="grid2">
      <label class="f"><span>كلمة المرور <?= $id ? '(فارغة = بدون تغيير)' : '<em>*</em>' ?></span><input type="password" name="password" minlength="8" autocomplete="new-password"<?= $id ? '' : ' required' ?>></label>
      <label class="f"><span>الصلاحية</span><select name="role"><option value="employee"<?= sel('employee', v($row, 'role')) ?>>موظف — مقالاته وإعلاناته فقط</option><option value="admin"<?= sel('admin', v($row, 'role')) ?>>مدير — كل الصلاحيات</option></select></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1"<?= (int) v($row, 'active', 1) ? ' checked' : '' ?>> الحساب مفعّل (أزل العلامة لإيقاف دخوله)</label>
    <button class="btn btn--primary btn--lg">💾 حفظ</button>
  </form>
  <?php
  finish();
}

/* ---------- my account ---------- */
if ($s === 'account') {
  $row = $me['id'] ? db_one('SELECT * FROM users WHERE id = ?', [$me['id']]) : null;
  start('حسابي', 'account');
  ?>
  <div class="head"><h1>حسابي</h1></div>
  <?php if (!$me['id']): ?>
  <div class="panel narrow"><p>أنت داخل بحساب <b>المدير الرئيسي</b>. لتغيير كلمة المرور عدّل الملف <code>admin/config.php</code> على الاستضافة.</p></div>
  <?php else: ?>
  <form method="post" action="?s=account" class="panel narrow">
    <?= csrf_field() ?>
    <p>اسم المستخدم: <b dir="ltr"><?= e($me['username']) ?></b></p>
    <label class="f"><span>نبذة عنك (تظهر أسفل مقالاتك)</span><textarea name="bio" rows="2" maxlength="490"><?= e($row['bio'] ?? '') ?></textarea></label>
    <h3>تغيير كلمة المرور (اختياري)</h3>
    <label class="f"><span>كلمة المرور الحالية</span><input type="password" name="current" autocomplete="current-password"></label>
    <div class="grid2">
      <label class="f"><span>كلمة المرور الجديدة</span><input type="password" name="new" minlength="8" autocomplete="new-password"></label>
      <label class="f"><span>تأكيدها</span><input type="password" name="new2" minlength="8" autocomplete="new-password"></label>
    </div>
    <button class="btn btn--primary">حفظ</button>
  </form>
  <?php endif;
  finish();
}

go('?s=dash');
