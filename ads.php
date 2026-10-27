<?php
/* إعلانات المعدات — /ads/ (list, filter by city / type / deal) and /ads/{slug} (one ad).
   Employees post ads from the dashboard: /admin/content.php */
define('SITE_APP', 1);
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/layout.php';

$slug = trim((string) ($_GET['slug'] ?? ''), '/');
$now = now();

/* ================= single ad ================= */
if ($slug !== '') {
  $ad = db_one("SELECT * FROM ads WHERE slug = ? AND status = 'published' AND published_at IS NOT NULL AND published_at <= ?", [$slug, $now]);
  if (!$ad) page_404();
  $path = ad_path($ad['slug']);
  enforce_path($path);

  $expired = $ad['expires_at'] !== null && $ad['expires_at'] <= $now;
  $images = ad_images($ad);
  $phone = ad_phone($ad);
  $deal = AD_DEALS[$ad['deal']] ?? '';
  $desc = $ad['meta_desc'] ?: cut($ad['title'] . ' ' . $deal . ($ad['city'] ? ' في ' . $ad['city'] : '') . ' — ' . plain_text($ad['description']), 160);
  $hasPrice = $ad['price'] !== null && (float) $ad['price'] > 0;

  // schema.org: a rental is a Service with an Offer; a sale with a price is a Product with an Offer.
  $offer = ['@type' => 'Offer', 'url' => url($path), 'priceCurrency' => 'SAR', 'seller' => ['@id' => url('/#business')],
            'availability' => $expired ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock'];
  if ($hasPrice) {
    $offer['price'] = (float) $ad['price'];
    if ($ad['deal'] === 'rent' && $ad['price_unit'] !== 'once') {
      $codes = ['day' => 'DAY', 'week' => 'WEE', 'month' => 'MON', 'year' => 'ANN', 'hour' => 'HUR'];
      $offer['priceSpecification'] = ['@type' => 'UnitPriceSpecification', 'price' => (float) $ad['price'], 'priceCurrency' => 'SAR',
                                      'unitCode' => $codes[$ad['price_unit']] ?? 'DAY', 'unitText' => PRICE_UNITS[$ad['price_unit']] ?? ''];
    }
  }
  if ($ad['expires_at']) $offer['validThrough'] = iso_date($ad['expires_at']);
  if ($ad['city'] && ($pl = place_ld($ad['city']))) $offer['areaServed'] = $pl;
  $node = [
    '@id' => url($path) . '#item', 'name' => $ad['title'], 'description' => cut(plain_text($ad['description']), 500), 'url' => url($path),
    'category' => $ad['category'], 'image' => array_map('abs_asset', $images),
  ];
  if ($ad['deal'] === 'sale' && $hasPrice) {
    $node = ['@type' => 'Product'] + $node + ['brand' => ['@type' => 'Brand', 'name' => SITE_NAME]];
    if ($ad['item_condition']) $offer['itemCondition'] = $ad['item_condition'] === 'new' ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition';
    $node['offers'] = $offer;
  } else {
    $node = ['@type' => 'Service', 'serviceType' => 'تأجير ' . $ad['category']] + $node + ['provider' => ['@id' => url('/#business')]];
    if ($ad['city'] && isset($offer['areaServed'])) $node['areaServed'] = $offer['areaServed'];
    if ($hasPrice) $node['offers'] = $offer;
  }
  if (!$node['image']) unset($node['image']);

  $waText = 'السلام عليكم، أستفسر عن إعلان: ' . $ad['title'] . "\n" . url($path);
  $wa = 'https://wa.me/' . wa_number($phone) . '?text=' . rawurlencode($waText);
  $related = db_all('SELECT * FROM ads WHERE id <> ? AND ' . AD_LIVE . ' ORDER BY CASE WHEN city = ? THEN 0 ELSE 1 END, CASE WHEN category = ? THEN 0 ELSE 1 END, published_at DESC LIMIT 3',
    [$ad['id'], $now, $now, $ad['city'], $ad['category']]);
  $crumbs = [['إعلانات المعدات', '/ads/']];
  if ($ad['city']) $crumbs[] = ['معدات في ' . $ad['city'], query_path('/ads/', ['city' => $ad['city']])];
  $crumbs[] = [$ad['title'], $path];

  page_start([
    'title' => $ad['title'] . ' ' . $deal . ($ad['city'] ? ' في ' . $ad['city'] : '') . ' | ' . SITE_NAME,
    'description' => $desc, 'path' => $path, 'nav' => 'ads', 'city' => $ad['city'],
    'image' => $images[0] ?? null, 'image_alt' => $ad['title'], 'preload' => isset($images[0]) ? '/' . ltrim($images[0], '/') : '',
    'robots' => $expired ? 'noindex, follow' : null, 'crumbs' => $crumbs, 'ld' => [$node],
  ]);
  ?>
    <article class="container ad">
<?php if ($expired): ?>
      <p class="notice">انتهى هذا الإعلان — تواصل معنا لمعرفة المعدات المتاحة حالياً أو تصفح <a href="/ads/">أحدث الإعلانات</a>.</p>
<?php endif; ?>
      <div class="ad__grid">
        <div class="ad__media">
<?php if ($images): $size = img_size($images[0]); ?>
          <figure class="ad__main"><img id="adMain" src="/<?= e(ltrim($images[0], '/')) ?>" alt="<?= e($ad['title']) ?>"<?= $size ? ' width="' . $size[0] . '" height="' . $size[1] . '"' : '' ?> fetchpriority="high"></figure>
<?php if (count($images) > 1): ?>
          <div class="ad__thumbs">
<?php foreach ($images as $i => $img): ?>            <button type="button" data-src="/<?= e(ltrim($img, '/')) ?>"<?= $i === 0 ? ' class="is-on"' : '' ?> aria-label="صورة <?= ar_num($i + 1) ?>"><img src="/<?= e(ltrim($img, '/')) ?>" alt="<?= e($ad['title'] . ' — صورة ' . ar_num($i + 1)) ?>" loading="lazy"></button>
<?php endforeach; ?>
          </div>
<?php endif; else: ?>
          <div class="ad__main ad__main--empty"><svg viewBox="0 0 64 64" width="64" height="64"><use href="#i-star"/></svg></div>
<?php endif; ?>
        </div>
        <div class="ad__info">
          <p class="ad__badges">
            <span class="chip <?= $ad['deal'] === 'sale' ? 'chip--sale' : 'chip--rent' ?>"><?= e($deal) ?></span>
<?php if ($ad['category']): ?>            <a class="chip chip--muted" href="<?= e(query_path('/ads/', ['type' => $ad['category']])) ?>"><?= e($ad['category']) ?></a>
<?php endif; ?>
          </p>
          <h1 class="ad__title"><?= e($ad['title']) ?></h1>
          <p class="ad__price"><?= e(ad_price_text($ad)) ?></p>
          <dl class="specs">
<?php if ($ad['city']): ?>            <div><dt>المدينة</dt><dd><a href="<?= e(query_path('/ads/', ['city' => $ad['city']])) ?>"><?= e($ad['city']) ?></a></dd></div>
<?php endif; if ($ad['category']): ?>            <div><dt>نوع المعدة</dt><dd><?= e($ad['category']) ?></dd></div>
<?php endif; if ($ad['item_condition']): ?>            <div><dt>الحالة</dt><dd><?= e(AD_CONDITIONS[$ad['item_condition']] ?? '') ?></dd></div>
<?php endif; ?>
            <div><dt>تاريخ النشر</dt><dd><time datetime="<?= e(iso_date($ad['published_at'])) ?>"><?= ar_date($ad['published_at']) ?></time></dd></div>
<?php if ($ad['expires_at'] && !$expired): ?>            <div><dt>متاح حتى</dt><dd><?= ar_date($ad['expires_at']) ?></dd></div>
<?php endif; ?>
          </dl>
<?php if (!$expired): ?>
          <div class="ad__actions">
            <a class="btn btn--wa btn--lg" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg width="20" height="20"><use href="#i-wa"/></svg> اسأل عن الإعلان واتساب</a>
            <a class="btn btn--amber btn--lg" href="tel:<?= e($phone) ?>"><svg width="18" height="18"><use href="#i-phone"/></svg> اتصل الآن</a>
          </div>
<?php endif; ?>
        </div>
      </div>
      <section class="prose ad__desc">
        <h2>تفاصيل الإعلان</h2>
<?= nl2p($ad['description']) ?>
      </section>
    </article>
<?php if ($related): ?>
    <section class="container related">
      <h2 class="section-title">إعلانات مشابهة</h2>
      <div class="cards"><?php foreach ($related as $r) ad_card($r); ?></div>
    </section>
<?php endif; ?>
    <div class="container"><?php cta_box($ad['city']); ?></div>
    <script>
      document.querySelectorAll(".ad__thumbs button").forEach(function (b) {
        b.addEventListener("click", function () {
          document.getElementById("adMain").src = b.dataset.src;
          document.querySelectorAll(".ad__thumbs button").forEach(function (x) { x.classList.toggle("is-on", x === b); });
        });
      });
    </script>
<?php
  page_end();
  exit;
}

/* ================= list ================= */
$city = trim((string) ($_GET['city'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$deal = trim((string) ($_GET['deal'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($city !== '' && !isset(CITIES[$city])) $city = '';
if ($type !== '' && !in_array($type, AD_CATEGORIES, true)) $type = '';
if (!isset(AD_DEALS[$deal])) $deal = '';
enforce_path('/ads/');

$where = AD_LIVE; $args = [$now, $now];
if ($city !== '') { $where .= ' AND city = ?'; $args[] = $city; }
if ($type !== '') { $where .= ' AND category = ?'; $args[] = $type; }
if ($deal !== '') { $where .= ' AND deal = ?'; $args[] = $deal; }
$total = (int) db_val("SELECT COUNT(*) FROM ads WHERE $where", $args);
$pages = max(1, (int) ceil($total / PER_PAGE));
if ($page > $pages && $total) page_404();
$ads = db_all("SELECT * FROM ads WHERE $where ORDER BY published_at DESC LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE), $args);
$cityCounts = [];
foreach (db_all('SELECT city, COUNT(*) AS n FROM ads WHERE ' . AD_LIVE . " AND city <> '' GROUP BY city", [$now, $now]) as $r) $cityCounts[$r['city']] = (int) $r['n'];

$params = array_filter(['city' => $city, 'type' => $type, 'deal' => $deal], 'strlen');
$self = query_path('/ads/', $params + ['page' => $page]);
$what = $type !== '' ? $type : 'معدات ثقيلة';
$dealTxt = $deal === 'sale' ? 'للبيع' : ($deal === 'rent' ? 'للإيجار' : 'للإيجار والبيع');
$heading = $what . ' ' . $dealTxt . ($city !== '' ? ' في ' . $city : ' في السعودية');
// city pages are good local landing pages; deeper filter combinations stay out of Google's index
$indexable = $total > 0 && count($params) <= 1;
$items = [];
foreach ($ads as $i => $a) $items[] = ['@type' => 'ListItem', 'position' => ($page - 1) * PER_PAGE + $i + 1, 'url' => url(ad_path($a['slug'])), 'name' => $a['title']];

page_start([
  'title' => 'إعلانات ' . $heading . ($page > 1 ? ' — صفحة ' . $page : '') . ' | ' . SITE_NAME,
  'description' => 'أحدث إعلانات ' . $heading . ': ونشات ورافعات، مان لفت، حفارات ورافعات شوكية بأسعار واضحة وتواصل مباشر واتساب مع ' . SITE_NAME . '.',
  'path' => $self, 'nav' => 'ads', 'city' => $city,
  'robots' => $indexable ? null : 'noindex, follow',
  'crumbs' => $city !== '' ? [['إعلانات المعدات', '/ads/'], ['معدات في ' . $city, $self]] : [['إعلانات المعدات', '/ads/']],
  'prev' => $page > 1 ? query_path('/ads/', $params + ['page' => $page - 1]) : '',
  'next' => $page < $pages ? query_path('/ads/', $params + ['page' => $page + 1]) : '',
  'ld' => [['@type' => 'CollectionPage', '@id' => url($self), 'url' => url($self), 'name' => 'إعلانات ' . $heading, 'isPartOf' => ['@id' => url('/#website')],
            'about' => $city !== '' ? place_ld($city) : ['@type' => 'Country', 'name' => 'المملكة العربية السعودية'],
            'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => $items]]],
]);
?>
    <section class="container page-head">
      <p class="kicker">إعلانات المعدات</p>
      <h1 class="h2"><?= e($heading) ?></h1>
      <p class="lead">معدات متاحة الآن من أسطول سرعة الإنجاز الدولية — اختر المدينة ونوع المعدة، وتواصل مباشرة واتساب لحجزها.</p>
      <form class="filterbar" method="get" action="/ads/">
        <label><span>المدينة</span>
          <select name="city"><option value="">كل المدن</option>
<?php foreach (CITIES as $c => $_): ?>            <option value="<?= e($c) ?>"<?= $c === $city ? ' selected' : '' ?>><?= e($c) ?><?= isset($cityCounts[$c]) ? ' (' . ar_num($cityCounts[$c]) . ')' : '' ?></option>
<?php endforeach; ?>
          </select></label>
        <label><span>نوع المعدة</span>
          <select name="type"><option value="">كل الأنواع</option>
<?php foreach (AD_CATEGORIES as $c): ?>            <option<?= $c === $type ? ' selected' : '' ?>><?= e($c) ?></option>
<?php endforeach; ?>
          </select></label>
        <label><span>نوع الإعلان</span>
          <select name="deal"><option value="">إيجار وبيع</option>
<?php foreach (AD_DEALS as $k => $v): ?>            <option value="<?= $k ?>"<?= $k === $deal ? ' selected' : '' ?>><?= e($v) ?></option>
<?php endforeach; ?>
          </select></label>
        <button class="btn btn--amber">عرض</button>
      </form>
    </section>
    <section class="container">
<?php if (!$ads): ?>
      <div class="empty-state">
        <p class="lead">لا توجد إعلانات مطابقة حالياً<?= $city !== '' ? ' في ' . e($city) : '' ?> — لكن أسطولنا يصل لجميع مدن المملكة.</p>
        <div class="empty-state__actions"><a class="btn btn--glass" href="/ads/">كل الإعلانات</a></div>
      </div>
      <?php cta_box($city); ?>
<?php else: ?>
      <div class="cards"><?php foreach ($ads as $a) ad_card($a); ?></div>
      <?php pager('/ads/', $params, $page, $pages); ?>
<?php endif; ?>
    </section>
<?php if ($city === '' && $cityCounts): ?>
    <section class="container city-links">
      <h2 class="section-title">تأجير معدات حسب المدينة</h2>
      <p>
<?php foreach ($cityCounts as $c => $n): ?>        <a class="chip" href="<?= e(query_path('/ads/', ['city' => $c])) ?>">تأجير معدات في <?= e($c) ?> <small><?= ar_num($n) ?></small></a>
<?php endforeach; ?>
      </p>
    </section>
<?php endif;
page_end();

function ad_card($a) {
  $imgs = ad_images($a);
  $size = $imgs ? img_size($imgs[0]) : null;
  ?>
        <article class="card card--ad">
          <a class="card__link" href="<?= e(ad_path($a['slug'])) ?>">
            <div class="card__img"><?php if ($imgs): ?><img src="/<?= e(ltrim($imgs[0], '/')) ?>" alt="<?= e($a['title']) ?>" loading="lazy" decoding="async"<?= $size ? ' width="' . $size[0] . '" height="' . $size[1] . '"' : '' ?>><?php endif; ?>
              <span class="chip chip--float <?= $a['deal'] === 'sale' ? 'chip--sale' : 'chip--rent' ?>"><?= e(AD_DEALS[$a['deal']] ?? '') ?></span></div>
            <div class="card__body">
              <h2 class="card__title"><?= e($a['title']) ?></h2>
              <p class="card__price"><?= e(ad_price_text($a)) ?></p>
              <p class="card__meta"><?php if ($a['city']): ?><svg width="14" height="14"><use href="#i-pin"/></svg> <?= e($a['city']) ?> · <?php endif; ?><?= e($a['category']) ?></p>
            </div>
          </a>
        </article>
<?php
}
