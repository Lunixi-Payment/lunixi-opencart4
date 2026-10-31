# Native acceptance

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
php tools/vendor/bin/phpstan analyse --no-progress
composer audit --working-dir=tools
```

Official source checkouts need their own `composer install --no-dev` before fixture installation if platform vendor files are absent. Build-time `SDK_SOURCE` may point to a separately checked-out public PHP SDK0.29.3; this never becomes a runtime dependency path.

The destructive opt-in permits installation and native test-only engine conversion in the dedicated schema. It is not a production migration command. The fixture installer passes random synthetic credentials in PHP memory to the official installer; it does not put DB passwords in a shell command. Native acceptance includes exact persisted decimal FX/ISO precision, encrypted profiles, MyISAM preflight, rollback after real stock/history writes, signed tamper/replay/collision, concurrent child-process event consumption, native fraud holds, queued capture, delayed confirmation, partial/full refunds/restock, void, overrefund rejection, changed-order browser denial and lost-response webhook recovery. The separate admin process uses real native users/models/controllers/Twig to check install, form, native token, custom nonce, finance permissions and uninstall retention.

No live gateway charge, OpenCart Marketplace review or email delivery service is exercised by this offline suite. Native mail methods are dispatched only by the post-commit outbox worker. Review SMTP configuration independently on staging.
