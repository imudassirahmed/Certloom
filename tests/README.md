# Certloom regression tests

The standalone suite requires Python 3.9+, PHP with the extensions used by the
selected suites, and Node 18+ for JavaScript request-handler and field-error tests. Renderer and
packaging tests also require their declared font, verifier, and Bash dependencies.
Use the CLI PHP configuration for the runtime being tested.

```powershell
python tests/run-suite.py --php '<PHP executable>' --ini '<CLI php.ini>'
python tests/run-suite.py --php '<PHP executable>' --ini '<CLI php.ini>' run-designer-autosave.php run-issue-request-races.js
python tests/run-suite.py --php '<PHP executable>' --ini '<CLI php.ini>' run-issue-field-errors.js
```

Repeat `--php` and `--ini` for multiple PHP runtimes. Use a fresh `--output`
directory to choose where transcripts and JSON results are saved. Timeouts and
missing dependencies fail; platform skips and diagnostics are recorded separately
from exit success. Review those transcripts before claiming coverage. The runner
uses argument vectors, preserves the process environment, and removes real-site
integration variables from standalone fixtures.

The two authorized-release packaging suites create complete plugin copies and
are excluded from the default single-workspace run. Run those suites separately
only in a permitted packaging workspace. Their exclusion is reported explicitly.
The default run does not execute real WordPress/MySQL tests or browser flows.

Read [the acceptance boundaries](acceptance-contract.md) and the
[real-host instructions](wp-integration/README.md) before broader acceptance.
Real-host fault injection requires the exact disposable site and a fresh private
database snapshot. Never store SQL dumps or credentials under the plugin webroot.
No test can turn a fixture authority seam into approved production authority.

Whole-state recovery engineering has separate envelope, raw-configuration, authoring and
origin-signature suites: `run-whole-state-backup-envelope.php`,
`run-whole-state-configuration.php`, `run-whole-state-authoring.php` and `run-whole-state-provenance.php`.
Their transport/integrity/signature success does not imply semantic import,
account ownership, execution resumption or complete recovery acceptance. The
guarded `wp-integration/run-whole-state-configuration.php` uses actual fixed/dynamic SQL,
multi-page capture, faults and checked rollback on the exact authorized Local site; it publishes no
archive or recovery key. See the [recovery checkpoint](../audit/CERTLOOM-WHOLE-STATE-RECOVERY-2026-10-03.md).

`run-whole-state-delivery-records.php` checks lossless bounded SQL delivery storage
without granting import/restart authority. The guarded real-host
`wp-integration/run-delivery-write-boundary.php` exercises atomic transitions,
maintenance, stale-job reconciliation, actual primary-index pages and capture
faults under checked rollback. `wp-integration/run-delivery-concurrency.php`
accepts two-session delivery races using private synchronization/ownership
journals and exact owned cleanup. Its explicit `restore` and
`recover-owned-preserve-options` modes require the verified private directory;
they never restore a SQL dump or overwrite unrelated site progress. See
[delivery evidence and limits](../audit/CERTLOOM-DELIVERY-CONCURRENCY-AND-RECOVERY-CAPTURE-2026-10-04.md).
