<?php
/** @var string $content */
$s = settings();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Billing') ?> — <?= e($s['business_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<div class="pub">
  <header class="pub-header">
    <div class="in">
      <div class="wm"><?= logo(28) ?><?= e($s['business_name']) ?></div>
      <a href="mailto:<?= e($s['billing_email']) ?>">Need help? <?= e($s['billing_email']) ?></a>
    </div>
  </header>
  <?= $content ?>
  <footer class="pub-footer">
    <div class="in">
      <span class="secure"><?= icon('lock', 16) ?>Secured by Stripe</span>
      <p>Payments are processed securely by Stripe. We never see or store your card details.</p>
      <p class="fine">© <?= date('Y') ?> <?= e($s['business_name']) ?><?= $s['gst_number'] !== '' ? ' · GST no. ' . e($s['gst_number']) : '' ?></p>
    </div>
  </footer>
</div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
