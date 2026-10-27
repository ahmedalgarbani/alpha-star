<?php
/* Editable preview of index.html for the site editor (admin/). Login required. */
define('ED_APP', 1);
require __DIR__ . '/admin/lib.php';

if (!ed_is_admin()) { header('Location: admin/'); exit; }
session_write_close();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow');
echo ed_preview_html();
