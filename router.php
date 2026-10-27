<?php
/* Local testing only (the live site uses .htaccess):
     php -S localhost:8000 router.php        → http://localhost:8000/   ·   dashboard: /admin/
   Mirrors the clean URLs and private-file rules from .htaccess. */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('~(^|/)\.|^/(inc|admin/(data|backups))(/|$)|^/router\.php$|\.(bat|cmd|sh|md|log|zip|sqlite|db)$|^/admin/(config|lib)\.php$|^/admin/(server\.js|editor\.html)$~i', $path)) {
  http_response_code(404); exit('Not found');
}

$routes = [
  '~^/blog/feed\.xml$~' => ['feeds.php', ['f' => 'rss']],
  '~^/sitemap-content\.xml$~' => ['feeds.php', ['f' => 'sitemap']],
  '~^/llms\.txt$~' => ['feeds.php', ['f' => 'llms']],
  '~^/blog/?$~' => ['blog.php', []],
  '~^/blog/([^/]+)/?$~' => ['blog.php', ['slug' => 1]],
  '~^/ads/?$~' => ['ads.php', []],
  '~^/ads/([^/]+)/?$~' => ['ads.php', ['slug' => 1]],
  '~^/faq/?$~' => ['faq.php', []],
];
foreach ($routes as $re => $r) {
  if (preg_match($re, $path, $m)) {
    foreach ($r[1] as $k => $v) $_GET[$k] = is_int($v) ? $m[$v] : $v;
    chdir(__DIR__);
    require __DIR__ . '/' . $r[0];
    return true;
  }
}

$file = __DIR__ . $path;
if ($path === '/' || is_file($file) || (is_dir($file) && (is_file($file . '/index.php') || is_file($file . '/index.html')))) return false;
require __DIR__ . '/404.php';
