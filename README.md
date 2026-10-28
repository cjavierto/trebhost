# TrebHost billing portal (PHP)

Clients pay hosting and domain renewals by card through a branded page. You manage clients, services and payments from an admin area. Stripe does the billing: clients are Stripe Customers, services are recurring Prices, payments are Subscriptions. **No database and no Composer needed.** Upload the folder, add your keys, done.

- **Admin:** `https://pay.trebhost.co.nz/admin` (Clients, Payments, Settings)
- **Client pay link:** `https://pay.trebhost.co.nz/c/<unique link>` (copy it from each client's page)

Requires PHP 8.1+ with the `curl` and `mbstring` extensions (both are on by default at Namecheap).

---

## 1. Stripe (do this first, in Test mode)

1. Stripe Dashboard → turn on **Test mode**. Developers → API keys → copy the **Secret key** (`sk_test_...`).
2. Settings → Billing → **Customer portal** → Activate. (This powers "Update card or view invoices".)
3. Settings → Emails → turn on successful-payment receipts and failed-payment emails.

## 2. Put it on Namecheap

1. **cPanel → Domains → Create A New Domain** (or Subdomains): `pay.trebhost.co.nz`. Untick "Share document root" and set the **Document Root to `pay-portal/public`**. This keeps your keys and code out of the public web folder.
2. **cPanel → File Manager** (or FTP): upload the contents of this folder into `/home/YOURUSER/pay-portal/` so you end up with `pay-portal/public`, `pay-portal/src`, `pay-portal/config`, etc.
3. **cPanel → Select PHP Version**: choose 8.1 or newer for this domain.
4. In `pay-portal/config/`, copy `.env.example` to `.env` and fill it in:
   - `STRIPE_SECRET_KEY` = your `sk_test_...` key
   - `ADMIN_PASSWORD` = your first admin password
   - `APP_SECRET` = a random string, 32+ characters (e.g. from a password generator). **Don't change it later** or links you've already sent stop working.
   - `APP_URL` = `https://pay.trebhost.co.nz`
5. Make sure `pay-portal/storage` is writable (permissions 755, or 775 if saving settings fails).
6. **cPanel → SSL/TLS Status**: run AutoSSL so the subdomain has HTTPS.
7. Open `https://pay.trebhost.co.nz/admin` and sign in.

## 3. Test it

1. **Clients → Add client**, then add a service (e.g. Website hosting, $49, Monthly).
2. Click **Preview** on the payment link and pay with Stripe's test card `4242 4242 4242 4242` (any future expiry, any CVC, any postcode).
3. Back in Admin, the service shows **Active** with its next charge date, and the payment appears under **Payments**.

## 4. Webhook (emails for failed payments)

1. Stripe → Developers → Webhooks → **Add endpoint**: `https://pay.trebhost.co.nz/webhook/stripe`
2. Events: `invoice.payment_failed` and `invoice.paid`.
3. Copy the **Signing secret** (`whsec_...`) into `STRIPE_WEBHOOK_SECRET` in `config/.env`.
4. Admin → Settings should now show **Receiving events** after Stripe sends one (use "Send test webhook" in Stripe).

## 5. Yearly renewal reminders (optional)

cPanel → **Cron Jobs** → add once a day:

```
/usr/local/bin/php /home/YOURUSER/pay-portal/cron/reminders.php
```

(Use the PHP path cPanel shows on the Cron Jobs page if it differs.) This emails clients their link 14 days before a yearly service renews. Turn it off in Settings → Notifications.

## 6. Go live

1. Stripe: switch off Test mode, copy the **live** secret key (`sk_live_...`), activate the Customer portal in live mode too, and add the webhook endpoint again in live mode (new `whsec_...`).
2. Update `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` in `config/.env`.
3. Test and live data are separate in Stripe, so **re-add your clients and services** in live mode.
4. Do one real payment on your own card first (and refund it in Stripe).

---

## How it behaves

| Thing | What happens |
| --- | --- |
| Pay now | Opens Stripe Checkout. After paying, the client lands back on their page with a success message. Stripe then charges them automatically every month or year. |
| First charge date | Optional per service. The client's card is saved when they check out and the first charge happens on that date. Stripe allows up to one billing period ahead (1 month for monthly, 1 year for yearly). |
| Failed payment | Shown as "Payment failed". The client's Pay now button opens the Stripe invoice. Stripe retries automatically; you and the client get an email (if enabled). |
| Remove service | If it's already being billed, future charges stop at the end of the current period. |
| Generate new link | Creates a new link and disables the old one immediately. |
| Change password | Settings → Security. Stored hashed; other devices are signed out. |

## Notes

- **Back up `storage/`**: it holds your Settings, the changed admin password and webhook state. Everything else lives in Stripe.
- **Emails** are sent with PHP `mail()` from your billing email. For best delivery, make sure that mailbox exists on your domain and SPF/DKIM are on in cPanel (Email Deliverability).
- **Prices** are NZD and entered GST-inclusive. This app doesn't calculate GST for you.
- **Stripe API version** is pinned in the code (`2024-06-20`), so Stripe account upgrades can't change behaviour.
- Errors are logged to `storage/error.log`. Set `APP_DEBUG=1` in `.env` temporarily if you need to see them on screen (turn off again after).
