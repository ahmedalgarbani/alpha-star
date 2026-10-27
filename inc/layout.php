<?php
/* Page frame for blog / ads / FAQ: <head> with SEO + GEO tags, header, footer. */

if (!defined('SITE_APP')) { http_response_code(404); exit; }

const ASSET_VER = '20261027';

function business_ref() {
  return ['@type' => 'LocalBusiness', '@id' => url('/#business'), 'name' => SITE_NAME, 'url' => url('/'), 'telephone' => SITE_PHONE,
          'image' => url('/assets/img/photo-13.jpg'),
          'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'الرياض', 'addressRegion' => 'منطقة الرياض', 'addressCountry' => 'SA']];
}
function place_ld($city) {
  if (!isset(CITIES[$city])) return null;
  $c = CITIES[$city];
  return ['@type' => 'City', 'name' => $city, 'containedInPlace' => ['@type' => 'Country', 'name' => 'المملكة العربية السعودية'],
          'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $c[1], 'longitude' => $c[2]]];
}

/**
 * $m: title, description, path (canonical, site-relative), robots, type (website|article),
 *     image, image_alt, city (geo focus), crumbs [[name, path], …], ld (extra JSON-LD nodes),
 *     article [published, modified, section, tags[]], nav (current nav key)
 */
function page_start(array $m) {
  $title = $m['title'];
  $desc = cut($m['description'] ?? '', 165);
  $canon = url($m['path']);
  $image = abs_asset($m['image'] ?? 'assets/img/photo-13.jpg');
  $robots = $m['robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1';
  $city = $m['city'] ?? '';
  $geo = CITIES[$city] ?? CITIES['الرياض'];
  $placename = ($city !== '' && isset(CITIES[$city]) ? $city : 'الرياض') . '، المملكة العربية السعودية';

  $graph = [];
  $graph[] = ['@type' => 'WebSite', '@id' => url('/#website'), 'url' => url('/'), 'name' => SITE_NAME . ' — تأجير المعدات الثقيلة', 'inLanguage' => SITE_LANG,
              'publisher' => ['@id' => url('/#business')]];
  $graph[] = business_ref();
  if (!empty($m['crumbs'])) {
    $items = [];
    foreach (array_merge([['الرئيسية', '/']], $m['crumbs']) as $i => $c) {
      $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => url($c[1])];
    }
    $graph[] = ['@type' => 'BreadcrumbList', '@id' => $canon . '#breadcrumb', 'itemListElement' => $items];
  }
  foreach ($m['ld'] ?? [] as $node) $graph[] = $node;
  $ld = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT);

  header('Content-Type: text/html; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  if (!headers_sent() && !isset($m['no_cache'])) header('Cache-Control: public, max-age=300');
  ?><!DOCTYPE html>
<html lang="ar-SA" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <meta name="robots" content="<?= e($robots) ?>">
  <link rel="canonical" href="<?= e($canon) ?>">
  <link rel="alternate" hreflang="ar-sa" href="<?= e($canon) ?>">
  <link rel="alternate" hreflang="x-default" href="<?= e($canon) ?>">
<?php if (!empty($m['prev'])): ?>  <link rel="prev" href="<?= e(url($m['prev'])) ?>">
<?php endif; if (!empty($m['next'])): ?>  <link rel="next" href="<?= e(url($m['next'])) ?>">
<?php endif; ?>
  <link rel="alternate" type="application/rss+xml" title="مدونة <?= e(SITE_NAME) ?>" href="<?= e(url('/blog/feed.xml')) ?>">
  <meta name="theme-color" content="#0B0D10">
  <meta name="geo.region" content="<?= e($geo[0]) ?>">
  <meta name="geo.placename" content="<?= e($placename) ?>">
  <meta name="geo.position" content="<?= $geo[1] ?>;<?= $geo[2] ?>">
  <meta name="ICBM" content="<?= $geo[1] ?>, <?= $geo[2] ?>">

  <meta property="og:type" content="<?= e($m['type'] ?? 'website') ?>">
  <meta property="og:locale" content="<?= SITE_LOCALE ?>">
  <meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
  <meta property="og:url" content="<?= e($canon) ?>">
  <meta property="og:title" content="<?= e($m['og_title'] ?? $title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:image" content="<?= e($image) ?>">
  <meta property="og:image:alt" content="<?= e($m['image_alt'] ?? $title) ?>">
<?php if (!empty($m['article'])): $a = $m['article']; ?>
  <meta property="article:published_time" content="<?= e(iso_date($a['published'])) ?>">
  <meta property="article:modified_time" content="<?= e(iso_date($a['modified'])) ?>">
<?php if (!empty($a['section'])): ?>  <meta property="article:section" content="<?= e($a['section']) ?>">
<?php endif; foreach ($a['tags'] ?? [] as $t): ?>  <meta property="article:tag" content="<?= e($t) ?>">
<?php endforeach; endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($m['og_title'] ?? $title) ?>">
  <meta name="twitter:description" content="<?= e($desc) ?>">
  <meta name="twitter:image" content="<?= e($image) ?>">

  <script type="application/ld+json">
<?= $ld ?>

  </script>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%230E1116'/%3E%3Cpath d='M32 8l5.5 12.5L51 22l-9.5 9 2.8 13.8L32 38l-12.3 6.8L22.5 31 13 22l13.5-1.5z' fill='%23F6B100'/%3E%3C/svg%3E">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Alexandria:wght@400;600;800;900&amp;family=Big+Shoulders+Display:wght@700;900&amp;family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
<?php if (!empty($m['preload'])): ?>  <link rel="preload" as="image" href="<?= e($m['preload']) ?>">
<?php endif; ?>
  <link rel="stylesheet" href="/css/styles.css?v=20261025b">
  <link rel="stylesheet" href="/css/pages.css?v=<?= ASSET_VER ?>">
</head>
<body class="subpage">
  <svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-wa" viewBox="0 0 24 24"><path fill="currentColor" d="M12.04 2a9.9 9.9 0 0 0-8.4 15.2L2 22l4.9-1.6A9.9 9.9 0 1 0 12.04 2Zm5.8 14.1c-.25.7-1.45 1.35-2 1.4-.55.06-1.2.08-1.93-.12a17.6 17.6 0 0 1-6.1-3.9c-1.6-1.6-2.4-3.2-2.65-3.9-.25-.7.02-1.35.3-1.75l.7-.9c.2-.25.45-.3.7-.3h.55c.2 0 .45.03.65.45l.9 2.1c.13.28.05.5-.1.7l-.45.55c-.15.18-.2.3-.08.55.35.7.9 1.45 1.5 2.05.75.75 1.45 1.2 2.1 1.5.28.13.47.08.63-.1l.6-.7c.2-.23.4-.2.63-.12l2 1c.25.12.4.2.45.3.05.13.05.6-.2 1.3Z"/></symbol>
    <symbol id="i-phone" viewBox="0 0 24 24"><path fill="currentColor" d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.2.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.25.2 2.45.6 3.6.1.3 0 .7-.2 1l-2.3 2.2Z"/></symbol>
    <symbol id="i-arrow" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m6-6-6 6 6 6"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></g></symbol>
    <symbol id="i-cal" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M8 3v4M16 3v4M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></symbol>
    <symbol id="i-star" viewBox="0 0 64 64"><path fill="currentColor" d="M32 10l5.2 11.8L50 23.3l-9 8.5 2.6 13L32 38.4l-11.6 6.4L23 31.8l-9-8.5 12.8-1.5z"/></symbol>
  </svg>

  <header class="header scrolled" id="header">
    <div class="container header__inner">
      <a href="/" class="brand" aria-label="نجوم الفاء — الرئيسية">
        <span class="brand__mark" aria-hidden="true"><svg viewBox="0 0 64 64" width="24" height="24"><use href="#i-star"/></svg></span>
        <span class="brand__text"><strong>نجوم الفاء</strong><small>لتأجير المعدات الثقيلة</small></span>
      </a>
      <nav class="nav" id="nav" aria-label="القائمة الرئيسية">
<?php
  $links = ['home' => ['/', 'الرئيسية'], 'fleet' => ['/#fleet', 'الأسطول'], 'ads' => ['/ads/', 'إعلانات المعدات'],
            'blog' => ['/blog/', 'المدونة'], 'faq' => ['/faq/', 'الأسئلة الشائعة'], 'contact' => ['/#contact', 'تواصل']];
  foreach ($links as $k => $l) {
    $cur = ($m['nav'] ?? '') === $k;
    echo '        <a href="' . e($l[0]) . '"' . ($cur ? ' class="is-current" aria-current="page"' : '') . '>' . e($l[1]) . "</a>\n";
  }
?>
        <a class="nav__phone" href="tel:<?= SITE_PHONE ?>" dir="ltr"><svg width="16" height="16"><use href="#i-phone"/></svg> <?= SITE_PHONE_TXT ?></a>
      </nav>
      <a class="btn btn--amber btn--sm header__cta" href="/#quote">اطلب عرض سعر</a>
      <button class="burger" id="burger" aria-label="فتح القائمة" aria-expanded="false" aria-controls="nav"><span></span><span></span></button>
    </div>
  </header>

  <main class="page" id="main">
<?php if (!empty($m['crumbs'])): ?>
    <nav class="container crumbs" aria-label="مسار التنقل">
      <ol>
        <li><a href="/">الرئيسية</a></li>
<?php foreach ($m['crumbs'] as $i => $c): $last = $i === count($m['crumbs']) - 1; ?>
        <li><?php if ($last): ?><span aria-current="page"><?= e($c[0]) ?></span><?php else: ?><a href="<?= e($c[1]) ?>"><?= e($c[0]) ?></a><?php endif; ?></li>
<?php endforeach; ?>
      </ol>
    </nav>
<?php endif;
}

function page_end() {
  $wa = 'https://wa.me/' . SITE_WA . '?text=' . rawurlencode('السلام عليكم، أريد الاستفسار عن المعدات');
  ?>
  </main>

  <footer class="footer">
    <div class="hazard" aria-hidden="true"></div>
    <div class="container footer__inner">
      <div class="footer__brand">
        <a href="/" class="brand">
          <span class="brand__mark" aria-hidden="true"><svg viewBox="0 0 64 64" width="24" height="24"><use href="#i-star"/></svg></span>
          <span class="brand__text"><strong><?= e(SITE_NAME) ?></strong><small dir="ltr">NUJOOM AL-FA TRADING</small></span>
        </a>
        <p>تأجير الرافعات والونشات والمعدات الثقيلة للمشاريع الإنشائية والبنية التحتية في الرياض وجميع مناطق المملكة.</p>
      </div>
      <div class="footer__col">
        <h4>روابط سريعة</h4>
        <a href="/#fleet">الأسطول</a>
        <a href="/#quote">جهّز طلبك</a>
        <a href="/ads/">إعلانات المعدات</a>
        <a href="/blog/">المدونة</a>
        <a href="/faq/">الأسئلة الشائعة</a>
        <a href="/#contact">تواصل معنا</a>
      </div>
      <div class="footer__col">
        <h4>مدن نخدمها</h4>
<?php foreach (['الرياض', 'جدة', 'مكة المكرمة', 'الدمام', 'المدينة المنورة'] as $c): ?>
        <a href="<?= e(query_path('/ads/', ['city' => $c])) ?>">تأجير معدات في <?= e($c) ?></a>
<?php endforeach; ?>
      </div>
      <div class="footer__col">
        <h4>تواصل</h4>
        <a href="tel:<?= SITE_PHONE ?>" dir="ltr"><?= SITE_PHONE_TXT ?></a>
        <a href="<?= e($wa) ?>" target="_blank" rel="noopener">واتساب مباشر</a>
        <span>الرياض، المملكة العربية السعودية</span>
        <span>دعم ٢٤/٧</span>
      </div>
    </div>
    <div class="footer__bottom">
      <div class="container footer__bottom-inner">
        <p>© <?= date('Y') ?> <?= e(SITE_NAME) ?> — جميع الحقوق محفوظة</p>
        <a href="#main" class="totop">للأعلى <svg width="16" height="16" style="transform:rotate(90deg)"><use href="#i-arrow"/></svg></a>
      </div>
    </div>
  </footer>

  <a class="float-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="تواصل واتساب">
    <svg width="28" height="28"><use href="#i-wa"/></svg>
    <span class="float-wa__tip">تحدث معنا الآن</span>
  </a>
  <script src="/js/pages.js?v=<?= ASSET_VER ?>" defer></script>
</body>
</html>
<?php
}

function page_404() {
  http_response_code(404);
  page_start(['title' => 'الصفحة غير موجودة | ' . SITE_NAME, 'description' => 'الصفحة المطلوبة غير موجودة.', 'path' => '/', 'robots' => 'noindex, follow', 'no_cache' => 1]);
  ?>
    <section class="container empty-state">
      <p class="kicker">خطأ ٤٠٤</p>
      <h1 class="h2">الصفحة غير موجودة</h1>
      <p class="lead">ربما نُقلت الصفحة أو انتهى الإعلان. تصفّح أحدث المعدات أو المقالات:</p>
      <div class="empty-state__actions">
        <a class="btn btn--amber" href="/ads/">إعلانات المعدات</a>
        <a class="btn btn--glass" href="/blog/">المدونة</a>
        <a class="btn btn--glass" href="/">الرئيسية</a>
      </div>
    </section>
<?php
  page_end();
  exit;
}

function pager($path, array $params, $page, $pages) {
  if ($pages <= 1) return;
  echo '<nav class="pager" aria-label="الصفحات">';
  for ($i = 1; $i <= $pages; $i++) {
    $href = query_path($path, $params + ['page' => $i]);
    echo $i === $page ? '<span aria-current="page">' . ar_num($i) . '</span>' : '<a href="' . e($href) . '">' . ar_num($i) . '</a>';
  }
  echo '</nav>';
}

function cta_box($city = '') {
  $where = $city ? 'في ' . $city : 'في الرياض وجميع مدن المملكة';
  $wa = 'https://wa.me/' . SITE_WA . '?text=' . rawurlencode('السلام عليكم، أريد عرض سعر لتأجير معدة ' . $where);
  ?>
      <aside class="cta-box">
        <div>
          <h2>تحتاج رافعة أو معدة <?= e($where) ?>؟</h2>
          <p>أرسل نوع المعدة والمدة وموقع المشروع، ونرسل لك عرض سعر واضحاً خلال ساعة.</p>
        </div>
        <div class="cta-box__actions">
          <a class="btn btn--wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg width="20" height="20"><use href="#i-wa"/></svg> واتساب</a>
          <a class="btn btn--amber" href="tel:<?= SITE_PHONE ?>"><svg width="18" height="18"><use href="#i-phone"/></svg> اتصل الآن</a>
        </div>
      </aside>
<?php
}
