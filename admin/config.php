<?php
/* ========= SET YOUR EDITOR PASSWORD HERE =========
   At least 10 characters. Don't share it.
   Then open:  https://your-domain.com/admin/   (leave "user name" empty, type this password)
   Employees get their own accounts from the dashboard: الموظفون → إضافة موظف            */

return [
  'password' => '132.122.127.',

  // The live address of the site (used for canonical links, sitemap, schema.org). No slash at the end.
  'site_url' => 'https://riyadh-tajir.com',

  /* Database for the blog, ads, FAQ and employee accounts.
     'sqlite' needs no setup (one file in admin/data/, blocked from the web).
     If your hosting has no SQLite, create a MySQL database in cPanel and fill in the details:
       'db' => ['driver' => 'mysql', 'host' => 'localhost', 'name' => 'cpuser_site', 'user' => 'cpuser_site', 'pass' => '…'], */
  'db' => ['driver' => 'sqlite'],
];
