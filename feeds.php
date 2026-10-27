<?php
/* Machine-readable files, always up to date with the database:
     /sitemap-content.xml → feeds.php?f=sitemap   (blog, ads, FAQ, city pages — for Google/Bing)
     /blog/feed.xml       → feeds.php?f=rss       (RSS of the blog)
     /llms.txt            → feeds.php?f=llms      (site summary for AI assistants: ChatGPT, Claude, Perplexity, Gemini) */
define('SITE_APP', 1);
require __DIR__ . '/inc/app.php';

$f = (string) ($_GET['f'] ?? '');
$now = now();
function x($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=900');

/* ---------------- sitemap ---------------- */
if ($f === 'sitemap') {
  header('Content-Type: application/xml; charset=utf-8');
  $posts = db_all('SELECT slug, cover, cover_alt, title, updated_at, published_at FROM posts WHERE ' . POST_LIVE . ' ORDER BY published_at DESC', [$now]);
  $ads = db_all('SELECT slug, images, title, city, updated_at, published_at FROM ads WHERE ' . AD_LIVE . ' ORDER BY published_at DESC', [$now, $now]);
  $faqMod = db_val("SELECT MAX(updated_at) FROM faqs WHERE status = 'published'");
  $blogMod = $posts ? max(array_column($posts, 'updated_at')) : null;
  $adsMod = $ads ? max(array_column($ads, 'updated_at')) : null;

  $urls = [];
  $urls[] = ['/blog/', $blogMod, 'daily', '0.8', []];
  $urls[] = ['/ads/', $adsMod, 'daily', '0.9', []];
  $urls[] = ['/faq/', $faqMod, 'monthly', '0.7', []];
  $cities = [];
  foreach ($ads as $a) if ($a['city'] !== '') $cities[$a['city']] = max($cities[$a['city']] ?? '', $a['updated_at']);
  foreach ($cities as $c => $mod) $urls[] = [query_path('/ads/', ['city' => $c]), $mod, 'weekly', '0.8', []];
  foreach ($posts as $p) $urls[] = [post_path($p['slug']), max($p['updated_at'], $p['published_at']), 'monthly', '0.7', $p['cover'] ? [$p['cover']] : []];
  foreach ($ads as $a) $urls[] = [ad_path($a['slug']), max($a['updated_at'], $a['published_at']), 'weekly', '0.6', array_slice(ad_images($a), 0, 5)];

  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
  foreach ($urls as $u) {
    echo "  <url>\n    <loc>" . x(url($u[0])) . "</loc>\n";
    if ($u[1]) echo '    <lastmod>' . x(date('Y-m-d', strtotime($u[1]))) . "</lastmod>\n";
    echo '    <changefreq>' . $u[2] . "</changefreq>\n    <priority>" . $u[3] . "</priority>\n";
    foreach ($u[4] as $img) echo '    <image:image><image:loc>' . x(abs_asset($img)) . "</image:loc></image:image>\n";
    echo "  </url>\n";
  }
  echo "</urlset>\n";
  exit;
}

/* ---------------- RSS ---------------- */
if ($f === 'rss') {
  header('Content-Type: application/rss+xml; charset=utf-8');
  $posts = db_all('SELECT * FROM posts WHERE ' . POST_LIVE . ' ORDER BY published_at DESC LIMIT 30', [$now]);
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n";
  echo '  <title>' . x('مدونة ' . SITE_NAME) . "</title>\n  <link>" . x(url('/blog/')) . "</link>\n";
  echo '  <description>' . x('نصائح ودلائل تأجير الرافعات والمعدات الثقيلة في الرياض والسعودية') . "</description>\n";
  echo "  <language>ar-sa</language>\n  <atom:link href=\"" . x(url('/blog/feed.xml')) . "\" rel=\"self\" type=\"application/rss+xml\"/>\n";
  if ($posts) echo '  <lastBuildDate>' . date(DATE_RSS, strtotime($posts[0]['updated_at'])) . "</lastBuildDate>\n";
  foreach ($posts as $p) {
    $author = author_of($p['author_id']);
    echo "  <item>\n    <title>" . x($p['title']) . "</title>\n    <link>" . x(url(post_path($p['slug']))) . "</link>\n";
    echo '    <guid isPermaLink="false">' . x(url('/blog/?p=' . $p['id'])) . "</guid>\n";
    echo '    <pubDate>' . date(DATE_RSS, strtotime($p['published_at'])) . "</pubDate>\n";
    echo '    <dc:creator>' . x($author['name'] ?? SITE_NAME) . "</dc:creator>\n";
    if ($p['category']) echo '    <category>' . x($p['category']) . "</category>\n";
    echo '    <description>' . x($p['excerpt'] ?: cut(plain_text($p['content']), 300)) . "</description>\n  </item>\n";
  }
  echo "</channel>\n</rss>\n";
  exit;
}

/* ---------------- llms.txt ---------------- */
if ($f === 'llms') {
  header('Content-Type: text/plain; charset=utf-8');
  $posts = db_all('SELECT slug, title, excerpt, content FROM posts WHERE ' . POST_LIVE . ' ORDER BY published_at DESC LIMIT 50', [$now]);
  $ads = db_all('SELECT * FROM ads WHERE ' . AD_LIVE . ' ORDER BY published_at DESC LIMIT 50', [$now, $now]);
  $faqs = db_all("SELECT question, answer FROM faqs WHERE status = 'published' ORDER BY sort_order, id");
  $l = function ($s) { return str_replace(["\r", "\n"], ' ', trim((string) $s)); };

  echo '# ' . SITE_LEGAL . ' (' . SITE_NAME_EN . ")\n\n";
  echo "> شركة سعودية مقرها الرياض لتأجير الرافعات والونشات (كرين) من ٥ إلى ١٧٥ طن، والمان لفت، والحفارات والشيولات، والرافعات الشوكية والتيليهندل — مع مشغلين معتمدين وصيانة ميدانية ٢٤/٧ وتوصيل لجميع مدن المملكة العربية السعودية.\n\n";
  echo '- المدير العام (زياد مطير) — هاتف وواتساب: ' . SITE_PHONE . "\n";
  echo '- مسؤول الحركة (عمر): ' . SITE_PHONE2 . "\n";
  echo '- العنوان: ' . SITE_ADDRESS . "، المملكة العربية السعودية\n";
  echo '- مناطق الخدمة: ' . implode('، ', array_keys(CITIES)) . "\n";
  echo "- مدة الإيجار: يومي، أسبوعي، شهري، سنوي\n- الموقع: " . url('/') . "\n\n";
  echo "## الصفحات الرئيسية\n\n";
  echo '- [الرئيسية — الأسطول وعرض السعر](' . url('/') . "): أنواع المعدات والحمولات وطلب عرض سعر واتساب\n";
  echo '- [إعلانات المعدات](' . url('/ads/') . "): معدات متاحة للإيجار والبيع حسب المدينة\n";
  echo '- [المدونة](' . url('/blog/') . "): دلائل تأجير واختيار المعدات الثقيلة\n";
  echo '- [الأسئلة الشائعة](' . url('/faq/') . "): الأسعار، المشغلين، الصيانة، التوصيل\n\n";
  if ($ads) {
    echo "## إعلانات المعدات الحالية\n\n";
    foreach ($ads as $a) echo '- [' . $l($a['title']) . '](' . url(ad_path($a['slug'])) . '): ' . (AD_DEALS[$a['deal']] ?? '') . ($a['city'] ? ' في ' . $a['city'] : '') . ' — ' . ad_price_text($a) . "\n";
    echo "\n";
  }
  if ($posts) {
    echo "## مقالات\n\n";
    foreach ($posts as $p) echo '- [' . $l($p['title']) . '](' . url(post_path($p['slug'])) . '): ' . $l($p['excerpt'] ?: cut(plain_text($p['content']), 160)) . "\n";
    echo "\n";
  }
  if ($faqs) {
    echo "## أسئلة شائعة\n\n";
    foreach ($faqs as $q) echo '### ' . $l($q['question']) . "\n\n" . trim($q['answer']) . "\n\n";
  }
  exit;
}

http_response_code(404);
