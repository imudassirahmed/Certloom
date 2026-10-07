# Configuration writers and guided setup acceptance

Status: accepted development checkpoint on the authorized Local site. Source
version remains `1.7.0-alpha.4.87`. This is not release or external-provider proof.

## Configuration repairs wake waiting requests

The existing checked configuration outbox now covers three additional writers:

| Organization edit | Reassessment scope | Persistence boundary |
| --- | --- | --- |
| Global field requirements | All Programs | Requirement option, domain generation and outbox share a checked transaction |
| Issuer branding/settings, including its field contract | All Programs | Issuer row and outbox share the existing transaction |
| Batch metadata/requirements and other saved Batch changes | Its Program | Batch row, primary instructor assignment and outbox share the existing transaction |
| Moving a Batch between Programs | All Programs | A bounded global pass retains work from both contexts |

These join the previously accepted Program and active-field-catalog writers.
The private worker still considers ten rows per slice. Program-scoped selection
uses the existing bounded Program/status/id branches; global selection uses
primary-key pages. Issuer edits do not enumerate every affected Program in an
interactive request. A large history can take multiple background slices.

Global saves read actual database storage, invalidate stale option caches and
verify the intended saved value. An unchanged Global requirement creates no
generation or signal. Empty Issuer patches preserve their previous behavior.
Failed or zero-row outbox persistence rejects and rolls back the associated edit,
including a Batch's synchronized instructor assignment. The existing watchdog
can recover a lost scheduling event from the durable signal.

Saving configuration does not issue synchronously, invent eligibility, write a
customer answer, or modify an issued snapshot. The canonical worker rechecks the
actual request. A previously qualified request can issue once its requirement
blocker is repaired; unknown qualification remains waiting.

The current row-write triggers do not establish every standalone signer/design,
settings or trusted-mapping change path, or an assignment-only repair whose Batch
row is unchanged. Those consumers remain in the audit backlog.

## Actual guided browser journey

Lance completed a fresh native setup through Welcome, Profile, Organization,
Program, Fields, Certificate, Email, Review, Issue and Finish. A second owned
journey verified the final completion guidance after these changes.

The scenarios used Training / LMS, one explicitly named Program and Issuer,
explicitly unchecked optional field recommendations, details-only presentation,
inherited email rules and one named recipient with **no email destination**.
Review returned HTTP 200 with configuration readiness and `recipient_evaluated`
false. Only the deliberate Save setup action created the Program/Issuer. Only the
deliberate Issue action created the recipient, Series, Version, number and event.
There were no delivery or email jobs.

The initial Finish screen repeated “Create a Program” after saving one. Completion
advice now replaces that task with “Review Program requirements and customer
fields” for the eight applicable profiles, while retaining the other profile
recommendations and General / Other advice. The checklist is computed only on
Finish. The final desktop/mobile screen was captured and visually inspected.

The first QA verification link was opened using the actual View credential link.
Its public screen showed status and number, retained the private-details notice,
and exposed neither the QA recipient nor Program name. Issue, Finish and public
verification had no horizontal overflow at 390px. This is details-only flow
acceptance; it is not production certificate artifact or anonymous-session proof.

Both browser fixtures were removed using exact owned-record journals. All 23
business stores' original rows, site users/posts and the original administrator
draft were preserved. Only explicitly owned added records were deleted. Relevant
configuration and the QA sequence counter were restored after verifying that
every new business row belonged to the fixture. Normal WordPress cron progress
and verification security counters were preserved at their current values.
The private journals were removed. Lance returned to its original setup URL and
1536 x 674 viewport; only the QA-opened verification tab was closed.

Browser controls used real application handlers through native DOM activation
where the attached extension's pointer actions were unreliable. Full pointer,
keyboard, assistive-technology and browser-concurrency acceptance remains open.

## Reproducible evidence

- `configuration-writers-final-php85.json` and `configuration-writers-final-php82.json`:
  **47 live checks per runtime** against WordPress 7.1.2 / MySQL 8.4.0. Includes
  three held-then-issued contexts, unknown qualification, stale-cache/no-op saves,
  Batch movement, six failed/zero-row signal cases, option-write failure,
  signature projection rollback and three preserved immutable snapshots. All
  tracked business rows, users/usermeta/posts, Certloom options and cron matched
  before-state after the deliberate rollback.
- `configuration-concurrency-final-php85.json` and `configuration-concurrency-final-php82.json`:
  **nine live checks per runtime**. Independent PHP/MySQL processes exercised an
  actual `INNODB_TRX` lock wait, different-scope coalescing and a third session's
  stale acknowledgement. This probe used synthetic scope IDs and an empty intake
  ceiling. It created no recipients, Programs or credentials; its original queue
  and tracked state were restored exactly, and its private journal was removed.
- `configuration-writers-final`: 20 passing focused suite executions and two
  initial failures in the older structural test. That test had stale schema-19,
  backup-4 and exact-array-ending assertions. It now checks the current schema-20,
  backup-5 contract and preserves its original Batch/Signer relationship checks.
  `domain-structure-current-contracts-final` contains the two passing reruns.
  All passing runs have zero diagnostics/skips. No production contract was
  weakened to accommodate the older assertions.
- `profile-setups-after-writers-php85.json`: **314 additional live checks** passed
  after the writer changes, including private setup review, profile designs,
  atomic apply/issue, replay and injected failure rollback.
- Twenty final PHP syntax checks across both runtimes, JavaScript syntax and
  targeted whitespace checks passed. The earlier 18 profile/setup suite runs and
  four sample-date runs remain documented in the profile/readiness checkpoint.
- `guided-and-writers-final`: the final complete selection of **22 focused suite
  executions** passed across both runtimes after the structural test corrections
  and final Finish view change, with zero diagnostics/skips.

Evidence files are under `output/program-recipient-20261003`. Browser captures are
`output/playwright/20261003-guided-*.png` and
`20261003-guided-final-*.png`. The real-host probes are repository test files
under `tests/wp-integration`; their usage and recovery boundaries are documented
in that directory's README. No commit or release has been created by this task.

A fresh private schema-20 SQL snapshot was taken before this test slice, outside
the webroot. Its metadata is `output/program-recipient-20261003/private-snapshot.json`;
SHA-256 is `7370f20d487a5963188f8dc5c3520c9592933b9e2c9211476901e023f6b97000`.
No snapshot was restored over the site.

Harness corrections: the first writer fixture used an unsupported lifetime name,
omitted explicit active/slug settings, and reused a Program subject to duplicate
prevention. Those failing attempts rolled back and preserved tracked state. The
correct fixtures use the actual `achievement` policy, active Batches, an explicit
signer slug and distinct Programs. The first concurrency observer depended on
unavailable performance-schema lock instrumentation; `INNODB_TRX` established the
actual overlap. Browser heartbeat advanced cron/maintenance metadata and the
watchdog legitimately acknowledged the owned zero-ceiling signal. Checked
recovery restored only the exact owned queue, accepting only that same-generation
acknowledgement and preserving current background options. The failed probe's
journal was removed before successful reruns. Browser summary selectors were
corrected for the two Review lists, and View credential was handled as a new tab.

## Still required

Staff request queue/history/retry/cancel, certificate-name and account-linking
policy, bounded notifications/reminders/expiry, remaining configuration consumers,
current trusted adapters and v2 intake, real mixed CSV commits/replays/failures,
actual subscriber browser transport, complete widget/screen/accessibility checks,
whole-state recovery, supported-platform/production-renderer/provider gates, and
actual concurrent canonical issuance remain open. LearnDash is still deferred
until the current Program/recipient implementation is ready, as requested.
