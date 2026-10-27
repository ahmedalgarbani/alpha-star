<?php
/* المدونة — /blog/ (list) and /blog/{slug} (article). Articles are written in the dashboard: /admin/content.php */
define('SITE_APP', 1);
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/sanitize.php';
require __DIR__ . '/inc/layout.php';

$slug = trim((string) ($_GET['slug'] ?? ''), '/');
$now = now();

/* ================= single article ================= */
if ($slug !== '') {
  $post = db_one('SELECT * FROM posts WHERE slug = ? AND ' . POST_LIVE, [$slug, $now]);
  if (!$post) page_404();
  $path = post_path($post['slug']);
  enforce_path($path);

  $author = author_of($post['author_id']);
  $tags = array_values(array_filter(array_map('trim', explode(',', $post['tags']))));
  $body = add_heading_ids($post['content'], $toc);
  $desc = $post['meta_desc'] ?: ($post['excerpt'] ?: cut(plain_text($post['content']), 160));
  $words = count(preg_split('/\s+/u', plain_text($post['content']), -1, PREG_SPLIT_NO_EMPTY));
  $cover = $post['cover'];
  $size = img_size($cover);
  $related = db_all('SELECT slug, title, cover, cover_alt, published_at FROM posts WHERE id <> ? AND ' . POST_LIVE .
    ' ORDER BY CASE WHEN category = ? THEN 0 ELSE 1 END, published_at DESC LIMIT 3', [$post['id'], $now, $post['category']]);

  $authorLd = $author ? ['@type' => 'Person', 'name' => $author['name']] + ($author['bio'] ? ['description' => $author['bio']] : [])
                      : ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => url('/')];
  $article = [
    '@type' => 'BlogPosting', '@id' => url($path) . '#article', 'mainEntityOfPage' => url($path),
    'headline' => cut($post['title'], 110), 'description' => $desc, 'inLanguage' => SITE_LANG,
    'datePublished' => iso_date($post['published_at']), 'dateModified' => iso_date(max($post['updated_at'], $post['published_at'])),
    'author' => $authorLd, 'publisher' => ['@id' => url('/#business')], 'isPartOf' => ['@id' => url('/blog/') . '#blog'],
    'wordCount' => $words, 'timeRequired' => 'PT' . reading_minutes($post['content']) . 'M',
  ];
  if ($cover) $article['image'] = ['@type' => 'ImageObject', 'url' => abs_asset($cover)] + ($size ? ['width' => $size[0], 'height' => $size[1]] : []);
  if ($post['category']) $article['articleSection'] = $post['category'];
  if ($tags) $article['keywords'] = implode('، ', $tags);
  if ($post['city'] && ($pl = place_ld($post['city']))) { $article['spatialCoverage'] = $pl; $article['contentLocation'] = $pl; }

  page_start([
    'title' => ($post['meta_title'] ?: $post['title']) . ' | ' . SITE_NAME,
    'og_title' => $post['meta_title'] ?: $post['title'],
    'description' => $desc, 'path' => $path, 'type' => 'article', 'nav' => 'blog', 'city' => $post['city'],
    'image' => $cover ?: null, 'image_alt' => $post['cover_alt'] ?: $post['title'], 'preload' => $cover ? '/' . ltrim($cover, '/') : '',
    'crumbs' => [['المدونة', '/blog/'], [$post['title'], $path]],
    'article' => ['published' => $post['published_at'], 'modified' => $post['updated_at'], 'section' => $post['category'], 'tags' => $tags],
    'ld' => [$article],
  ]);
  $shareUrl = rawurlencode(url($path));
  $shareText = rawurlencode($post['title']);
  ?>
    <article class="container article">
      <header class="article__head">
<?php if ($post['category']): ?>        <a class="chip" href="<?= e(query_path('/blog/', ['cat' => $post['category']])) ?>"><?= e($post['category']) ?></a>
<?php endif; ?>
        <h1 class="article__title"><?= e($post['title']) ?></h1>
