# Whole-state recovery implementation checkpoint

Updated 8 October 2026: Whole-state engineering acceptance is complete within the recorded internal source/environment boundary. See the [current completion report and evidence](CERTLOOM-REMAINING-WORK-CLOSURE-2026-10-08.md). The dated sections below are historical checkpoints; their earlier open-work statements are superseded for this scope. Release validation and installed LearnDash remain separate.

Started 3 October. The initial 3–4 October checkpoint follows; current engineering acceptance and its limits are linked above.

## Implemented boundary

`WholeStateBackupFormat` defines a separate outer v1 recovery envelope. It preserves the original domain v5 and legacy v4 streams and their historical reader/hash contracts, rather than silently adding configuration to those manifests. The scope requires six components: native domain, legacy registry, configuration, authoring, durable deliveries and installation-local references, plus content-addressed asset members.

`WholeStateBackupReader` performs read-only ZIP/member integrity analysis. Canonical manifest bytes reject duplicate JSON properties. Exact member sets, typed bounded sizes and SHA-256 descriptors reject missing/extra/changed content. Regular-member attributes, explicit names and content addresses prevent traversal, symlink/directory aliases, wrappers and duplicate-name ambiguity. Encrypted members are refused. The reader streams member bytes without extraction, rechecks the archive hash and returns `ownership_verified=false` and `restore_ready=false`. A site ID, source URL or well-formed HMAC claim is not authenticity.

The 1 GiB archive/aggregate and 512 MiB stream-member ceilings are explicit envelope bounds; configuration is capped at 8 MiB and each asset at 64 MiB. Authoring, deliveries and references use line-oriented members for future bounded processing. These do not accept long-running HTTP behavior; bounded snapshot/staging orchestration and operational limits still need implementation before this is wired to a product control.

## Evidence

