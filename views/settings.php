<?php
/** @var ?array $account */
/** @var array $webhook */
$s = settings();
$test = App\StripeApi::isTestMode();
$accId = $account['id'] ?? '';
$maskedId = $accId !== '' ? substr($accId, 0, 5) . '••••••••' . substr($accId, -4) : '—';
$accName = $account['business_profile']['name'] ?? ($account['settings']['dashboard']['display_name'] ?? $s['business_name']);
$hookAt = (int)($webhook['last_at'] ?? 0);
$hookUrl = base_url() . '/webhook/stripe';
?>
<div class="page-head">
  <div>
    <h1>Settings</h1>
    <p>Business details, Stripe connection, notifications and security</p>
  </div>
</div>

<section class="card card-pad" aria-labelledby="s-biz" style="gap:20px">
  <div class="card-head">
    <h2 id="s-biz">Business details</h2>
    <p>Shown on payment pages, receipts and invoices.</p>
  </div>
  <form method="post" action="/admin/settings/business" class="stack">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="b-name">Business name</label>
        <input id="b-name" name="business_name" type="text" value="<?= e($s['business_name']) ?>" required maxlength="80"></div>
      <div class="field"><label for="b-email">Billing email</label>
        <input id="b-email" name="billing_email" type="email" value="<?= e($s['billing_email']) ?>" required></div>
      <div class="field"><label for="b-gst">GST number <span class="opt">(optional)</span></label>
        <input id="b-gst" name="gst_number" type="text" value="<?= e($s['gst_number']) ?>" placeholder="[YOUR GST NUMBER]" maxlength="30"></div>
      <div class="field"><label for="b-currency">Currency</label>
        <select id="b-currency" disabled><option><?= e(cur()) ?></option></select></div>
    </div>
    <div class="field">
      <label for="b-footer">Payment page message</label>
      <textarea id="b-footer" name="footer_message" rows="3" maxlength="500"><?= e($s['footer_message']) ?></textarea>
      <span class="help">Appears under the services list on every client's payment page.</span>
    </div>
    <div class="actions-row"><button type="submit" class="btn">Save changes</button></div>
  </form>
</section>

<section class="card card-pad" aria-labelledby="s-stripe" style="gap:20px">
  <div class="card-head">
    <h2 id="s-stripe">Stripe</h2>
    <p>Card payments, subscriptions and invoices run through your Stripe account. API keys live in <code>config/.env</code> on the server, never in this screen.</p>
  </div>
  <div class="kv">
    <div class="left">
      <span class="mark" aria-hidden="true">S</span>
      <div class="t"><?= e($accName) ?><small><?= e($maskedId) ?></small></div>
    </div>
    <?= $account ? badge('connected') : badge('failed', 'Could not reach Stripe') ?>
  </div>
  <div class="two-col">
    <div class="mini">
      <span class="label">Mode</span>
      <span><?= badge($test ? 'pending' : 'active', $test ? 'Test mode' : 'Live mode') ?></span>
      <span class="help" style="font-size:13px;color:var(--muted)">Switch by changing <code>STRIPE_SECRET_KEY</code> in <code>config/.env</code>.</span>
    </div>
    <div class="mini">
      <span class="label">Webhook</span>
      <?php if ($hookAt): ?>
        <span><?= badge('active', 'Receiving events') ?> <span style="font-size:13px;color:var(--muted)">last <?= e(date('d/m/Y g:i a', $hookAt)) ?></span></span>
      <?php else: ?>
        <span><?= badge('pending', 'No events received yet') ?></span>
      <?php endif; ?>
      <code><?= e($hookUrl) ?></code>
      <span style="font-size:13px;color:var(--muted)">Add this URL in Stripe → Developers → Webhooks with the events <code>invoice.payment_failed</code> and <code>invoice.paid</code>.</span>
    </div>
  </div>
  <div class="actions-row" style="justify-content:flex-start">
    <a class="btn secondary" href="<?= e(dashboard_url()) ?>" target="_blank" rel="noopener">Open Stripe Dashboard<?= icon('external') ?></a>
  </div>
</section>

<section class="card card-pad" aria-labelledby="s-notif" style="gap:20px">
  <div class="card-head">
    <h2 id="s-notif">Notifications</h2>
    <p>Emails to you and your clients. Receipts are sent by Stripe.</p>
  </div>
  <form method="post" action="/admin/settings/notifications" class="stack">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="notify_fail_admin"<?= $s['notify_fail_admin'] ? ' checked' : '' ?>>
      <span class="t">Email me when a payment fails<small>Sent to <?= e($s['billing_email']) ?> with the client and reason.</small></span></label>
    <label class="check"><input type="checkbox" name="notify_success_admin"<?= $s['notify_success_admin'] ? ' checked' : '' ?>>
      <span class="t">Email me for every successful payment<small>Off by default — the Payments page has the full list.</small></span></label>
    <label class="check"><input type="checkbox" name="remind_yearly"<?= $s['remind_yearly'] ? ' checked' : '' ?>>
      <span class="t">Remind clients before yearly renewals<small>Sends their payment link 14 days before a domain or yearly service renews. Needs the daily cron job (see README).</small></span></label>
    <label class="check"><input type="checkbox" name="notify_fail_client"<?= $s['notify_fail_client'] ? ' checked' : '' ?>>
      <span class="t">Tell clients when a card is declined<small>Includes their payment link so they can pay or update their card.</small></span></label>
    <div class="actions-row"><button type="submit" class="btn">Save preferences</button></div>
  </form>
</section>

<section class="card card-pad" aria-labelledby="s-sec" style="gap:20px">
  <div class="card-head">
    <h2 id="s-sec">Security</h2>
    <p>Change the admin password. Use at least 12 characters.</p>
  </div>
  <form method="post" action="/admin/settings/password" class="stack">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="p-cur">Current password</label>
        <input id="p-cur" name="current" type="password" autocomplete="current-password" required></div>
      <div class="field"><label for="p-new">New password</label>
        <input id="p-new" name="new" type="password" autocomplete="new-password" minlength="12" required></div>
      <div class="field"><label for="p-conf">Confirm new password</label>
        <input id="p-conf" name="confirm" type="password" autocomplete="new-password" minlength="12" required></div>
    </div>
    <div class="actions-row">
      <button type="submit" class="btn">Update password</button>
    </div>
  </form>
  <form method="post" action="/admin/settings/sign-out-all" class="actions-row" data-confirm="Sign out of every device, including this one?" style="border-top:1px solid var(--border-soft);padding-top:20px">
    <?= csrf_field() ?>
    <button type="submit" class="btn secondary">Sign out of all devices</button>
  </form>
</section>
