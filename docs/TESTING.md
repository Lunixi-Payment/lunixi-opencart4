# Native acceptance

**Historical verified merchant ZIP 0.1.3:** [`lunixi.ocmod.zip`](https://github.com/Lunixi-Payment/lunixi-opencart4/releases/download/v0.1.3/lunixi.ocmod.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-opencart4/releases/download/v0.1.3/SHA256SUMS) · [Release v0.1.3](https://github.com/Lunixi-Payment/lunixi-opencart4/releases/tag/v0.1.3) · [Passing release CI](https://github.com/Lunixi-Payment/lunixi-opencart4/actions/runs/41242057536).

Public ZIP SHA-256: `e111ca6469cc646a7ef8450c1b3c99d75d425733a1dbc8cbf4ae1ab707972b43`. The downloaded archive passed public checksum, GitHub asset digest and ZIP CRC checks on 1 November 2026.

The tests invoke original OpenCart 4.1.0.4 models, loader, fraud and event dispatcher against a real isolated MariaDB schema. Only the external gateway HTTP boundary is synthetic. They exercise the **built merchant ZIP**, not another SDK autoload tree. The official platform source is pinned to `3b0597ec91cae3de040a63fee3834a6d910372ea`.

Provision a disposable database named exactly `lunixi_oc4` and a least-privileged user. Put host, port, database, username and password in an external chmod-600 JSON file. Never use production credentials. Set these task-specific environment variables (paths are examples):

```sh
export LUNIXI_NATIVE_TEST_DESTRUCTIVE=1
export LUNIXI_TEST_DB=/absolute/private/acceptance-db.json
export OPENCART_SOURCE=/absolute/pinned-opencart-source
export LUNIXI_TEST_SITE=/absolute/fresh-disposable-site
composer install --working-dir=tools --no-interaction
php tools/format.php --check
php tools/build.php
php tools/check-artifact.php
php tests/install-native.php
php tests/deploy-native.php
php tests/prepare-fraud.php
php tools/vendor/bin/phpunit --bootstrap tests/native_bootstrap.php tests/NativeAcceptanceTest.php
php tests/admin-native.php
php tests/cli-native.php
php tests/return-http.php
php tools/vendor/bin/phpstan analyse --no-progress
composer audit --working-dir=tools
```

Official source checkouts need their own `composer install --no-dev` before fixture installation if platform vendor files are absent. Build-time `SDK_SOURCE` may point to a separately checked-out public PHP SDK1.0.1; this never becomes a runtime dependency path.

The destructive opt-in permits installation and native test-only engine conversion in the dedicated schema. It is not a production migration command. The fixture installer passes random synthetic credentials in PHP memory to the official installer; it does not put DB passwords in a shell command. Native acceptance includes exact persisted decimal FX/ISO precision, encrypted profiles, MyISAM preflight, rollback after real stock/history writes, signed tamper/replay/collision, concurrent child-process event consumption, native fraud holds, queued capture, delayed confirmation, partial/full refunds/restock, void, overrefund rejection, changed-order browser denial and lost-response webhook recovery. The separate admin process uses real native users/models/controllers/Twig to check install, form, native token, custom nonce, finance permissions and uninstall retention.

No live gateway charge, OpenCart Marketplace review or email delivery service is exercised by this offline suite. Native mail methods are dispatched only by the post-commit outbox worker. Review SMTP configuration independently on staging.

`return-http.php` starts a loopback PHP server against the actual installed native `index.php`, emulating trusted TLS termination only in its test router. It sends cross-site POST without cookies/CSRF, checks fixed relative303/ignored forged fields, rejects an oversized body and compares financial/native order/history/stock table snapshots. No live gateway is used.

The backend-page contract regression can be selected with PHPUnit `--filter "BackendHostedUrl|MissingOrUnsafeBackendHostedUrl|HostedAuthorization"`. It checks exact opaque URL forwarding/cache/encryption, missing/unsafe URL rejection and unchanged original mode/key/hash. It uses no live gateway.

Version 0.1.2 targeted checkout-mode/backend-URL acceptance passed **3 tests / 23 assertions** on the official native fixture, plus PHPStan level 5, formatter and standalone ZIP checks. This patch did not rerun a live merchant flow; earlier full acceptance remains the baseline.

## 0.1.3 SDK refresh acceptance

Fresh 0.1.3 ZIP acceptance used the published Packagist PHP SDK **0.29.6** (source `29fb3ac2f0fdff7a3825e189572c7aef4b212cc1`), PHP **8.4.1**, the pinned official platform above and a new disposable MariaDB 11/InnoDB schema with its own schema-only user. **11 native tests / 81 assertions** and **21 supplemental HTTP checks** passed on the rebuilt scoped package. Actual admin, CLI and return HTTP checks also passed. Existing release receipts above remain historical.

Supplemental HTTP checks exercise the original native confirm/status/webhook routes. A test-only native parent controller emits the unchanged payment widget through Loader; this is not a complete shopping-cart UI. They cover embedded configuration, native CSRF/owner denial, repeated create, capture/stock/history exactly once, signed replay, FAILED/VOIDED nonfulfillment, exact backend hosted URL and missing-URL fail-closed behavior. Only the external Lunixi transport is synthetic; no bank/CDN/3DS transaction or Marketplace certification is claimed. The SDK carries the upstream PHP 8.5 cURL correction; this module's local native acceptance ran on PHP 8.4.1.

The public 0.1.3 archive linked above passed checksum/asset-digest/CRC verification and [release CI](https://github.com/Lunixi-Payment/lunixi-opencart4/actions/runs/41242057536). Its PHP/JavaScript/templates and financial runtime match the locally accepted 0.1.3 ZIP; the public archive only adds a documentation dark-logo asset. Acceptance used real OpenCart **4.1.0.4**, **PHP 8.4.1 / MariaDB 11.8.6 / InnoDB**, with **11 native tests / 81 assertions** and **21 supplemental HTTP checks**, plus native admin/CLI/return checks. The supplemental widget uses an explicit test-only native parent controller; this is not a complete shopping-cart browser walkthrough. Gateway transport is synthetic. Browser/CDN, real bank/3DS, SMTP and Marketplace acceptance are not claimed.
