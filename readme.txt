=== Payzum Crypto & Stablecoin Payments for WooCommerce ===
Contributors: payzum
Tags: woocommerce, cryptocurrency, stablecoin, usdc, payment gateway
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto and stablecoins (USDC/USDT, multi-chain) in WooCommerce with Payzum. Non-custodial — funds settle to your own wallet.

== Description ==

Payzum lets your WooCommerce store accept cryptocurrency and stablecoin payments (USDC/USDT and
more, across multiple chains). Payments are **non-custodial**: funds settle directly to your own
wallet — Payzum never takes custody.

At checkout the buyer is redirected to a secure Payzum hosted checkout (QR + deposit address, live
status). Your order is marked paid automatically from a signed IPN webhook, so a closed browser tab
never loses a paid order.

Features:

* Crypto & stablecoin checkout (USDC/USDT, multi-chain).
* Non-custodial — settles to your wallet.
* Hosted checkout — no card data or crypto handling on your server.
* Signed IPN webhooks (HMAC-SHA-512) verify every payment server-side.
* No chargebacks.

== Installation ==

1. Upload the `payzum-crypto-payments` folder to `/wp-content/plugins/`, or install the zip via
   Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to WooCommerce → Settings → Payments → Payzum and enable it.
4. Paste your **API key** and **Webhook secret** from your Payzum dashboard.
5. Copy the **IPN URL** shown on the settings screen into your Payzum webhook settings. The IPN
   signature header is fixed (`x-nowpayments-sig`) — no configuration needed.
6. Save. Place a test order to confirm the flow.

== External services ==

This plugin connects to the Payzum API to create payment invoices and receive payment
notifications. It is required for the gateway to work.

* What it sends: when a buyer chooses Payzum at checkout, the plugin sends the order total,
  currency, order id and your store's callback/return URLs to Payzum to create the invoice.
  Payment confirmations arrive as signed webhooks from Payzum; the plugin verifies their
  signature before updating the order. No customer personal data is sent by the plugin.
* When: only when the gateway is enabled and a buyer pays (or the store owner tests the
  connection from the settings screen).
* Endpoints: `https://merchant.payzum.com` (production) or `https://staging.payzum.com`
  (staging), as selected in the plugin settings.
* Service provider: Payzum — [terms](https://payzum.com/terms), [privacy](https://payzum.com/privacy).

== Frequently Asked Questions ==

= Is it custodial? =
No. Funds settle directly to your own wallet.

= Which coins are supported? =
USDC/USDT and other crypto across supported chains. Which coins your store accepts is configured
in your Payzum dashboard (Merchants → Settings → Accepted tokens); the buyer picks one of those
on the Payzum checkout.

= Do I need to write code? =
No. Enter your API key and webhook secret and you are live.

== Credits ==

Coin icons are from the open-source cryptocurrency-icons set (MIT license, spothq);
a few network icons are original monograms bundled with this plugin.

== Changelog ==

= 1.5.1 =
* Plugin URI now points at the plugin's own repository, so it differs from the Author URI as
  the plugin directory requires. No functional change.

= 1.5.0 =
* The IPN now verifies the amount and currency paid against the order before it is completed.
  Nothing was compared before, so a payment for any amount completed the order in full; a mismatch
  now puts the order on hold for review instead.
* An amount that cannot be read (missing, empty, or not a number) counts as a mismatch. The first
  version of this check treated an unreadable amount as "nothing to compare" and completed the
  order unverified.
* A payment reference whose order suffix does not match the order it claims to pay is rejected.
* The transition to complete is serialised with a database lock, so two deliveries arriving at once
  cannot both complete the order.
* Lock names are scoped to the site's database, table prefix and plugin. Lock names are global to
  the MySQL server, so the previous ones collided: two stores on one server blocked each other, and
  this plugin generated the same name as the Easy Digital Downloads and Paid Memberships Pro
  gateways.

= 1.4.0 =
* Rebuilt on the official `payzum/payzum-php` SDK (bundled in `vendor/`): HTTP client, webhook
  signature verification, decimal-exact amounts and the payment-status vocabulary now live in one
  tested library instead of plugin code.
* IPN deliveries are deduplicated by their event id — retries can no longer add duplicate notes or
  re-fire the paid transition.
* New Environment setting (production / staging) for end-to-end testing against the sandbox.
* Order totals are sent with every digit intact (no float rounding on the wire).
* Requires PHP 8.1 (the SDK's floor).

= 1.3.0 =
* Cart & Checkout blocks support; HPOS compatibility declared.

= 1.2.0 =
* Modal and inline render modes via the Payzum widget; crypto currency mode
  (`pricing_mode: "direct"`).

= 1.1.0 =
* The buyer picks the coin on the Payzum hosted checkout (`pay_currency: "all"`); dropped the
  in-store coin picker.
* IPN signature read from the fixed `x-nowpayments-sig` header.
* Zero-total orders complete without an invoice; repeated IPNs no longer duplicate order notes;
  recurring signups are refused instead of being charged once.

= 1.0.0 =
* Initial release: hosted-checkout gateway with signed IPN verification.
