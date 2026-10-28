<?php
/** @var array $range */
/** @var array[] $rows */
$counts = ['paid' => 0, 'failed' => 0, 'refunded' => 0, 'pending' => 0];
foreach ($rows as $r) {
    $counts[$r['status']]++;
}
$labels = ['paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded', 'pending' => 'Pending'];
$collectedTitle = $range['month'] ? 'Collected in ' . $range['month'] : 'Collected · ' . $range['label'];
?>
<div class="page-head">
  <div>
    <h1>Payments</h1>
    <p>Card payments from all clients, synced from Stripe</p>
  </div>
  <div class="toolbar">
    <a class="btn secondary" href="<?= e(dashboard_url('/payments')) ?>" target="_blank" rel="noopener">Open Stripe<?= icon('external') ?></a>
    <a class="btn secondary" href="/admin/payments.csv?range=<?= e($range['key']) ?>"><?= icon('download') ?>Export CSV</a>
  </div>
</div>

<div class="stats">
  <section class="card stat">
    <h2><?= e($collectedTitle) ?></h2>
    <span class="big"><?= e(money($collected)) ?></span>
    <span class="small"><?= (int)$collectedCount ?> successful payment<?= $collectedCount === 1 ? '' : 's' ?></span>
  </section>
  <section class="card stat">
    <h2>Outstanding</h2>
    <span class="big"><?= e(money($outstanding)) ?></span>
    <span class="small<?= $outstandingClients ? ' warn' : '' ?>"><?= (int)$outstandingClients ?> client<?= $outstandingClients === 1 ? '' : 's' ?> awaiting payment</span>
  </section>
  <section class="card stat">
    <h2><?= $range['month'] ? 'Failed this month' : 'Failed · ' . e($range['label']) ?></h2>
    <span class="big"><?= (int)$failedCount ?></span>
    <span class="small<?= $failedCount ? ' err' : '' ?>"><?= e(money($failedAmount)) ?><?= $nextRetry ? ' · Stripe retries on ' . e(dmy($nextRetry)) : ' not collected' ?></span>
  </section>
</div>

<section class="card" style="overflow:hidden">
  <div class="filters">
    <div class="pills" role="group" aria-label="Filter by status">
      <button type="button" data-status-filter="all" aria-pressed="true">All <span><?= count($rows) ?></span></button>
      <button type="button" data-status-filter="paid" aria-pressed="false">Succeeded</button>
      <button type="button" data-status-filter="failed" aria-pressed="false">Failed</button>
      <button type="button" data-status-filter="refunded" aria-pressed="false">Refunded</button>
    </div>
    <div class="toolbar">
      <div class="search">
        <?= icon('search') ?>
        <input type="search" aria-label="Search payments" placeholder="Search client or service" style="width:240px"
               data-filter-table="#payments-table" data-empty="#no-pay-match">
      </div>
      <label for="range" class="sr-only">Date range</label>
      <select id="range" style="width:auto">
        <?php foreach (App\Payments::rangeOptions() as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $key === $range['key'] ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty" style="border-top:1px solid var(--border)">No payments in this period yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data wide" id="payments-table">
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Client</th>
            <th scope="col">Service</th>
            <th scope="col" style="text-align:right">Amount</th>
            <th scope="col">Status</th>
            <th scope="col">Card</th>
            <th scope="col"><span class="sr-only">Receipt</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr data-status="<?= e($r['status']) ?>" data-search="<?= e(mb_strtolower($r['client'] . ' ' . $r['service'])) ?>">
            <td style="font-variant-numeric:tabular-nums;white-space:nowrap"><?= e(dmy($r['created'])) ?></td>
            <td><a class="name" href="/admin/clients/<?= e($r['customer_id']) ?>"><?= e($r['client']) ?></a></td>
            <td>
              <?= e($r['service']) ?>
              <?php if ($r['detail'] !== ''): ?><span class="sub"><?= e($r['detail']) ?></span><?php endif; ?>
              <?php if ($r['note'] !== ''): ?><span class="sub<?= $r['status'] === 'failed' ? ' err' : '' ?>"><?= e($r['note']) ?></span><?php endif; ?>
            </td>
            <td class="num strong"><?= e(money($r['amount'])) ?></td>
            <td><?= badge($r['status'], $labels[$r['status']]) ?></td>
            <td style="white-space:nowrap;color:var(--text-2)"><?= e($r['card']) ?></td>
            <td class="num">
              <?php if ($r['receipt']): ?>
                <a href="<?= e($r['receipt']) ?>" target="_blank" rel="noopener" style="font-weight:500;text-decoration:none">Receipt</a>
              <?php else: ?>
                <a href="/admin/clients/<?= e($r['customer_id']) ?>" style="font-weight:500;text-decoration:none">View client</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="empty" id="no-pay-match" hidden>No payments match.</div>
  <?php endif; ?>
  <div class="table-foot">
    <span>Showing <?= count($rows) ?> payment<?= count($rows) === 1 ? '' : 's' ?> · <?= e($range['label']) ?></span>
    <span>Amounts in <?= e(cur()) ?>, incl. GST</span>
  </div>
</section>
