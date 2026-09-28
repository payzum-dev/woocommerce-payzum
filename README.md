# Payzum for WooCommerce — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) in
[WooCommerce](https://woocommerce.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody. No chargebacks, no card networks, no PCI surface.

- **Plugin:** `payzum-crypto-payments` · **Version:** 1.5.0 · **License:** GPL-2.0-or-later
- **Requires:** WordPress ≥ 5.6, WooCommerce ≥ 5.0 (tested up to 11.0), PHP ≥ 8.1
- **Compatible with:** WooCommerce Blocks checkout and HPOS (High-Performance Order Storage)

## How it works

1. The buyer picks **Payzum** at checkout and is redirected to a hosted checkout
   page (QR code + deposit address, live status), where they choose the coin and
   chain and send the payment. No wallet or card data touches your server.
2. Crypto confirmation is **asynchronous**, so the order is marked paid from
   Payzum's signed server-to-server IPN webhook, never from the buyer's browser
   return — a closed tab never loses a paid order.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   Redelivered webhooks are deduplicated, so an order is never fulfilled twice.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — payments settle to the merchant's own wallet.
- **Hosted checkout** — no card fields, no crypto handling, no PCI scope.
- **Signed IPN webhooks** (HMAC-SHA-512) settle orders server-side.
- **Currency allowlist** — accept everything, or restrict the coins offered at
  checkout with a multi-select populated live from the Payzum API.
- **Production / staging selector** built into the settings screen.
- **Zero chargebacks** — crypto payments are final.

## Installation

**From the release zip (recommended).** Download
[`payzum-crypto-payments-1.5.0.zip`](https://github.com/payzum-dev/woocommerce-payzum/releases/latest), then in WordPress go to
**Plugins → Add New → Upload Plugin**, pick the zip and activate it. The archive unpacks to
`payzum-crypto-payments/`, the folder name WordPress expects.

**From a clone.** This repository *is* the plugin, so its contents must land in a folder named
`payzum-crypto-payments`:

```bash
git clone https://github.com/payzum-dev/woocommerce-payzum.git payzum-crypto-payments
mv payzum-crypto-payments /path/to/wp-content/plugins/
```

Do not drop the repository in as `woocommerce-payzum` — WordPress keys the plugin off the folder name.

The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php) SDK is
vendored, so no composer step is needed.

## Configuration

Go to **WooCommerce → Settings → Payments → Payzum**:

| Setting | Meaning |
|---|---|
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) |
| Webhook secret | Verifies incoming payment webhooks (IPN) |
| Accepted currencies | Optional multi-select; empty accepts every coin your merchant account allows |
| Environment | Production or staging (staging needs its own API key) |
| Debug log | Writes to **WooCommerce → Status → Logs**, source `payzum` |

The settings screen shows your store's **IPN URL**
(`https://<your-store>/wc-api/wc_gateway_payzum`) read-only — paste it into the
Payzum dashboard webhook settings. The signature header is fixed; nothing to
configure.

## Order status mapping

| Payzum payment status | WooCommerce order status |
|---|---|
| `finished` | Payment complete (processing/completed) |
| `partially_paid` | On hold (underpaid — the buyer sent less than the invoice) |
| `expired` | Cancelled |
| `failed` | Failed |
| anything else | Order note only, status unchanged |

## FAQ

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Which stablecoins and networks can my store accept?**
USDC and USDT on the major chains (Polygon, Ethereum, Arbitrum, Base, Optimism,
Tron, Solana, …), plus native assets. The exact list is your merchant
allowlist, configured in the Payzum dashboard and enforced server-side.

**Do buyers need an account?**
No. They scan a QR or copy a deposit address from the hosted checkout and pay
from any wallet.

**What about chargebacks?**
There are none — crypto payments are final, which eliminates chargeback fraud.

**Does it work with the WooCommerce block-based checkout?**
Yes, the gateway registers WooCommerce Blocks support, and it declares HPOS
compatibility.

**What data is shared with Payzum?**
Only the order total, currency, an order reference and your store's callback
URLs — no customer personal data. Endpoints: `https://merchant.payzum.com`
(production), `https://staging.payzum.com` (staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — Magento 2, PrestaShop, Shopware 6, OpenCart, Zen Cart, nopCommerce,
Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure, Saleor, Sylius, Easy Digital
Downloads, GiveWP, Paid Memberships Pro, WHMCS, Blesta, HostBill, ClientExec,
pretix, Frappe/ERPNext, Akaunting and django-payments — plus official SDKs for
PHP, Node.js/TypeScript, Python and Rust. Browse them all at
[github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[GPL-2.0-or-later](LICENSE). Contributed and maintained by Payzum.
