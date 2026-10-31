<p align="center"><img src=".github/assets/lunixi-logo.svg" alt="Lunixi" width="220"></p>

# Lunixi Payments for OpenCart 4

Native payment extension **0.1.2** for **OpenCart 4.1.0.4**. Opens the existing Lunixi form on the merchant website by default, with optional redirect to the validated backend `paymentPageUrl`. Payment methods, eligible meal cards, shopping credit, installments, insurance and multi-card availability remain managed in the Lunixi merchant panel and form. This adapter does not collect card numbers/CVV or duplicate payment-method switches.

[Turkish guide](docs/README.tr.md) · [Documentation](https://docs.lunixi.com/en/sdks/opencart4) · [Source](https://github.com/Lunixi-Payment/lunixi-opencart4)

## Compatibility

| Component | Verified target |
|---|---|
| OpenCart | **4.1.0.4**, original native order model |
| PHP | 64-bit **8.3+**, within the platform's supported range |
| Extensions | `bcmath`, `curl`, `json`, `sodium`, `mysqli` |
| Database | MySQL/MariaDB, **all shop tables InnoDB**, one native connection |
| Shared payment core | Namespace-isolated `lunixi/php-sdk` **0.29.5** |
| Local native acceptance | PHP 8.4.1, MariaDB 11, official platform install, offline gateway transport |

Compatibility preflight rejects a different platform version, modified native order model, nontransactional tables, and unreviewed active order/history, total or fraud hooks. Default audited native activity/statistics hooks remain active; native fraud can hold fulfillment even after capture. Third-party themes/extensions require a separate compatibility review. Native recurring/subscription carts are unavailable because automatic recurring billing is not implemented by this extension. This source release does not imply OpenCart Marketplace acceptance or a live merchant certification.

## Merchant installation

1. Back up files, database and server encryption key; test on staging.
2. Verify all shop and extension tables use InnoDB; the extension never converts native tables.
3. Generate a separate encryption key locally: `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`. Configure `LUNIXI_OPENCART_ENCRYPTION_KEY` in PHP-FPM **and** the CLI worker environment, outside the web root/database. Back up this key separately.
4. Upload **`lunixi.ocmod.zip`** through **Extensions → Installer**, then install **Lunixi Payments** under **Extensions → Payments**. Do not rename the ZIP: OpenCart 4 uses its basename to create the extension/lunixi namespace. Merchants do not need Composer.
5. Give the intended administrator access/modify permission to `extension/lunixi/payment/lunixi`. Capture/refund/void also require modify permission to `sale/order`.
6. Edit Lunixi Payments; select the native store, enter merchant ID, key ID, Ed25519 private key and webhook secret. Secret fields stay blank on reload; blank values retain the encrypted store-specific credential profile. Never share credentials between stores.
7. Keep embedded checkout as the default, or explicitly choose optional hosted checkout, and select optional preauthorization. Installation creates separate localized awaiting/authorized/review statuses outside native processing/complete sets. Map **paid** to a processing/complete status; all other mappings must be outside those sets. Preauthorization and partial capture remain on hold.
8. Register the HTTPS webhook shown in the module, such as `https://shop.example/index.php?route=extension/lunixi/payment/lunixi.webhook`. Preserve raw request bytes and all `X-Lunixi-*` headers through the reverse proxy; allow retryable delivery.
9. Install the worker below, verify its logs/exit behavior, then enable the payment method. Configure eligible methods in the Lunixi panel.

Production gateway origin is fixed to `https://api-gateway.lunixi.com`. A trusted operator can select a different HTTPS origin only through `LUNIXI_GATEWAY_ORIGIN` and an exact JSON allowlist in `LUNIXI_ALLOWED_GATEWAY_ORIGINS`; there is no browser/admin URL input.

## Worker and payment operations

Run every minute in the native catalog context, with the same PHP extensions/environment/key as the website:

```sh
php /absolute/shop/extension/lunixi/system/library/lunixi4/cli.php --root=/absolute/shop --store-id=0
```

Use one invocation per native store. Protect cron logs; output contains counts or an opaque error. The web cron route is forbidden. The native admin screen queues capture/refund/void with a durable operation ID; it does **not** execute catalog order/stock code from an admin registry. Refresh operation status after the worker runs. `pending` or `uncertain` means wait/reconcile/retry the **same** ID and amount. The screen reports success only when authenticated settlement balances confirm the operation. A rejected overcapture/overrefund does not issue a financial API call. Refunds use actual captured balances; split refunds use the shared aggregate API.

The worker also reconciles durable attempts and delivers native notifications after commit. Mail is claimed before sending: interrupted `sending`/`uncertain` rows require operator review and are never blindly resent. Review queue counts and unresolved operations. No native emails are sent inside the payment transaction. Native history, stock, coupons, rewards and invoices use original platform behavior, with idempotent application on the same database connection.

## Checkout and security behavior

Only the fixed official `https://cdn.lunixi.com/lunixi-checkout.min.js` bundle renders the embedded form. Merchant signing/webhook secrets remain server-side. For a CSP nonce, a trusted server integration can set native configuration `lunixi_csp_nonce` for the current response; nonce values are escaped in scripts/styles. Allow the documented Lunixi frame/connect origins required by your deployed form.

Return query parameters and browser form events never mark an order paid. Session/order/store/customer ownership is checked before returning a token. Raw-body v2 HMAC, freshness and signed event identity are checked before authenticated payment GET. The shared core verifies payment, merchant, immutable order fingerprint, currency, exact amount, fees and split-parent completion. An incomplete multi-card aggregate or partial capture remains on hold. An installment fee is recorded separately from the basket, never added twice. Changed native totals/currency cannot be paid by an older attempt, including rate-limited browser polls.

A lost create response is recoverable only through a verified payment webhook plus authenticated GET echoing the original opaque reservation hash/fingerprint. A bound payment ID is never replaced. Unique durable inbox records and row locks prevent concurrent/replayed events from deducting stock twice; transaction failures roll back inbox and native changes together. Secrets are encrypted with a separate server key. Old credential profiles and the server key must remain usable through the reconciliation/refund retention period; changing profile settings does not delete old credentials. Coordinate gateway key revocation accordingly.

Uninstall disables the method and preserves payment history, encrypted profiles, operation IDs, event inbox, notification outbox and custom statuses. Back up and explicitly plan any later data removal.

## Build and verify

```sh
composer install --working-dir=tools --no-interaction
php tools/format.php --check
php tools/build.php
php tools/check-artifact.php
composer audit --working-dir=tools
```

The build pins SDK 0.29.5 and bundles a scoped copy. If testing an unreleased public SDK source checkout, provide the explicit `SDK_SOURCE=/absolute/public-sdk-checkout` environment variable; this path is not copied into public artifacts. `php-scoper` and Composer are build tools only. The resulting merchant ZIP is in `dist/`.

[Native acceptance instructions](docs/TESTING.md) install the exact official platform in an isolated schema and test the **built ZIP**, real native models/events/stock and a synthetic external gateway. No production DB or live payment key is used. PHPStan level 5 checks native glue against actual platform classes; the shared core has its own independent suite. CI pins action commits and the platform source SHA.

License: GPL-3.0-or-later for this native extension; bundled SDK retains its MIT license. [Security policy](SECURITY.md).

Financial outcomes that remain uncertain are held for review. `FAILED` alone does not authorize a new attempt: the public payment read does not expose definitive provider outcome certainty. Unresolved financial retries stop after 23 hours; authenticated GET reconciliation remains available. A timeout, token expiry or browser callback never generates a fresh financial key.

## Provider browser return

Lunixi hosted, split and 3DS flows return by **POST** to the generated `returned` route. This route alone accepts an informational cross-site POST without a session/CSRF token: it ignores the complete form body and immediately sends **303** to a relative same-origin GET of that route, keeping only the numeric `order_id` from the original callback URL. It does not fetch a payment, disclose order data or change financial state. The GET retains native session/customer/store/order ownership and authenticated payment reconciliation. Checkout/admin POST protections remain required; browser success fields never prove payment.

The application rejects declared callback bodies above **64 KiB**. Configure the HTTPS reverse proxy to enforce the same wire-body limit on this route, including chunked requests; keep the signed webhook's separate 1 MiB limit. Do not convert the callback to a checkout/confirm POST or remove ownership checks from GET.

## Checkout mode and existing attempts

Embedded checkout is the default; optional hosted checkout uses the exact validated `paymentPageUrl` returned by the backend. Both modes open the same Lunixi form and support the methods, multi-card, installments and insurance enabled for the merchant. None of these features requires redirecting. Never derive a payment-page URL from the browser token.

Existing explicit hosted settings are retained. Checkout mode, callback, preauthorization and locale are bound to an attempt's original request. Changing them cannot reset its request hash or idempotency key. If an older attempt has no stored backend payment-page URL, optional redirect fails closed: reconcile that attempt rather than constructing a URL, deleting history or creating a replacement key. Embedded rendering still uses the protected browser configuration. Keep the signed webhook, authenticated settlement checks and informational 3DS/split return routes.
