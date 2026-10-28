<?php /** @var string $message */ ?>
<div class="<?= isset($back) ? 'stack' : '' ?>" style="<?= isset($back) ? '' : 'max-width:560px;margin:96px auto;padding:0 20px' ?>">
  <section class="card status-page">
    <h1>Something needs attention</h1>
    <p><?= e($message) ?></p>
    <?php if (!empty($back)): ?>
      <a class="btn secondary" href="<?= e($back) ?>">Back to clients</a>
    <?php else: ?>
      <a class="btn secondary" href="javascript:history.back()">Go back</a>
    <?php endif; ?>
  </section>
</div>
