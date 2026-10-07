# Certloom real WordPress/MySQL integration gate

These tests are deliberately **not** replaced by the pure-PHP suite. They must be run on a disposable WordPress installation backed by MySQL/MariaDB before Certloom's next-generation domain can become the source of truth.

## Local recovery fault injection

`run-recovery-cache.php` and `run-domain-recovery-matrix.php` run directly with the site's PHP CLI and configuration. They require `CERTLOOM_TEST_WP_ROOT` to identify the disposable WordPress root, `wp_get_environment_type() === 'local'`, and `CERTLOOM_TEST_DB_BACKUP` to point to an existing database snapshot. Take that snapshot immediately before testing. These scripts refuse HTTP execution and refuse to start with existing maintenance state.

```powershell
$env:CERTLOOM_TEST_WP_ROOT = '<disposable Local site>/app/public'
$env:CERTLOOM_TEST_DB_BACKUP = '<private snapshot outside the webroot>.sql'
& '<Local PHP executable>' -c '<Local CLI ini>' tests/wp-integration/run-recovery-cache.php
& '<Local PHP executable>' -c '<Local CLI ini>' tests/wp-integration/run-domain-recovery-matrix.php
& '<Local PHP executable>' -c '<Local CLI ini>' tests/wp-integration/run-domain-recovery-matrix.php roundtrip
```

The matrix requires an issuer. It deliberately terminates independent WordPress workers before/after the table rename and journal commits, including a recovery worker just after rollback. It tests live and expired worker leases, competing normal request bootstraps, immediate completion-receipt retry, cleanup, and preservation of all 14 native table hashes and the three configuration entries. The roundtrip variant exercises the real serialized backup, bounded staging and both Merge/Replace services. Its negative identity check needs an unissued recipient without a WordPress account; the changed recipient exists only in discarded private staging tables.

On a passing run, live entity data and test metadata are restored and private test tables are removed. A failed assertion intentionally retains recovery evidence; inspect it and use the private pre-test snapshot or validated pre-test native backup to recover before rerunning. These are infrastructure/service tests, not a complete site-backup contract, browser restore acceptance, provider acceptance, or normalized-domain release authority.

Run, in order:

```bash
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-numbering-policy.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-schema.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-premium-gate.php
wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-row-lock-gate.php
```


## Designer recovery concurrency probe

`run-designer-autosave-concurrency.php` requires the exact disposable Local site
and a fresh private SQL snapshot through `CERTLOOM_TEST_WP_ROOT` and
`CERTLOOM_TEST_DB_BACKUP`. Run it directly with the Local PHP executable and its
CLI configuration. It starts a separate WordPress/MySQL worker to hold the same
Designer mutex, checks save/discard/editor-preparation exclusion, and verifies
session ownership after release. Its template and user-meta writes run inside an
intentionally rolled-back transaction. Native rows, generation and original
administrator metadata must match afterward, with no named lock left held.

This probe establishes service-level concurrency behavior. It does not cover
browser multi-tab interaction or grant normalized-domain or renderer authority.

## Pending-request organization operations on Local

`run-pending-request-admin.php` requires the exact Local site/root/database/port,
its PHP CLI configuration, and the same verified private snapshot environment
(`CERTLOOM_TEST_WP_ROOT`, `CERTLOOM_TEST_DB_BACKUP`,
`CERTLOOM_TEST_DB_BACKUP_SHA256`). Run directly with the Local PHP executable;
HTTP execution is refused. External transport is suppressed and its own cron is
disabled. The full fixture intentionally rolls back and compares all 23 business
stores, users/usermeta/posts, owned configuration and cron.

The probe covers private bounded staff reads, capabilities, strict commands,
stale revisions, evidence-preserving reassessment, safe cancellation, actual
canonical interrupted-receipt reconciliation, write/event/CAS failures, paged
history, anonymized recipients and EXPLAIN of the actual joined status query.
It does not accept new external adapters, simultaneous canonical issuance,
production artifacts, or the full browser/assistive-technology matrix.

## Pending configuration probes on Local

`run-pending-configuration-writers.php` exercises real Global, Issuer and Batch
edits, held qualified requests, bounded canonical workers, unknown qualification,
immutable snapshots, stale option caches, and failed/zero-row signal writes. It
deliberately rolls back the full fixture and compares all 23 business stores,
users/usermeta/posts, Certloom-owned options and cron with the before-state.

`run-pending-configuration-concurrency.php` starts independent PHP/MySQL sessions.
It observes the second session's real `INNODB_TRX` lock wait, coalesces two
synthetic Program-scope signals, and rejects stale generation acknowledgement.
This probe uses an empty intake ceiling and creates no Programs, recipients or
credentials. It tests the outbox lock/generation contract, rather than concurrent
canonical issuance or external event qualification. It refuses existing requests
or pending configuration work. Its exact original queue row is restored using a
checked transaction and an owned worker receipt.

Both probes require the exact `ecapla.local` Local site/database/port, its PHP
CLI configuration, a fresh private SQL backup, `CERTLOOM_TEST_WP_ROOT`,
`CERTLOOM_TEST_DB_BACKUP`, and `CERTLOOM_TEST_DB_BACKUP_SHA256`. Keep backup files
outside the webroot. The probes suppress external transport and disable cron
only in their own CLI requests. For deterministic concurrency observation, pause
heartbeat in the task's browser tab while the probe runs. Other browser traffic
can still advance cron or legitimately acknowledge the probe's empty ceiling.

On concurrency failure, join its workers before inspecting the retained private
journal. `inspect <private-directory>` compares scheduling fields. `restore
<private-directory>` requires the exact committed worker receipt and unchanged
tracked state. The narrow `restore-preserve-background <private-directory>` mode
restores only that owned queue row while preserving other options at their current
values; every business table must still match. It also accepts only the watchdog's
exact pending-to-acknowledged transition for the same owned generation, zero
ceiling and no job. A different queue generation or changed business data refuses
cleanup. No whole-database backup is automatically restored.

These are development probes. They do not emit or replace the canonical release
acceptance bundle, and do not establish renderer, provider, multisite, scheduler
matrix, or concurrent issue-once acceptance.

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

Recipient workflow probes also run against the explicitly guarded Local test site. `run-recipient-account-links.php` covers exact staff-reviewed links, unlink/deletion holds, account/name policy and immutable history. `run-bulk-recipient-sources.php` exercises actual CSV parsing, durable preflight/commit workers, customer submission/approval, qualified waiting requests, mixed rows, exact repeat enrollment ownership and crash replay. Both require the exact site and a verified private database snapshot; their fixtures use checked rollback, block external transport and verify tracked rows, Certloom configuration and cron preservation. Their success is implementation evidence, separate from release authority.

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
