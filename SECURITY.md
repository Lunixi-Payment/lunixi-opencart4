# Security policy

Version0.1.x is the currently maintained source line. Report suspected vulnerabilities privately through the Lunixi merchant support channel or the repository's private security advisory facility. Do not post credentials, raw customer/webhook bodies, payment tokens, private keys, database exports or exploitable production details in public issues.

Provide the extension/platform/PHP versions, a synthetic reproduction, expected/actual behavior and redacted request IDs. The maintainer will assess the impact and coordinate a fix/disclosure; no response-time guarantee is implied.

Use a current audited platform, HTTPS, a separate server encryption key and InnoDB. Keep signing keys/server backups outside the web root. Do not disable webhook authentication, fraud/business hooks, native CSRF/permissions or the compatibility preflight. Preserve operation IDs and exact amounts after uncertain financial outcomes. Retain old encrypted profiles and the server key for reconciliation/refunds. See docs/OPERATIONS.md for safe recovery and deliberate data retention.