<?php if ($post['excerpt']): ?>        <p class="lead article__lead"><?= e($post['excerpt']) ?></p>
<?php endif; ?>
        <p class="meta">
          <span>بقلم <strong><?= e($author['name'] ?? SITE_NAME) ?></strong></span>
          <span><svg width="15" height="15"><use href="#i-cal"/></svg> <time datetime="<?= e(iso_date($post['published_at'])) ?>"><?= ar_date($post['published_at']) ?></time></span>
<?php if (substr($post['updated_at'], 0, 10) > substr($post['published_at'], 0, 10)): ?>
          <span>آخر تحديث: <time datetime="<?= e(iso_date($post['updated_at'])) ?>"><?= ar_date($post['updated_at']) ?></time></span>
<?php endif; ?>
          <span>قراءة <?= ar_num(reading_minutes($post['content'])) ?> دقائق</span>
<?php if ($post['city']): ?>          <span><svg width="15" height="15"><use href="#i-pin"/></svg> <?= e($post['city']) ?></span>
<?php endif; ?>
        </p>
      </header>
<?php if ($cover): ?>
      <figure class="article__cover">
        <img src="/<?= e(ltrim($cover, '/')) ?>" alt="<?= e($post['cover_alt'] ?: $post['title']) ?>"<?= $size ? ' width="' . $size[0] . '" height="' . $size[1] . '"' : '' ?> fetchpriority="high">
      </figure>
<?php endif; ?>
      <div class="article__layout">
<?php if (count($toc) >= 3): ?>
        <aside class="toc" aria-label="محتويات المقال">
          <p class="toc__title">محتويات المقال</p>
          <ol>
<?php foreach ($toc as $t): ?>            <li class="toc__<?= $t['level'] ?>"><a href="#<?= e($t['id']) ?>"><?= e($t['text']) ?></a></li>
<?php endforeach; ?>
          </ol>
        </aside>
<?php endif; ?>
        <div class="prose">
<?= $body ?>

<?php if ($tags): ?>
          <p class="tags"><?php foreach ($tags as $t): ?><span class="chip chip--muted">#<?= e($t) ?></span> <?php endforeach; ?></p>
<?php endif; ?>
          <div class="share">
            <span>شارك المقال:</span>
            <a href="https://wa.me/?text=<?= $shareText ?>%20<?= $shareUrl ?>" target="_blank" rel="noopener nofollow">واتساب</a>
            <a href="https://x.com/intent/post?text=<?= $shareText ?>&amp;url=<?= $shareUrl ?>" target="_blank" rel="noopener nofollow">إكس</a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareUrl ?>" target="_blank" rel="noopener nofollow">لينكدإن</a>
          </div>
<?php if ($author && $author['bio']): ?>
          <div class="author-box"><strong><?= e($author['name']) ?></strong><p><?= e($author['bio']) ?></p></div>
<?php endif; ?>
        </div>
      </div>
      <?php cta_box($post['city']); ?>
    </article>
<?php if ($related): ?>
    <section class="container related">
      <h2 class="section-title">مقالات ذات صلة</h2>
      <div class="cards"><?php foreach ($related as $r) post_card($r); ?></div>
    </section>
<?php endif;
  page_end();
  exit;
}

