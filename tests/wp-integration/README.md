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

Every real-host gate emits `certloom-wp-integration-gate-evidence` v3 before its assertions, including one machine-readable `EVIDENCE_JSON` line. It binds the transcript to one run ID, the exact Certloom semantic version and production-intended runtime payload, the exact gate/helper source hashes, and the non-sensitive WordPress/PHP/database environment plus a privacy-safe configured database/table-instance SHA-256. Use the canonical runner below rather than manually combining transcripts. A production-byte change or WordPress/PHP/database environment change makes previously stored/admin evidence stale even when the semantic version is unchanged; rerun the full applicable proof/rehearsal sequence after either change.

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

The historical `CERTLOOM_DOMAIN_*` readiness constants remain `false` permanently and are not release authority. These gates, plus the dedicated migration/restore/cutover exercises, must pass before DBAUTH can finalize an exact-payload release manifest on the supported database matrix.

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

The collector fails closed unless all four transcripts share one run ID, semantic version, exact runtime payload, and non-sensitive WordPress/PHP/database environment; the gate/helper hashes match the current source; every required PASS marker is present; and all six stored proof/rehearsal layers are currently passed against the same exact payload, the same canonical WordPress/PHP/database environment, and the same configured database/table instance. Environment identity is produced by the same `RuntimeEnvironmentEvidence` contract for both real-host transcripts and stored evidence, so cross-host/runtime and same-server cross-database stitching fail closed. The exported bundle contains privacy-safe text reports plus canonical hashes of the validated stored result objects, not raw backup paths, owner tokens, credential values, or failure examples. It is evidence only and does **not** enable normalized-domain production authority.

## DBAUTH-01 release finalization

The acceptance collector publishes **evidence only**. For Certloom 1.6.96+, the final bundle and index use `certloom-normalized-domain-acceptance-bundle` v5 and `certloom-normalized-domain-acceptance-index` v5. They must remain outside the plugin tree until a reviewer finalizes release authority against the exact production payload that was tested.

Finalize the reviewed v5 acceptance into a separate external authority directory:

```bash
php wp-content/plugins/certloom/tests/domain-authority/finalize-domain-acceptance.php \
  /secure/external/certloom-db-acceptance \
  /secure/external/certloom-domain-authority
```

The finalizer fails closed unless the acceptance/index pair is canonical, complete, bound to the current Certloom semantic version and exact runtime payload, and matches the final environment and normalized-domain graph identities. It writes only the reviewed external authority set; it never edits the plugin source and never flips a PHP constant.

Build the authority-bearing release explicitly:

```bash
CERTLOOM_DOMAIN_AUTHORITY_DIR=/secure/external/certloom-domain-authority \
SOURCE_DATE_EPOCH=<approved-release-epoch> \
wp-content/plugins/certloom/tests/build-release.sh /secure/external/certloom-authorized-build
```

Authorized packaging validates the finalized authority directory again, injects only the canonical `resources/domain/accepted.json` release manifest into source/installable staging, and reports `domain_authority=validated`. A normal build with no reviewed authority directory reports `domain_authority=absent` and must not synthesize the manifest.

`resources/domain/accepted.json` is the **sole release-level normalized-domain authority**. The historical readiness constants stay false and are ignored by `DomainFeatureGate`; predefining them cannot open production authority. The manifest is excluded from the runtime production fingerprint only to avoid self-reference, while the manifest itself binds the exact fingerprint that passed the genuine ceremony.

Even an authorized release does not cut over an individual site automatically. `DomainCutoverCoordinator` remains a second independent boundary and requires that site's healthy schema, completed migration, source-generation parity, maintenance ownership, committed receipt, and final cutover checks before durable authority changes from the current registry to the normalized domain.

