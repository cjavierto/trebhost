<?php
/** @var array $client */
/** @var string $token */
/** @var array $rows */
/** @var int $due */
/** @var bool $hasPortal */
/** @var ?array $banner */
$s = settings();
$n = count($rows);
$pay = '/c/' . $token;
?>
<main class="pub-main">
  <div class="intro">
    <p class="eyebrow">Billing</p>
    <h1>Hosting &amp; domain renewals for <?= e($client['name'] ?? '') ?></h1>
    <p class="lede">Review your services and pay any outstanding renewals by card.<?= !empty($client['email']) ? ' Receipts are emailed to ' . e($client['email']) . '.' : '' ?></p>
  </div>

  <?php if ($banner): ?>
    <div class="banner success" role="status">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="flex:0 0 auto;margin-top:1px"><circle cx="12" cy="12" r="10" fill="#16A34A"></circle><path d="M7.5 12.5l3 3 6-6.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
      <div><strong>Payment successful — thank you</strong><?= e($banner['text']) ?></div>
    </div>
  <?php endif; ?>

  <section class="card svc-card" aria-labelledby="svc-h">
    <div class="svc-top">
      <div>
        <h2 id="svc-h">Your services</h2>
        <p><?= $n ?> service<?= $n === 1 ? '' : 's' ?> · prices in <?= e(cur()) ?>, incl. GST</p>
      </div>
      <?php if ($n > 0 && $due > 0): ?>
        <div class="due"><span>Amount due</span><span><?= e(money($due)) ?></span></div>
      <?php elseif ($n > 0): ?>
        <span class="paid-up"><?= icon('check', 18) ?>You're all paid up</span>
      <?php endif; ?>
    </div>

    <?php if ($n === 0): ?>
      <div class="empty" style="border-top:1px solid var(--border)">There's nothing to pay right now.</div>
    <?php else: ?>
    <ul class="svc-list">
      <?php foreach ($rows as $r):
          $svc = $r['svc']; $sub = $r['sub']; $state = $r['state'];
      ?>
        <li class="svc">
          <div class="info">
            <span class="n"><?= e($svc['name']) ?></span>
            <?php if ($svc['description'] !== ''): ?><span class="d"><?= e($svc['description']) ?></span><?php endif; ?>
            <?php if ($state === 'failed'): ?>
              <span class="e">Your last payment didn't go through. Please pay to keep this service running.</span>
            <?php endif; ?>
          </div>
          <div class="price">
            <b><?= e(money($svc['amount'])) ?></b>
            <span>per <?= $svc['interval'] ?></span>
          </div>
          <div class="act">
            <?= badge($state) ?>
            <?php if ($state === 'active'): ?>
              <?php if ($sub && $sub['period_end']): ?>
                <span class="when"><?= $sub['cancel_at_period_end'] ? 'Ends ' : 'Next charge ' ?><?= e(dmy($sub['period_end'])) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <form method="post" action="<?= e($pay) ?>/checkout" style="width:100%">
                <input type="hidden" name="price_id" value="<?= e($svc['id']) ?>">
                <button type="submit" class="btn block" aria-label="<?= e(($svc['anchor'] && $state === 'due' ? 'Set up payment' : 'Pay now') . ' for ' . $svc['name'] . ', ' . money($svc['amount'])) ?>"><?= $svc['anchor'] && $state === 'due' ? 'Set up payment' : 'Pay now' ?></button>
              </form>
              <?php if ($state === 'due' && $svc['anchor']): ?>
                <span class="when">First charge <?= e(dmy($svc['anchor'])) ?></span>
              <?php elseif ($state === 'failed' && !empty($sub['retry_at'])): ?>
                <span class="when">Next attempt <?= e(dmy($sub['retry_at'])) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($s['footer_message'] !== ''): ?>
      <div class="note-card" style="border-top:1px solid var(--border)"><?= nl2br(e($s['footer_message'])) ?></div>
    <?php endif; ?>
  </section>

  <?php if ($hasPortal): ?>
  <section class="card portal-card">
    <div class="t">
      <h2>Payment details &amp; invoices</h2>
      <p>Change the card we charge automatically, or download past invoices and receipts.</p>
    </div>
    <form method="post" action="<?= e($pay) ?>/portal">
      <button type="submit" class="btn secondary">Update card or view invoices<?= icon('external') ?></button>
    </form>
  </section>
  <?php endif; ?>
</main>
