# Certloom real WordPress/MySQL integration gate

These tests are deliberately **not** replaced by the pure-PHP suite. They must be run on a disposable WordPress installation backed by MySQL/MariaDB before Certloom's next-generation domain can become the source of truth.

Run, in order:

```bash
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-numbering-policy.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-schema.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-premium-gate.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-row-lock-gate.php
```


## Exact-byte evidence boundary

Every real-host gate emits `certloom-wp-integration-gate-evidence` v2 before its assertions, including one machine-readable `EVIDENCE_JSON` line. It binds the transcript to one run ID, the exact Certloom semantic version and production-intended runtime payload, the exact gate/helper source hashes, and the non-sensitive WordPress/PHP/database environment. Use the canonical runner below rather than manually combining transcripts. A production-byte change makes previously stored/admin evidence stale even when the semantic version is unchanged; rerun the full applicable proof/rehearsal sequence after any such change.

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

## Canonical normalized-domain acceptance bundle

Do not manually stitch the real-host gate transcripts together. Run all four under one run identity and atomically publish them outside the plugin tree:

```bash
wp-content/plugins/certloom/tests/wp-integration/run-base-gates.sh /secure/external/certloom-db-base-gates
```

After fresh passing database proof, gated-domain proof, populated migration preflight, staging migration, Replace/Merge restore rehearsal, and final cutover rehearsal on that same disposable environment and exact Certloom payload, publish one privacy-safe machine-readable acceptance bundle:

```bash
CERTLOOM_DB_BASE_GATE_DIR=/secure/external/certloom-db-base-gates \
CERTLOOM_DB_ACCEPTANCE_DIR=/secure/external/certloom-db-acceptance \
wp eval-file wp-content/plugins/certloom/tests/wp-integration/build-normalized-domain-acceptance.php
```

The collector fails closed unless all four transcripts share one run ID, semantic version, exact runtime payload, and non-sensitive WordPress/PHP/database environment; the gate/helper hashes match the current source; every required PASS marker is present; and all six stored proof/rehearsal layers are currently passed against the same payload. The exported bundle contains privacy-safe text reports plus canonical hashes of the validated stored result objects, not raw backup paths, owner tokens, credential values, or failure examples. It is evidence only and does **not** enable normalized-domain production authority.