/* ================= list ================= */
$cat = trim((string) ($_GET['cat'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
enforce_path('/blog/');

$where = POST_LIVE; $args = [$now];
if ($cat !== '') { $where .= ' AND category = ?'; $args[] = $cat; }
$total = (int) db_val("SELECT COUNT(*) FROM posts WHERE $where", $args);
$pages = max(1, (int) ceil($total / PER_PAGE));
if ($page > $pages && $total) page_404();
$posts = db_all("SELECT id, slug, title, excerpt, content, cover, cover_alt, category, published_at, author_id FROM posts WHERE $where ORDER BY published_at DESC LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE), $args);
$cats = db_all('SELECT category, COUNT(*) AS n FROM posts WHERE ' . POST_LIVE . " AND category <> '' GROUP BY category ORDER BY n DESC", [$now]);
if ($cat !== '' && !$total) page_404();

$params = $cat !== '' ? ['cat' => $cat] : [];
$self = query_path('/blog/', $params + ['page' => $page]);
$heading = $cat !== '' ? 'مقالات: ' . $cat : 'مدونة تأجير المعدات الثقيلة';
$items = [];
foreach ($posts as $i => $p) $items[] = ['@type' => 'ListItem', 'position' => ($page - 1) * PER_PAGE + $i + 1, 'url' => url(post_path($p['slug'])), 'name' => $p['title']];

page_start([
  'title' => $heading . ($page > 1 ? ' — صفحة ' . $page : '') . ' | ' . SITE_NAME,
  'description' => $cat !== ''
    ? 'مقالات ' . $cat . ' من نجوم الفاء: نصائح ودلائل عملية لتأجير الرافعات والمعدات الثقيلة في الرياض والسعودية.'
    : 'نصائح ودلائل عملية لاختيار وتأجير الرافعات والونشات والمان لفت والحفارات في الرياض وجميع مدن السعودية — من فريق نجوم الفاء.',
  'path' => $self, 'nav' => 'blog',
  'crumbs' => $cat !== '' ? [['المدونة', '/blog/'], [$cat, $self]] : [['المدونة', '/blog/']],
  'prev' => $page > 1 ? query_path('/blog/', $params + ['page' => $page - 1]) : '',
  'next' => $page < $pages ? query_path('/blog/', $params + ['page' => $page + 1]) : '',
  'ld' => [
    ['@type' => 'Blog', '@id' => url('/blog/') . '#blog', 'url' => url('/blog/'), 'name' => 'مدونة ' . SITE_NAME, 'inLanguage' => SITE_LANG, 'publisher' => ['@id' => url('/#business')]],
    ['@type' => 'CollectionPage', '@id' => url($self), 'url' => url($self), 'name' => $heading, 'isPartOf' => ['@id' => url('/#website')],
     'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]],
  ],
]);
?>
    <section class="container page-head">
      <p class="kicker">المدونة</p>
      <h1 class="h2"><?= e($heading) ?></h1>
      <p class="lead">دلائل عملية تساعدك تختار المعدة المناسبة لمشروعك، وتعرف الأسعار واشتراطات السلامة قبل التأجير.</p>
<?php if ($cats): ?>
      <div class="filters">
        <a class="chip<?= $cat === '' ? ' is-on' : '' ?>" href="/blog/">الكل</a>
<?php foreach ($cats as $c): ?>        <a class="chip<?= $cat === $c['category'] ? ' is-on' : '' ?>" href="<?= e(query_path('/blog/', ['cat' => $c['category']])) ?>"><?= e($c['category']) ?> <small><?= ar_num($c['n']) ?></small></a>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </section>
    <section class="container">
<?php if (!$posts): ?>
      <div class="empty-state"><p class="lead">لا توجد مقالات منشورة بعد — تابعنا قريباً.</p></div>
<?php else: ?>
      <div class="cards"><?php foreach ($posts as $p) post_card($p, true); ?></div>
      <?php pager('/blog/', $params, $page, $pages); ?>
<?php endif; ?>
    </section>
<?php
page_end();

/* ---------------- pieces ---------------- */
function post_card($p, $withExcerpt = false) {
  $size = img_size($p['cover']);
  ?>
        <article class="card">
          <a class="card__link" href="<?= e(post_path($p['slug'])) ?>">
            <div class="card__img"><?php if ($p['cover']): ?><img src="/<?= e(ltrim($p['cover'], '/')) ?>" alt="<?= e($p['cover_alt'] ?: $p['title']) ?>" loading="lazy" decoding="async"<?= $size ? ' width="' . $size[0] . '" height="' . $size[1] . '"' : '' ?>><?php endif; ?></div>
            <div class="card__body">
<?php if (!empty($p['category'])): ?>              <span class="chip chip--sm"><?= e($p['category']) ?></span>
<?php endif; ?>
              <h2 class="card__title"><?= e($p['title']) ?></h2>
<?php if ($withExcerpt): ?>              <p class="card__text"><?= e($p['excerpt'] ?: cut(plain_text($p['content']), 140)) ?></p>
<?php endif; ?>
              <p class="card__meta"><time datetime="<?= e(iso_date($p['published_at'])) ?>"><?= ar_date($p['published_at']) ?></time></p>
            </div>
          </a>
        </article>
<?php
}
