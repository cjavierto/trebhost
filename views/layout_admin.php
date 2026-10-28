<?php
/** @var string $content */
$s = settings();
$flash = take_flash();
$nav = $nav ?? '';
$items = [
    'clients' => ['/admin', 'Clients'],
    'payments' => ['/admin/payments', 'Payments'],
    'settings' => ['/admin/settings', 'Settings'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Admin') ?> — <?= e($s['business_name']) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <?= logo(28) ?>
      <span class="name"><?= e($s['business_name']) ?></span>
      <span class="tag">Admin</span>
    </div>
    <nav class="nav" aria-label="Main">
      <?php foreach ($items as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= icon($key, 18) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <span class="avatar" aria-hidden="true"><?= e(initials($s['business_name'])) ?></span>
      <div class="who">
        <span>Owner</span>
        <form method="post" action="/admin/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
      </div>
    </div>
  </aside>
  <main class="main">
    <?php if ($flash): ?>
      <div class="banner <?= $flash[0] === 'error' ? 'error' : 'success' ?>" role="<?= $flash[0] === 'error' ? 'alert' : 'status' ?>"><div><?= e($flash[1]) ?></div></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
