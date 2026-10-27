<?php
/* "Page not found" page in the site's design (set in .htaccess: ErrorDocument 404 /404.php). */
define('SITE_APP', 1);
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/layout.php';
page_404();
