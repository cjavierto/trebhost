<?php
/** @var array $client */
/** @var array $rows */
/** @var int $due */
/** @var string $payUrl */
$base = '/admin/clients/' . $client['id'];
$count = count($rows);
$linkAt = (int)($client['metadata']['link_at'] ?? $client['created'] ?? 0);
?>
<div class="stack" style="gap:16px">
  <nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="/admin">Clients</a><span aria-hidden="true">/</span><span aria-current="page"><?= e($client['name'] ?? '') ?></span>
  </nav>
  <div class="client-head">
    <div class="client-id">
      <span class="mark" aria-hidden="true"><?= e(initials((string)($client['name'] ?? ''))) ?></span>
      <div style="min-width:0">
        <h1><?= e($client['name'] ?? '') ?></h1>
        <?php if (!empty($client['email'])): ?>
          <a href="mailto:<?= e($client['email']) ?>"><?= e($client['email']) ?></a>
        <?php else: ?>
          <span style="font-size:14px;color:var(--muted)">No billing email yet</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="toolbar">
      <a class="btn secondary" href="<?= e(dashboard_url('/customers/' . $client['id'])) ?>" target="_blank" rel="noopener">View in Stripe<?= icon('external') ?></a>
      <button type="button" class="btn secondary" data-open-dialog="edit-client">Edit client</button>
    </div>
  </div>
</div>

<section class="card card-pad" aria-labelledby="link-h">
  <div class="card-head">
    <h2 id="link-h">Payment link</h2>
    <p>Send this link to your client. It always shows their current services and anything due.</p>
  </div>
  <div class="linkbox">
    <label for="paylink" class="sr-only">Payment link</label>
    <input id="paylink" type="text" readonly value="<?= e($payUrl) ?>">
    <button type="button" class="btn" data-copy="#paylink"><?= icon('copy') ?><span class="lbl">Copy link</span></button>
    <a class="btn secondary" href="<?= e($payUrl) ?>" target="_blank" rel="noopener">Preview<?= icon('external') ?></a>
  </div>
  <div class="meta-line">
    <span>Created <?= e(dmy($linkAt)) ?></span>
    <span aria-hidden="true">·</span>
    <form method="post" action="<?= e($base) ?>/link" data-confirm="Create a new link? The current link will stop working straight away.">
      <?= csrf_field() ?>
      <button type="submit" class="link-btn" style="font-size:13px;min-height:24px">Generate new link</button>
    </form>
    <span>— the current link will stop working.</span>
  </div>
</section>

<section class="card" style="overflow:hidden" aria-labelledby="svc-h">
  <div class="card-bar">
    <h2 id="svc-h">Services</h2>
    <span><?= $count ?> service<?= $count === 1 ? '' : 's' ?><?= $due > 0 ? ' · ' . e(money($due)) . ' due' : '' ?></span>
  </div>
  <?php if ($count === 0): ?>
    <div class="empty" style="border-top:1px solid var(--border)">No services yet. Add the first one below.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th scope="col">Service</th>
            <th scope="col" style="text-align:right">Price</th>
            <th scope="col">Status</th>
            <th scope="col">Next charge</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $svc = $r['svc']; $sub = $r['sub'];
            if ($r['state'] === 'active') {
                $next = $sub && $sub['period_end'] ? ($sub['cancel_at_period_end'] ? 'Ends ' : '') . dmy($sub['period_end']) : '—';
            } elseif ($r['state'] === 'failed') {
                $next = !empty($sub['retry_at']) ? 'Retry ' . dmy($sub['retry_at']) : '—';
            } else {
                $next = $svc['anchor'] ? 'First charge ' . dmy($svc['anchor']) : '—';
            }
        ?>
          <tr>
            <td>
              <span class="strong" style="display:block"><?= e($svc['name']) ?></span>
              <?php if ($svc['description'] !== ''): ?><span class="sub"><?= e($svc['description']) ?></span><?php endif; ?>
            </td>
            <td class="num"><span class="strong"><?= e(money($svc['amount'])) ?></span><span style="color:var(--muted)"> / <?= $svc['interval'] ?></span></td>
            <td><?= badge($r['state']) ?></td>
            <td style="font-variant-numeric:tabular-nums"><?= e($next) ?></td>
            <td class="num">
              <form method="post" action="<?= e($base) ?>/services/remove"
                    data-confirm="<?= e($r['state'] === 'due' ? 'Remove ' . $svc['name'] . '?' : 'Remove ' . $svc['name'] . '? It is already being billed. Future charges stop at the end of the current period.') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="price_id" value="<?= e($svc['id']) ?>">
                <button type="submit" class="link-btn danger" aria-label="Remove <?= e($svc['name']) ?>">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card card-pad" style="gap:20px" aria-labelledby="add-h">
  <div class="card-head">
    <h2 id="add-h">Add service</h2>
    <p>It appears on the client's payment page straight away.</p>
  </div>
  <form method="post" action="<?= e($base) ?>/services" class="stack">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field">
        <label for="svc-name">Service name</label>
        <input id="svc-name" name="name" type="text" placeholder="e.g. Website hosting" required maxlength="100">
      </div>
      <div class="field">
        <label for="svc-desc">Description <span class="opt">(optional)</span></label>
        <input id="svc-desc" name="description" type="text" placeholder="e.g. Business plan · example.co.nz" maxlength="140">
      </div>
      <div class="field">
        <label for="svc-price">Price (<?= e(cur()) ?>, incl. GST)</label>
        <div class="input-money"><span aria-hidden="true">$</span><input id="svc-price" name="price" type="text" inputmode="decimal" placeholder="0.00" required></div>
      </div>
      <fieldset class="field" style="margin:0;padding:0;border:0">
        <legend class="label" style="margin-bottom:8px">Billing cycle</legend>
        <div class="seg">
          <label><input type="radio" name="cycle" value="monthly" checked>Monthly</label>
          <label><input type="radio" name="cycle" value="yearly">Yearly</label>
        </div>
      </fieldset>
      <div class="field">
        <label for="svc-date">First charge date <span class="opt">(optional)</span></label>
        <input id="svc-date" name="first_charge" type="text" placeholder="DD/MM/YYYY" inputmode="numeric" pattern="\d{1,2}/\d{1,2}/\d{4}">
      </div>
    </div>
    <div class="form-actions">
      <span class="note">Leave the date blank to charge when the client first pays.</span>
      <button type="submit" class="btn"><?= icon('plus') ?>Add service</button>
    </div>
  </form>
</section>

<dialog id="edit-client" aria-labelledby="edit-client-h">
  <form method="post" action="<?= e($base) ?>/edit">
    <?= csrf_field() ?>
    <h2 id="edit-client-h">Edit client</h2>
    <div class="field">
      <label for="ec-name">Client or business name</label>
      <input id="ec-name" name="name" type="text" required maxlength="120" value="<?= e($client['name'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="ec-email">Billing email</label>
      <input id="ec-email" name="email" type="email" maxlength="190" value="<?= e($client['email'] ?? '') ?>">
    </div>
    <div class="actions">
      <button type="button" class="btn secondary" data-close-dialog>Cancel</button>
      <button type="submit" class="btn">Save</button>
    </div>
  </form>
</dialog>
