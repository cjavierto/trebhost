<?php
$s = settings();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin sign in — <?= e($s['business_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<div class="center-page">
  <form class="card login-card" method="post" action="/admin/login">
    <?= csrf_field() ?>
    <div class="login-head">
      <div class="wm"><?= logo(32) ?><?= e($s['business_name']) ?></div>
      <div>
        <h1>Sign in to Admin</h1>
        <p>Manage clients, services and payment links.</p>
      </div>
    </div>

    <?php if (!empty($locked)): ?>
      <div class="banner error" role="alert"><div>Too many attempts. Please wait 15 minutes and try again.</div></div>
    <?php endif; ?>

    <div class="field">
      <label for="pw">Password</label>
      <input id="pw" name="password" type="password" autocomplete="current-password" required autofocus
        <?= !empty($error) ? 'aria-invalid="true" aria-describedby="pw-err"' : '' ?>>
      <?php if (!empty($error)): ?><span id="pw-err" class="error-text"><?= e($error) ?></span><?php endif; ?>
    </div>
    <button class="btn block" type="submit"<?= !empty($locked) ? ' disabled' : '' ?>>Sign in</button>
  </form>
  <span class="secured"><?= icon('lock', 14) ?>Secured by Stripe · © <?= date('Y') ?> <?= e($s['business_name']) ?></span>
</div>
</body>
</html>