- 45 real ZIP/format/hostile-member checks pass on PHP 8.5.1 and 8.2.29 without diagnostics: `output/program-recipient-20261003/whole-envelope-final`.
- All sixteen final focused executions pass without diagnostics across both runtimes in `whole-envelope-final`.
- Existing domain builder/reader, metadata, line boundaries, jobs, workflow backup and legacy builder regressions pass in `whole-envelope-first`. The legacy round-trip test initially failed because its standalone fixture had not registered the namespaced autoloader needed by the previously added field-policy dependency. Its fixture now loads the production dependency; its original behavioral assertions remain unchanged. Both corrected round-trip runs pass in `whole-envelope-roundtrip-corrected`.
- No site database, account, credential, option or file publication is changed by this envelope work. Owned ZIP fixtures are removed.
- ZIP metadata and streaming APIs were checked against the [PHP external-attribute documentation](https://www.php.net/manual/en/ziparchive.getexternalattributesindex.php), [entry metadata documentation](https://www.php.net/manual/en/ziparchive.statindex.php) and [stream documentation](https://www.php.net/manual/en/ziparchive.getstream.php).

## Next implementation sequence

1. Define and validate the six component schemas and a complete, explicit durable-configuration inventory. Native domain v5 already includes four recipient workflow stores, bringing its current graph to eighteen entity types. The acceptance contract's earlier fourteen-entity count is historical.
2. Share one capture coordinator between manual and scheduled snapshots. Use the existing maintenance/write fence, checked generation and private job storage; add fingerprint coverage for durable options, authoring, deliveries and exact referenced files that are outside the current domain generation scope. Partial/interrupted capture is never a complete archive.
3. Capture actual configuration: global scoped fields; Tutor/Woo/LearnDash/Coursera/Credly/v1-v2 webhook mappings; new Tutor/Woo pending options; sealed connection credentials; email design catalog, issued-template compatibility option, assignments and automation rules/runs; custom-font index; durable setup/product choices and backup schedule/retention. Exclude release/entitlement authority, cached health, temporary secrets displays, cron leases and import workspaces. This is an inventory to validate, not a blanket `certloom_*` copy.
4. Correct an earlier assumption: current email designs live in `certloom_email_template_catalog_v1`, with separate settings/assignment/automation options. The current code does not store that catalog as WordPress posts. Inventory legacy authoring separately rather than inventing a post-based restore.
5. Preserve native and legacy credential graphs, numbering reservations, immutable snapshots, privacy markers, account/submission/request history, durable email/destination receipts and automation replay evidence. Do not restore disposable execution jobs as runnable work.
6. Inventory exact media/font/issued artifact references and bytes, independently of mutable attachment metadata. Preserve issued bytes/snapshot hashes, and distinguish editable designs' remappable references from immutable historical identities.
7. Verify same-installation provenance with a local secret that is never imported from the archive. Cross-site accounts, attachment/course/product IDs and destinations need explicit target review. Keep the existing unverified-account refusal until the whole-state ownership boundary is complete; matching names/emails/URLs do not establish ownership. Imported third-party credentials stay paused or require reconnection under target salts.
8. Stage DB graph and owned files under one recoverable journal. Check each transition, file publication, rollback/resume and cleanup receipt; never overwrite or delete changed ordinary files. Preserve current user metadata and unrelated cron progress. Configured requests/deliveries/automations remain suspended until reconciled; sent work never replays merely because it was imported.
9. Wire native review/mapping/mode/commit/resume controls only after complete semantic preflight. Accept actual mixed/manual/scheduled same-site/fresh-site journeys, collisions/forgeries, missing/corrupt assets, interruption/cleanup ownership and non-replayed deliveries against a disposable WordPress/MySQL/filesystem environment.

These steps remain open. The current Data tools/domain and legacy archives keep their existing partial scope. See the [master Program/recipient plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md) and [whole-state acceptance target](../tests/acceptance-contract.md#whole-state-recovery-implementation-target). LearnDash installation/testing remains deferred.

## Explicit option capture and authenticated origin

The next engineering slice adds `WholeStateOptionInventory`, lossless `WholeStateOptionRecord`, `WholeStateConfigurationFormat`, and an uncached `WholeStateOptionRepository`. Thirty-one durable option names are checked against their production store constants. Their ownership is explicit:

| Component | Fixed options | Reconciliation |
| --- | ---: | --- |
| Domain | 3 | Existing field-catalog/settings review |
| Legacy | 1 | Existing numbering reconciliation |
| Configuration | 21 | Field policies, setup, source/connection review, held automation/scheduling and retention |
| Authoring | 2 | Email catalog and issued-template compatibility settings |
| Deliveries | 1 | Automation replay evidence |
| References | 3 | Font assets and local/remote backup-copy availability review |

Each raw storage record distinguishes absence from a present empty value, retains exact bytes through canonical base64, and checks length and SHA-256. It never unserializes or decrypts configuration. This preserves leading-zero identifiers, zero results, booleans, Unicode, sparse/typed serialized structures and sealed credentials without normalizing away malformed data. **These are transport checks, not semantic approval to import a value.** Every source/policy/catalog still needs its own semantic preflight and target reference reconciliation.

The first indexed SQL read checks sizes/hashes without fetching option bodies. The second is capped, and its complete fingerprint must match the first. A final uncached verification detects later edits, inserts or deletions. Limits are 2 MiB per option and 4 MiB aggregate raw option storage; oversized state fails rather than producing an incomplete snapshot. The configuration component requires the exact snapshot inventory version/fingerprint and its complete twenty-one-option scope. Other components' options are not silently duplicated into it. Capture is not yet connected to manual/scheduled product controls.

`WholeStateBackupProvenance` signs the complete canonical outer manifest under the context `certloom-whole-state-provenance-v1`, replacing the tag with sixty-four zeroes for the HMAC input. `WholeStateProvenanceRepository` explicitly provisions one installation/blog root under the checked transaction/write fence and current data-tools actor. The key is random, authenticated-encrypted with the existing vault and WordPress security material, non-autoloaded, purpose-bound, and excluded from every fixed export inventory. Repeated setup preserves exact key bytes. Corrupt, mismatched or oversized roots are not replaced automatically; uninstall removes the root.

Identity/signature reads bypass option filters and serialized values. Verification never provisions a root and checks existing authentication material before opening it, avoiding `wp_salt()`'s missing-material generation path. Different installation/blog claims, altered member descriptors, forged tags and corrupt local keys fail closed. Authentic provenance returns `ownership_verified=false` and `restore_ready=false`: it does not establish an account/object mapping, validate asset/component semantics, reconnect credentials or restart delivery/issuance. The original outer ZIP reader still performs integrity analysis only; the eventual preflight coordinator must combine the separate boundaries.

Final evidence:

- 64 guarded WordPress/MySQL checks pass per PHP 8.5.1/8.2.29 runtime in `output/program-recipient-20261003/whole-recovery-options-accepted-php85.json` and `whole-recovery-options-accepted-php82.json`. All three option-read EXPLAIN plans use `option_name`. Actual option-byte races, private root setup, filters, forgery, purpose/identity corruption, missing roots, SQL failures/zero writes and size bounds are covered. Tracked business/core rows and every WordPress option, including authentication fallbacks and cron, are preserved by checked rollback.
- 55 option/configuration and 30 origin/root standalone checks pass per runtime. All sixteen focused executions in `whole-provenance-final` pass without diagnostics, including unchanged domain/legacy/workflow/numbering regressions. The installed Elementor PHP 8.5 bootstrap emits its existing external deprecation; final probes have no Certloom diagnostics.
- No recovery root or archive is published on the local site by acceptance fixtures. No backup UI is relabeled as complete. Dynamic template recommendation capture is accepted in the later checkpoint below. Complete delivery/reference graphs, asset capture, common manual/scheduled orchestration, target-account mappings, file/DB journals, paused reconciliation and real whole-state restore journeys remain open.

## Authoring capture, 4 October

`WholeStateTemplateRecommendationInventory` and its raw repository now capture the exact `certloom_template_recommendations_` namespace. Native certificate designs/revisions/assignments remain in the existing domain graph; the extra authoring stream combines the two fixed email authoring options with all captured profile recommendation options. There is no invented WordPress-post email catalog or blanket plugin-prefix copy.

Canonical positive, platform-representable Template ID suffixes are required. The reader keeps orphan metadata for later graph review; it does not silently discard it or establish ownership of a corresponding design. Malformed namespace members fail the capture. Opaque storage remains lossless, including empty values, leading zeros and serialized data; it is never unserialized or executed by these classes.

The complete namespace is bounded at 5,000 options, 2 MiB per raw value and 4 MiB aggregate raw metadata. Indexed descriptors bracket value pages of at most 200 keys. Each value's captured length is enforced in SQL before it can be returned, bounding returned bytes even if a concurrent edit grows every row. Checked PHP digests detect same-length edits; the final complete namespace read detects new keys outside the value pages. Final verification detects later changes. All reads explicitly use the `option_name` index. These fingerprints still need the common coordinator and write fence before a whole-state snapshot can be claimed.

`WholeStateAuthoringFormat` provides canonical JSON lines with strict component/version/count metadata, ordered unique option identities, exact storage records and a complete fingerprint footer. Both fixed authoring presence records are mandatory, even on a site without recommendation metadata. It rejects expanded scope, duplicate properties/rows, reordered/truncated streams and forged digests. The authoring component and outer member limit are 8 MiB; its raw aggregate cap includes fixed email authoring plus dynamic metadata. Transport validity is separate from semantic content, reference mapping, restore publication and restart permission.

Evidence:

- 48 standalone authoring checks pass per PHP 8.5.1/8.2.29 runtime, including the full 5,000-option/25-value-page boundary and 5,002-option component round trip. All ten focused executions in `output/program-recipient-20261004/whole-authoring-accepted-pure` pass without diagnostics; configuration, provenance, outer envelope and workflow-backup regressions remain accepted.
- The expanded guarded WordPress/MySQL probe passes 105 checks per runtime in `output/program-recipient-20261004/whole-authoring-php85.json` and `whole-authoring-php82.json`. Actual multi-page storage capture, all range EXPLAIN plans, warmed caches/filters, insert/delete/same-length/growth races, SQL faults, malformed namespace members and component round trip are covered. Original business/core rows and every WordPress option, including cron and authentication fallbacks, are preserved by checked rollback.
- No archive/root/authoring option is published by these fixtures. Source mappings, exact referenced assets, durable delivery graphs, coordinated manual/scheduled snapshots, reviewed target mappings, recoverable DB/file journals and paused restoration remain open. Template recommendation and other non-domain writers still need the common recovery consistency contract; the new read models do not alone supply it.

Twelve further compatibility executions pass without diagnostics in `output/program-recipient-20261004/whole-authoring-compatibility`: domain backup/metadata, legacy builder/round trip, numbering compatibility and saved template recommendation behavior. Fourteen PHP syntax checks across both runtimes and the recipient JavaScript syntax check pass. The narrowed tracked diff has no whitespace errors. Existing issued/domain/legacy format contracts and the site's ordinary data remain preserved.

## Delivery consistency and capture, 4 October

Delivery creation/state writers now use checked maintenance-fenced transactions,
locked evidence and exact attempt proof. External stale-job reconciliation
rechecks the current lease and closes the delivery/job atomically. Two-session
claim and terminal-result races are accepted for both actual delivery stores;
this does not accept canonical issuance/approval/cancellation races.

Lossless storage records and a bounded keyset read model now inventory both
complete delivery tables. Exact columns, SQL nulls, storage-byte hashes, SQL body
length guards and generation/final descriptor checks are accepted against real
MySQL. Explicit bounds are 256 KiB per row, sixteen rows per value page,
100,000 combined records and 256 MiB raw aggregate. These services still need
the complete delivery stream and common coordinator; semantic restoration,
reference/assets, target ownership and recoverable DB/file publication remain
open. Other non-domain configuration/automation writers still need fencing.

Final evidence is **163 guarded live checks and 14 independent-worker checks per
PHP runtime**, plus **18 focused executions without diagnostics**. Fixtures and
their private concurrency journals are removed, ordinary business/account data
and current unrelated site progress are preserved, and no external delivery or
issued Version is created. See [the complete checkpoint, fault diagnostics and
remaining boundaries](CERTLOOM-DELIVERY-CONCURRENCY-AND-RECOVERY-CAPTURE-2026-10-04.md).

## Coordinated source capture, 4 October

The capture services now combine all six components and actual referenced bytes through `WholeStateBackupBuilder`. Native v5 and legacy v4 shapes remain unchanged. The legacy manifest is shared with its existing synchronous exporter, and the bounded legacy builder resumes from an authenticated persisted prefix and truncates an uncommitted append. Both complete delivery stores are captured in sixteen-row SQL pages with their original null/storage-byte and replay-evidence contract.

The reference component exports only opaque current account identity/blog-membership digests, explicit missing references, typed local object identities, exact media descriptors and custom-font descriptors. It does not export WordPress passwords/roles/user metadata or grant target ownership. Historical images require the saved media proof; changed present attachments cannot substitute for issued bytes. Asset members are content addressed and deduplicated. Account/reference checks bypass warmed WordPress caches. The private checkpoint stores raw configuration/sealed credentials outside job progress and browser responses.

The common builder brackets components with generation and raw option/recommendation/delivery fingerprints, verifies legacy state, signs the final manifest with the existing local root, writes strict uncompressed ZIP members, and rechecks the complete archive and component streams. It refuses mixed or incomplete captures. Its current operational bound is **128 MiB of component and asset data**, below the outer format's 1 GiB transport ceiling. Oversized state fails explicitly. Short capture fences are released; this does not create a persistent site freeze.

Evidence: **37 disposable real WordPress/MySQL/filesystem checks pass per PHP 8.2/8.5 runtime** in `output/program-recipient-20261004/whole-coordinated-capture-first-php82.json` and `whole-coordinated-capture-first-php85.json`. The mixed graph includes a canonical image Version, actual linked accounts, five legacy records, twenty delivery rows, all fixed configuration scopes and an actual asset ZIP member. Every capture phase crosses a saved private worker boundary. Checkpoint forgery/adoption, append replay, current account changes, missing identities, historical image changes and registry mutation are covered. All 36 disposable tables/private fixture files/checkpoints are removed; original business and every ordinary option are unchanged. Twenty-eight strict reference stream/hostile-row checks pass per runtime. The existing synchronous builder passes its unchanged 21 assertions after its fixture loads the current namespaced dependency.

This is accepted **source capture**, not a completed recovery product. Native manual/scheduled queuing/catalog controls, complete semantic component preflight, reviewed target mappings, typed account proof, staged DB/file journals, fallback asset publication, paused reconciliation and actual same/fresh-site restore remain open. The current backup UI keeps its partial scope until those controls are wired and accepted. See [canonical/lifecycle/native evidence](CERTLOOM-CANONICAL-LIFECYCLE-AND-NATIVE-COMMITS-2026-10-04.md).

## Workers, reviewed ownership and original assets, 4 October

Manual and scheduled whole-state jobs now use the same production worker and verified local catalog. Real expired/wrong leases fail before capture. Private checkpoints are bounded to the committed and next file, publication is checked under the current job lease, and cleanup removes exact owned working files. Catalog append/delete/retention use current checked option mutation. A real outer COMMIT failure leaves one verified deterministic archive copy; retry adopts those exact bytes without duplicating the catalog. These internal workers are accepted; native product controls and scheduler opt-in are still to be wired.

Typed account and object review checks the actual outer archive SHA/signature, explicit complete mappings, current actor, target generation and uncached account/object identities. Same-installation mappings require the exact source identity; reviewed target mode requires explicit existing target choices of the source object type. Explicit unlink/disconnect is supported. Serialized flags cannot recreate authority. Object proof establishes a reviewed connection, **not image-byte equivalence, editable-layout rewriting or full configuration approval**.

Reviewed account projection is now consumed by the actual domain row validator, bounded staging service and atomic eighteen-table workspace. Default credential-only restore continues refusing unproven numeric links. An explicitly unlinked graph still requires a current review baseline before promotion. Completion receipt replay bypasses the obsolete pre-commit generation, while fresh promotion rechecks proof immediately before the table swap. A persistent account-creation stamp protects restored profile/collection/canonical-field access against deleted/reused numeric IDs while allowing legitimate email edits. Current ordinary links keep their existing behavior.

The sealed, maintenance-owned asset journal publishes one verified archived image/font per slice using exclusive atomic file publication. Actual cursor-write failure, interrupted publication, forged local journals, changed bytes and pre-existing identical files are covered. Rollback deletes only newly owned unchanged bytes; unresolved asset cleanup preserves its fixture journal and table inventory. Issued-image display uses original recovered bytes only when its saved media proof matches, without leaking private paths.

Custom-font capture and recovery now includes actual SFNT/font bytes, deduplication and missing-upload fallback. The public design-font REST route is restricted to exact faces in the current font catalog; other private assets cannot be requested by hash. It verifies bytes again when serving, supports HEAD, keeps private paths out of response data/headers, and refuses changed files. Designer and email font URLs use that route. This does not change commercial renderer authority or expand the accepted production renderer font manifest.

Evidence under `output/program-recipient-20261004/`:

- `whole-reviewed-promotion-php85.json`: 93 actual capture/worker/account/file/publication/promotion checks; its deprecated test-only Reflection call was subsequently removed.
- `whole-object-account-promotion-php82.json`: 103 checks, including typed object review, actual profile read/save, current canonical field locks, repeated completed promotion, stale unlink refusal and exact issued Version preservation. All 36 base tables and 108 registered workspace names are removed; original business and all original options are unchanged.
- `whole-font-recovery-corrected-php82.json` and `whole-font-recovery-corrected-php85.json`: 20 actual ZIP/filesystem/font/REST/rollback checks per runtime. Every fixture and private journal is removed; original business and all original options are unchanged.
- `whole-source-semantics-php82.log` and `whole-source-semantics-php85.log`: 120 read-only checks per runtime against six actual source-store normalizers. Closed scope, strict scalar types, real dates, zero thresholds, duplicate identities and refusal to regenerate timestamps are covered. These validators are a component foundation; all thirty-one option policies and graph reconciliation are not yet complete.
- Existing domain staging, workspace/job, custom-font catalog and repository-safety regressions pass. No actual customer message, production release, entitlement or renderer authority is introduced.

Still open: complete semantic preflight for every fixed/dynamic option and delivery/legacy component; graph closure and application of reviewed object mappings; a unified full database/configuration/file journal with actual mixed same/fresh-site restore and failure recovery; coupling asset finalization to that full durable commit; native review/mapping/commit/resume controls and manual/scheduled product parity. An accepted eighteen-table domain promotion plus independently accepted file publication is **not** yet an accepted whole-state restore. Phase 6 stays open.

## Unified workspace and native worker, 7 October

The separate full recovery engine now stages and atomically replaces **twenty-one stores**: the original eighteen domain entities, legacy registry, and both delivery stores. Its target-sealed journal records raw configuration presence and autoload flags, both local generation markers, exact member descriptors, staging/design/cleanup cursors, and the durable commit receipt. Default credential-only restore retains its original eighteen-store scope and unproven-account refusal. Neither WordPress accounts/roles, worker jobs, the local recovery key nor commercial authority are imported.

All thirty-one fixed option policies and the bounded template recommendation namespace have semantic dispatch. Delivery projection pauses queued/preparing work, treats interrupted sending as uncertain, and retains terminal evidence. Restore pauses app mappings, email automation, pending notices and the source backup schedule. Source backup catalogs do not become claims that copies exist on the destination. Foreign provider credentials need reconnection.

Reviewed current/published certificate artwork can acquire a new revision with explicitly mapped media. The source revision, its canonical layout/hash, and every issued Version column remain unchanged. Recipient mappings use the current target account identity; reused account IDs remain refused. Image choices require the exact archived descriptor rather than a matching attachment name or host.

The native complete-restore job stores approval choices and an archive copy in immutable, encrypted private files. Each slice verifies the current job lease and progress, recreates current typed review, and compares the original approval fingerprint. After durable commit, its local receipt drives cleanup without requiring an obsolete generation or importing serialized authority. New job creation now joins the checked InnoDB maintenance fence; existing recovery jobs can still advance their own progress.

Startup checks the journal directly in SQL. An unresolved whole restore runs bounded recovery before schema work. While its journal remains, only the existing recovery dispatcher is registered. Terminal cleanup can continue after recovery renews its own lease, and a durably rolled-back journal never repeats the table swap after aliases have started disappearing.

Current evidence under `output/program-recipient-20261004/`:

- `whole-interruption-mapping-php82.json` and `whole-interruption-mapping-php85.json`: **139 checks per runtime**, including actual rename/settings/COMMIT failures; record-before-cursor replay; stale owner/revision refusal; lost maintenance lease; failed cleanup after partial alias removal; completed receipt replay; and fresh recovery-root/account/media mappings.
- `whole-native-worker-php82.json` and `whole-native-worker-php85.json`: **150 checks per runtime**, adding real coordinator/JobRunner/lease/progress/commit replay and exact intent/archive cleanup. Original business and core data are preserved; all 36 disposable tables and 297 registered workspace names are removed. The PHP 8.2 metadata audit identifies only ordinary `cron` and `action_scheduler_lock_async-request-runner` progress as changed original options; these are preserved, not reset. Neither run leaves a private journal.
- `whole-owned-fixture-cleanup-php85.json`: successful exact cleanup of a retained, rolled-back QA fixture after the interruption test exposed the renewed-lease cleanup issue. No configured-site SQL restore was used.
- `run-domain-bootstrap-gate.php` passes on both runtimes, covering unresolved recovery, schema deferral, recovery-only runtime, SQL read failure and return to gated legacy boot after cleanup.

An earlier test used the site's private recovery directory for one successful QA image publication. That exact unchanged QA hash was removed, with the directory preserved. Subsequent tests use their own private directories. The rejected follow-up test was corrected and rerun; it is not acceptance evidence.

Remaining engineering work: deeper configuration-to-staged-graph closure and nonempty email/media cases; explicit recovery of static email attachments and editable email media URLs; bounded source preflight/extraction performance; native backup/schedule/catalog/review/mapping/commit/resume controls and recovery guidance; refreshed recipient/staff and complete widget/editor/font HTTP journeys. The internal full engine and native worker are accepted for the recorded fixtures; the recovery product flow and Phase 6 are not complete. LearnDash and release validation remain separate later stages.

## Native recovery journey and atomic handoff, 7 October

Implemented `certloom-recovery` with manual capture, verified private catalog downloads, upload/safety capture, current account/object/artwork review, explicit replacement confirmation, background activity, pause/resume/retry, bounded cleanup and organization-controlled rollback. The screen registers before ordinary domain boot so an interrupted restore cannot hide its recovery controls. Existing Backup & Restore and credential tools link to it; legacy archive actions retain their original scope. Optional daily/weekly recovery schedules share the manual capture worker and existing schedule option. Cron only uses an already prepared recovery root.

Review drafts use target-sealed, uncached SQL storage with owner and expiry checks. A session mutex serializes review, discard and confirmation. Consuming a draft and creating its native job commit together in a short checked transaction, after private file copying. Scheduling errors retain the queued identity for watchdog recovery. Search suggestions use an authenticated, read-only REST route, bounded website queries and private response headers; current typed proofs revalidate the selections. Reused source IDs are not automatically suggested when their current identity digest differs.

Final journal removal and maintenance lease release now share one checked transaction. A real release SQL failure leaves the terminal journal discoverable. Native rollback refuses running workers and committed receipts, cancels an eligible paused/queued/failed job, and preserves exact live tables/settings while removing only its private working files.

Evidence: `whole-recovery-controls-php82.json` passes **162 checks**. `whole-native-handoff-php82.json` and `whole-native-handoff-php85.json` each pass **169 checks**, including real native daily/weekly scheduling, draft-consumption SQL rollback, repeated-confirmation reuse, filtered history and exact rollback. Each removes 36 disposable tables and 360 registered workspace names, preserves original business/core records and leaves no private journal. Only ordinary cron and Action Scheduler progress differs in original options. The connection-search regression passes **13 checks on each runtime**. `output/playwright/recovery-current-rest-layout-20261007.log` records actual Lance REST 200/400 responses, invalid-nonce 403, private response headers, 1440/390 layouts without page overflow, and labelled form fields. Desktop and phone screenshots are recorded alongside it. Actual populated connection-picker keyboard/pointer behavior and native final confirmation remain due.

The controls are implemented, but the remaining source graph/email-file/performance work and current visible recovery/customer/widget journeys are still open. This section does not close Phase 6, LearnDash or release validation.

## Static email files and portable artwork, 7 October

Recovery reference/asset components now use protocol v2, with strict v1 readers
retained. The outer envelope remains v1, domain remains v5 and registry remains
v4; plugin/schema versions and commercial authority are unchanged. Capture
includes actual safe static email attachment bytes with the native 10 MiB file
limit. Images also used as attachments share one content-addressed member.
Opaque documents stay outside the web root and have no public download route.

Typed review requires a current WordPress attachment holding exactly the saved
bytes, or explicit disconnection. It never imports source WordPress objects or
trusts a media name/ID. Mapped attachments retain the native 20 MiB combined
delivery limit. Editable visual image blocks, inline image attributes, table
backgrounds and CSS image URLs follow those reviewed connections. Source
revisions and issued Version content remain unchanged. Disconnecting the only
visual image leaves a small blank position instead of silently selecting a
factory email design. Older snapshots explain their missing attachment/URL
coverage. Foreign recovery now also clears legacy webhook credentials.

A real nonempty visual catalog exposed KSES converting `&#039;` to `&apos;`
between valid sanitization passes. Recovery accepts only those equivalent
encoded apostrophe spellings while preserving stored HTML bytes. Executable
markup removal, literal quotes and changed image URLs still fail validation.
No general entity decoding or sanitizer bypass was added.

Evidence under `output/program-recipient-20261004/`:

- `whole-email-files-php82.json` and `whole-email-files-php85.json`: **33 real
  checks each**, including actual ZIP PNG/PDF members, native visual/issued email
  settings, mapped target files/URLs, changed-file refusal, explicit
  disconnection, real document cursor SQL failure, retry adoption, rollback and
  full twenty-one-store commit. Each removes 36 disposable tables and 63
  registered workspace names, leaves no private journal, and preserves original
  business and all ordinary options.
- `whole-file-protocol-handoff-php82.json`: **169 current matrix checks** after
  protocol v2, including immutable issued bytes, fresh-root mappings, native
  worker/control/handoff/scheduling and failure recovery. All 36 disposable
  tables and 360 registered aliases are removed; original business/options are
  unchanged. Its PHP 8.5 refresh is next.
- `whole-reference-files-php82.log`/`php85.log`: **42 strict stream checks** each;
  `whole-file-envelope-php82.log`/`php85.log`: **48 envelope checks** each;
  `whole-email-entity-semantics-php82.log`/`php85.log`: **232 semantic checks**
  each; `whole-email-design-regression-php82.log`/`php85.log`: **259 native email
  regression checks** each. No diagnostics are present.

Initial file/canonical-markup fixture runs were rejected before promotion and
cleaned exactly; they are diagnostic records, not accepted evidence. The
remaining work is configuration-to-source/staged-graph closure, bounded
preflight/extraction performance, populated native review/confirmation controls,
catalog/retention guidance and refreshed recipient/staff/widget/editor journeys.
Phase 6 and release/LearnDash validation remain open.
