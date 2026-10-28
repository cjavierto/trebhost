<?php $s = settings(); ?>
<main class="pub-main" style="max-width:560px;justify-content:center;padding-top:96px;padding-bottom:96px">
  <section class="card status-page">
    <div class="ring"><?= icon('unlink', 26) ?></div>
    <?php if (!empty($notFound)): ?>
      <h1>We couldn't find that page</h1>
      <p>The address may be mistyped. If you were sent a payment link, please use the exact link from your email.</p>
    <?php else: ?>
      <h1>This payment link is no longer valid</h1>
      <p>It may have expired or been replaced with a new one. Please get in touch and we'll send you an up-to-date link. Your services are not affected.</p>
    <?php endif; ?>
    <a class="btn" href="mailto:<?= e($s['billing_email']) ?>" style="margin-top:8px"><?= icon('mail') ?>Contact billing support</a>
  </section>
</main>
