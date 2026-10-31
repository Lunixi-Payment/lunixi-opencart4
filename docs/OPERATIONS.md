# Operational preparation

## Transactional database gate

Make an offsite backup, verify restoration, enter maintenance mode, stop order writers and review each installed business/fraud/order hook. List engines using the actual configured prefix:

```sql
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
```

All prefixed native and extension tables must use InnoDB. OpenCart 3 defaults to MyISAM. A DBA must review indexes, FULLTEXT behavior, available disk, locking, backups and the platform's migration requirements before explicitly converting individual tables, for example `ALTER TABLE actual_prefix_order ENGINE=InnoDB;`. This is an example, not a complete migration script: converting only the order table is insufficient. Verify every table and retry the compatibility report after the approved migration. Neither install nor enable performs an automatic production conversion. Acceptance tests convert only the separately named disposable test schema after an explicit destructive-test opt-in.

## Preflight and native hooks

The compatibility report identifies `platform_version`, `modified_native_order`, `nontransactional:<table>`, `order_hook:<trigger>:<action>`, `total_hook:<code>` or `fraud_hook:<code>`. Resolve the actual cause with the operator. Do not remove a business/fraud hook to bypass the gate. The source pins the official order model hash as well as the platform version. Core updates and third-party changes need an adapter review and the full native suite before enabling payments again.

## Recovery and retention

Keep the worker scheduled. Review unresolved operations and native fraud/manual fulfillment holds in the payment settings and order history. An asynchronous HTTP 2xx never proves capture/refund. Do not generate another operation ID to get around an uncertain operation; retry the exact existing ID and amount or reconcile first. Never manually mark paid based on a return URL, email, a single split leg or a screen image.

Notification outbox rows are claimed to `sending` before contacting mail. A crash or uncertain SMTP result may have delivered a message; review it before any explicit operator replay. Payment records, native history, retained encrypted profiles, event IDs, operation IDs and the independent server key must remain available for the merchant's refund/reconciliation retention period. Uninstall deliberately preserves this data. Do not purge inbox rows within gateway redelivery/financial retention windows.

The gateway TEST/LIVE key determines the environment. Do not point production credentials at a testing proxy, disable TLS verification or log raw webhook/token/credential material. Configure native database TLS where required; the CLI honors native SSL certificate constants. Gateway origins are operator-controlled HTTPS origins, not merchant-controlled redirect inputs.
