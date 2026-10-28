<?php
/** @var array $snap */
/** @var int $serviceCount */
$clients = $snap['clients'];
$n = count($clients);
?>
<div class="page-head">
  <div>
    <h1>Clients</h1>
    <p><?= $n ?> client<?= $n === 1 ? '' : 's' ?> · <?= (int)$serviceCount ?> service<?= $serviceCount === 1 ? '' : 's' ?></p>
  </div>
  <div class="toolbar">
    <div class="search">
      <?= icon('search') ?>
      <input type="search" id="client-search" aria-label="Search clients" placeholder="Search by name or email"
             data-filter-table="#clients-table" data-empty="#no-match">
    </div>
    <button type="button" class="btn" data-open-dialog="add-client"><?= icon('plus') ?>Add client</button>
  </div>
</div>

<section class="card" style="overflow:hidden">
  <?php if ($n === 0): ?>
    <div class="empty">No clients yet. Click <strong>Add client</strong> to create your first one.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data" id="clients-table">
        <thead>
          <tr>
            <th scope="col">Client</th>
            <th scope="col">Email</th>
            <th scope="col" style="text-align:right">Services</th>
            <th scope="col">Status</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c):
              $row = $snap['rows'][$c['id']];
              $url = '/admin/clients/' . $c['id'];
              $search = mb_strtolower(($c['name'] ?? '') . ' ' . ($c['email'] ?? ''));
          ?>
            <tr data-search="<?= e($search) ?>">
              <td><a class="name" href="<?= e($url) ?>"><?= e($c['name'] ?? '(no name)') ?></a></td>
              <td style="color:var(--text-2)"><?= e($c['email'] ?? '—') ?></td>
              <td class="num"><?= count($row['services']) ?></td>
              <td><?= badge($row['status']) ?></td>
              <td class="num"><a href="<?= e($url) ?>" style="font-weight:500;text-decoration:none">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="empty" id="no-match" hidden>No clients match your search.</div>
    <div class="table-foot">
      <span>Showing <?= $n ?> of <?= $n ?> client<?= $n === 1 ? '' : 's' ?></span>
      <span>Status reflects each client's most urgent service</span>
    </div>
  <?php endif; ?>
</section>

<dialog id="add-client" aria-labelledby="add-client-h">
  <form method="post" action="/admin/clients">
    <?= csrf_field() ?>
    <h2 id="add-client-h">Add client</h2>
    <div class="field">
      <label for="ac-name">Client or business name</label>
      <input id="ac-name" name="name" type="text" required maxlength="120">
    </div>
    <div class="field">
      <label for="ac-email">Billing email <span class="opt">(optional)</span></label>
      <input id="ac-email" name="email" type="email" maxlength="190">
      <span class="help">Stripe emails receipts here.</span>
    </div>
    <div class="actions">
      <button type="button" class="btn secondary" data-close-dialog>Cancel</button>
      <button type="submit" class="btn">Add client</button>
    </div>
  </form>
</dialog>
