# Changelog

## 0.1.3 — 2026-10-31

- Bundle published PHP SDK 0.29.6, including the PHP 8.5 cURL handle-lifecycle compatibility correction.
- Preserve embedded checkout defaults, exact backend `paymentPageUrl` forwarding, stored settings and financial/idempotency behavior.
- Rebuild the standalone scoped merchant package; merchants still do not need Composer.

## 0.1.2 — 2026-10-31

- Make the existing on-site Lunixi form the documented default; retain explicitly selected optional hosted checkout.
- Use PHP SDK 0.29.5 to consume the validated backend `paymentPageUrl` exactly, with encrypted persistence and no token-to-URL reconstruction.
- Preserve existing checkout request hashes, idempotency keys, mode settings and informational 3DS/split returns; missing legacy hosted URLs require reconciliation.

## 0.1.1 — 2026-10-31

- Accept the gateway informational POST return through a bounded, stateless same-origin 303 adapter. Ignore submitted payment fields and require native ownership on the resumed GET.
- Verify fresh native activation and clean CI environments.

## 0.1.0 — 2026-10-31

- Native OpenCart 4.1.0.4 embedded/hosted Lunixi form and preauthorization.
- Scoped PHP SDK0.29.3, encrypted per-store credential profiles and exact integer money.
- Transactional inbox/native stock/history application, signed recovery and post-commit outbox.
- Durable capture/refund/void queue, authenticated confirmation and catalog CLI reconciliation.
- EN/TR admin/checkout strings, original Lunixi branding, RTL-aware spacing and CSP nonce support.
- Strict platform/hook/InnoDB compatibility gate and standalone artifact/native acceptance checks.
