# Certloom real WordPress/MySQL integration gate

These tests are deliberately **not** replaced by the pure-PHP suite. They must be run on a disposable WordPress installation backed by MySQL/MariaDB before Certloom's next-generation domain can become the source of truth.

Run, in order:

```bash
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-numbering-policy.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-schema.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-premium-gate.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-row-lock-gate.php
```

Required pass evidence includes:

- `dbDelta()` creates/updates every domain table successfully;
- tables use InnoDB;
- critical unique and query indexes match the schema contract;
- real transaction rollback works;
- a second process blocks on the same `SELECT ... FOR UPDATE` Credential Series row until the owning transaction commits;
- current-authority sequential numbering uses real atomic `wp_options` allocation, skips collisions, and never rewinds;
- global human certificate-number ownership is enforced;
- cross-Series Version lineage is rejected without side effects;
- invalid immutable snapshots do not advance domain generation or strand number reservations;
- duplicate source idempotency failures release newly reserved numbers;
- lifecycle state/event writes and public verification work against real tables;
- the legacy registry remains untouched by additive domain tests.

The public authority flags remain `false` until this gate, plus the dedicated migration/restore cutover exercises, are executed successfully on the supported database matrix.
