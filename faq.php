<?php
/* الأسئلة الشائعة — /faq/. Questions are managed in the dashboard: /admin/content.php?s=faq */
define('SITE_APP', 1);
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/layout.php';

enforce_path('/faq/');
$faqs = db_all("SELECT * FROM faqs WHERE status = 'published' ORDER BY sort_order, id");
$groups = [];
foreach ($faqs as $f) $groups[$f['category'] !== '' ? $f['category'] : 'أسئلة عامة'][] = $f;

$qa = [];
foreach ($faqs as $f) {
  $qa[] = ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => plain_text(nl2p($f['answer']))]];
}

page_start([
  'title' => 'الأسئلة الشائعة عن تأجير الرافعات والمعدات الثقيلة | ' . SITE_NAME,
  'description' => 'إجابات واضحة عن أسعار تأجير الونشات والرافعات والمان لفت، مدة الإيجار، المشغلين، الصيانة، والتوصيل في الرياض وجميع مدن السعودية.',
  'path' => '/faq/', 'nav' => 'faq',
  'crumbs' => [['الأسئلة الشائعة', '/faq/']],
  'ld' => $qa ? [['@type' => 'FAQPage', '@id' => url('/faq/') . '#faq', 'url' => url('/faq/'), 'inLanguage' => SITE_LANG, 'mainEntity' => $qa]] : [],
]);
?>
    <section class="container page-head">
      <p class="kicker">الأسئلة الشائعة</p>
      <h1 class="h2">كل ما تريد معرفته عن<br><span class="amber">تأجير الرافعات والمعدات الثقيلة</span></h1>
      <p class="lead">جمعنا أكثر الأسئلة التي يطرحها عملاؤنا في الرياض وجميع مدن المملكة. لم تجد سؤالك؟ راسلنا واتساب ونرد فوراً.</p>
<?php if (count($groups) > 1): ?>
      <div class="filters">
<?php foreach (array_keys($groups) as $i => $g): ?>        <a class="chip" href="#g<?= $i + 1 ?>"><?= e($g) ?></a>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </section>
    <section class="container faq-page">
<?php if (!$faqs): ?>
      <div class="empty-state"><p class="lead">لا توجد أسئلة منشورة بعد.</p></div>
<?php endif; $gi = 0; foreach ($groups as $g => $list): $gi++; ?>
      <div class="faq faq--page" id="g<?= $gi ?>">
        <h2 class="faq__title"><?= e($g) ?></h2>
<?php foreach ($list as $f): ?>
        <details id="q<?= (int) $f['id'] ?>">
          <summary><?= e($f['question']) ?></summary>
          <div class="faq__answer"><?= nl2p($f['answer']) ?></div>
        </details>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
      <?php cta_box(); ?>
    </section>
    <script>
      // open the question linked from another page (/faq/#q12)
      try { var d = document.querySelector("details" + location.hash); if (d) d.open = true; } catch (e) {}
    </script>
<?php
page_end();
