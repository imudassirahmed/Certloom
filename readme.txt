=== Certloom – Certificate Verification ===
Contributors: Ecapla
Tags: certificate verification, certificate, credentials, certificate registry, shortcode
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage certificates and publish exact-number verification with a native block, shortcode, CSV tools, and optional Elementor widgets.

== Description ==

> Development note for 1.5.6: Certloom continues as the same in-place plugin. The proven 1.5 registry remains authoritative while the next-generation domain and data tools stay behind development gates. This checkpoint hardens the renderer contract with independently verified assets, centralized QR modules, exact content-addressed font manifests, deterministic z/rotation rules, a single render coordinator, and canonical receipt-integrity hashes. It still does not ship a public PDF/PNG/JPG generator or switch the credential source of truth.

Certloom helps certification bodies, training providers, associations, auditors, and other organizations manage certificate records and publish public verification on WordPress.

Visitors enter the complete certificate number to view its current status and only the fields approved for public display. Letter case is ignored, while spaces, punctuation, and hyphens must match. Records stay in the site's WordPress database; Certloom does not require an external verification service.

= WordPress-first and self-hosted =

Certloom provides a practical certificate registry without requiring a separate SaaS platform or a page builder. The native block and shortcode work with WordPress itself, while Elementor integration remains optional.

= Publishing methods =

* **Native WordPress block** with six designs.
* **Shortcode** using `[certloom]` in the Classic Editor, a Shortcode block, templates, or other page builders.
* **Direct links** using `?certloom_number=YOUR-NUMBER` for QR-code destinations. These requests receive noindex and private-cache headers, although the number may still appear in browser history, infrastructure logs, or site analytics.
* **Optional Elementor widgets** when Elementor 3.10 or later is active: Professional, Meridian, Halo, Axis, Nova, and Forge.

Each Elementor widget has isolated content and style controls. Structural responsiveness is automatic and container-aware. Certloom is independent and is not affiliated with or endorsed by Elementor.

= Certificate management =

* Dedicated indexed database table.
* Add, edit, search, filter, sort, paginate, delete, and bulk-delete records.
* Valid, Suspended, and Withdrawn workflow statuses; Expired is normally calculated from the expiry date.
* Protected review handling for explicitly restored historical Expired records.
* Configurable core and custom fields with ordering and public-visibility controls.
* Fresh installations publish only certificate number and status by default.
* Removed custom fields are retired so their keys and stored values remain protected.
* Dedicated `manage_certverify` capability and optional Certificate Manager role.

= Import, export, and complete backup =

* CSV template generated from the current field schema, followed by mapping review.
* Partial updates preserve fields not included in the import.
* Blank mapped cells preserve existing values unless clearing is explicitly enabled. Status cannot be cleared, and a new row with a mapped-but-blank Status is rejected.
* Per-row validation, formula neutralization, and bounded file, row, column, and cell limits.
* Administrator-only Historical restore mode for trusted Certloom exports containing protected legacy Expired records.
* Versioned streaming JSON backup for lossless restoration of certificates, settings, fields, retired values, workflow sources, and timestamps.
* Restore preview before choosing exact snapshot replacement or a non-destructive certificate merge.
* Restore locking pauses Certloom writes during commit while public verification remains available.
* Chunked imports, indexed prefetching, deferred counts, and keyset exports reduce memory and query pressure on large registries.

= Security and privacy =

* Whole-number exact lookup; partial matches are never returned.
* Public output includes only fields explicitly enabled by the site owner.
* Private-cache and noindex headers for REST verification and supported direct-link requests.
* Bounded input, basic rate limiting, accessible announcements, and safe no-JavaScript verification.
* Identical same-page lookups share one in-flight request and stale responses cannot overwrite newer searches.
* No analytics, telemetry, remote scripts, remote fonts, or external certificate processing.
* Suggested privacy-policy wording is added to WordPress's Privacy Policy Guide.
* Certificate data and settings are retained on uninstall by default; administrators may opt into complete removal.

== Installation ==

1. Install the ZIP through Plugins > Add New > Upload Plugin, or upload the `certloom` folder to `/wp-content/plugins/`.
2. Activate Certloom.
3. Review Certloom > Field Manager and choose any additional fields that may appear publicly.
4. Add records manually or through Certloom > Import & Export.
5. Open Certloom > Appearance & Embed and choose the block, shortcode, or optional Elementor method.
6. Review Certloom > Access & Settings for permissions, branding, privacy, and uninstall preferences.
7. After widget CSS changes, use Elementor > Tools > Regenerate CSS & Data and clear site caches.

== Frequently Asked Questions ==

= Can I use Certloom without Elementor? =

Yes. The native block, shortcode, administration, REST verification, CSV tools, direct links, roles, and status handling work independently.

= What happens when a certificate expires? =

Expiry is calculated from the expiry date during normal administration and CSV workflows. Extending the date renews the certificate unless it is Suspended or Withdrawn. Restored historical Expired records remain protected until reviewed.

= Can a CSV update erase fields I did not import? =

No. Unmapped values are preserved. Blank mapped cells on existing records are also preserved unless clearing is explicitly enabled. Status cannot be cleared.

= What is the difference between CSV export and Complete backup? =

CSV is intended for spreadsheet interchange. Complete backup is a versioned streaming JSON format for lossless restoration, including settings, field definitions, retired values, workflow sources, and timestamps.

= Does verification work without JavaScript? =

Yes. The form can submit a normal GET request and render the result on the server. JavaScript provides the faster in-page experience.

= Is certificate data sent to Ecapla? =

No. Records stay in the site's WordPress database and verification is performed by that site.

= Is data deleted on uninstall? =

Not by default. Enable permanent data removal in Certloom > Access & Settings before deleting the plugin only when that is intended.

= Where can I get support? =

After publication, use the Support tab on the WordPress.org plugin page. Include the WordPress, PHP, and Certloom versions, clear reproduction steps, and relevant error text. Never post private certificate data, backup files, credentials, or personal information in a public topic.


== Screenshots ==

1. Public certificate verification page with status guide.
2. Appearance & Embed admin screen showing the block, shortcode, and Elementor widget options.
3. Professional Elementor widget design in the editor preview.
4. Meridian Elementor widget design in the editor preview.

== Changelog ==

= 1.5.6 =
* Harden the renderer contract before selecting a PDF/image engine: z-order is now unique and deterministic, arbitrary rotation is normalized and its rotated bounding box must remain on-page, and canonical JSON rejects unsupported runtime values/non-finite floats.
* Add independent local-asset inspection that verifies actual PNG/JPEG bytes, MIME, SHA-256, size, dimensions and bounded pixel counts instead of trusting resolver metadata.
* Centralize QR encoding before renderer dispatch so PDF/PNG/JPEG receive the exact same validated module matrix, error-correction choice and quiet-zone policy.
* Add a content-addressed font manifest requiring the exact declared family/weight/style font bytes; renderers may not silently synthesize or substitute a historical certificate font.
* Add the Artifact Render Coordinator as the single production rendering choke point for capability checks, exact font binding, output-size limits, controlled failures and integrity receipts.
* Upgrade artifact receipts to a v2 schema with a canonical receipt-integrity hash so request/layout/renderer metadata tampering is detectable in addition to output-byte tampering.
* Strengthen verification-URL validation by rejecting embedded credentials and URL fragments before QR/render preparation.
* Expand focused rendering-contract coverage from 36 to 61 assertions while preserving 43/43 standalone regression suites and 180/180 PHP lint checks.
* Keep the real WordPress/MySQL authority-cutover gate and real PDF/image output benchmark deliberately open; this release does not expose artifact generation publicly.

= 1.5.5 =
* Add the renderer-independent canonical certificate layout schema with strict A4/Letter physical geometry, stable UUIDv4 element IDs, bounded positioning/rotation/opacity, typed elements, local-only asset references, and fail-closed unknown-property handling.
* Add shared Unicode first-strong text direction resolution, including Urdu/Arabic RTL handling, explicit direction overrides, deterministic overflow policy, and registered-font machine keys rather than raw CSS/font injection.
* Add resolve-once merge/QR/local-asset preparation so PDF and image renderers receive the same primitive immutable document instead of independently resolving certificate data.
* Add deterministic layout, render-request, renderer-environment, and final artifact SHA-256 receipts so historical outputs can be audited against their exact values/assets/renderer context.
* Add an explicit PDF/PNG/JPEG renderer registry and fail-soft runtime capability detector so missing optional renderer requirements cannot take down the registry or public verifier.
* Add permanent rendering acceptance fixtures for English, Urdu RTL, mixed-script text, long-name shrink behavior, non-quarter rotation, and verification QR placement.
* Keep arbitrary HTML/CSS and remote layout assets out of canonical templates; schema v1 accepts local PNG/JPEG assets only.
* Keep all new-domain authority/data-tool cutover flags disabled and preserve the legacy 1.5 registry/verifier until the real disposable WordPress + MySQL/MariaDB gate passes.
* Record the current PDF-engine decision as deliberately open: current TCPDF/tc-lib-pdf releases require PHP 8.2, while mPDF remains compatible with the plugin's PHP range but still must pass Certloom's real Urdu/RTL/rotation/output-parity benchmark before adoption.

= 1.5.4 =
* Continue the same Certloom plugin in place; legacy 1.5 registry/verification remains authoritative and all new-domain cutover flags remain disabled by default.
* Add a durable WordPress-local job engine with bounded slices, queued/running/paused/succeeded/partial/failed/cancelled states, one-shot cron continuation, watchdog recovery, worker leases, crash recovery, explicit Retry/Pause/Resume/Cancel controls, and bounded retention.
* Add randomized protected job-file storage and short-lived mapping-session cleanup for sensitive import/backup/restore files.
* Add resumable new-domain CSV mapping/preflight/commit with real fgetcsv parsing, multiline support, bounded files/rows/columns/cells, cross-request duplicate staging, explicit omitted-vs-blank PATCH semantics, immutable correction Versions, and durable per-row commit receipts.
* Add a Series-owned globally unique certificate-number assignment table so immutable correction Versions may retain the same human certificate number without allowing a different credential to steal it.
* Persist Core-readable compliance state on Credential Series and explicit previous-Version lineage so compliance truth/history remain interpretable independently of future Pro modules.
* Add complete new-domain resumable backup with configuration snapshotting, domain-generation consistency checks, chained integrity hashing, crash-tail truncation, protected download, and semantic restore validation.
* Add staging-first Replace and conservative Merge restore foundations with two-step review → explicit commit, private graph validation, atomic promotion/rollback journaling, completion receipts, and a shared maintenance lock that pauses both legacy and new-domain writes while keeping verification readable.
* Add a hidden-by-default protected Data Tools (New) preview using the dedicated data-tools capability, nonces, real-upload checks, private files, explicit destructive confirmations, safe job diagnostics, and protected backup streaming.
* Preserve all six existing Elementor verification widgets (Professional/default, Meridian, Halo, Axis, Nova, Forge) and add a regression gate that requires them to remain registered and delegated to the shared renderer.
* Define future PDF/PNG/JPG certificate actions as extensions of those existing shared-renderer widgets; no duplicate image-specific verification-widget family will be introduced.
* Harden uninstall cleanup for operational job/import/restore staging state while permanent credential data remains preserved by default.
* Keep real source-of-truth cutover blocked until the disposable WordPress/MySQL integration gate proves dbDelta/indexes/transactions/atomic table promotion and a fresh migration snapshot still matches the live legacy source.

= 1.5.3 =
* Add granular credential, issuance, recipient, program, issuer, template, analytics, integration, data-tools, and settings capabilities while preserving access for roles with the legacy broad Certloom capability.
* Add a hidden-by-default new-domain admin preview for server-paged Recipients, Programs, Issuers, and Credentials with field-level PATCH semantics and lifecycle actions routed through the transaction-backed command service.
* Strengthen cutover gating so new admin readiness alone cannot switch authority while legacy import/backup/restore tools can still mutate the legacy registry.
* Add source-snapshot cutover checks and a dormant legacy-write guard for the eventual atomic authority switch.

= 1.5.2 =
* Continue the same Certloom plugin update line with no separate plugin/product fork.
* Add guest VisibilityPolicy + PublicCredentialPresenter so the new verification path exposes only an intentionally small public-safe view model and hides private/recipient-only/organization-only credentials from guests.
* Add a second, hard-gated `certloom/v1/credential` REST adapter for checksummed public Credential Series IDs; the existing 1.5 `/verify` route remains live and authoritative.
* Scope public rate-limit buckets by operation and make the new Credential Series verification path fail closed if its protective counter cannot be persisted.
* Add durable migration state with resumable preflight/commit phases, exact source count/max-ID boundaries, and legacy data-generation tracking.
* Add bounded legacy migration commit processing and an atomic idempotent writer that creates Recipient → Series → Version → current-Version pointer → migration event in one short transaction.
* Stop migration at the first unsafe write and keep the public cutover disabled until migration completes.
* Add a source-generation gate: any legacy certificate/settings/field mutation after preflight blocks migration and requires a fresh preflight snapshot.
* Add explicit write-authority state so the new public verifier cannot become authoritative while the legacy 1.5 admin/write paths are still active. Actual authority cutover is deferred to the 1.5.3 registry/admin milestone.
* Add pure tests for public presentation/privacy, migration commit/state behavior, and the public cutover gate.

= 1.5.1 =
* Begin the next-generation Certloom architecture as an in-place 1.5.0 → 1.5.1 plugin update without destructively migrating the proven registry.
* Add a Certloom namespace/autoloader and additive domain-schema manager with migration locking and schema-health checks.
* Add recipients, programs, issuers, credential-series, credential-version, and credential-event tables as the additive next-generation domain foundation.
* Add pure Core lifecycle states, four-layer Status Service, lifecycle transition rules, and checksummed non-enumerable public credential IDs.
* Add initial repository boundaries for recipients, programs, issuers, credentials, versions, and append-only lifecycle events.
* Add transaction-backed lifecycle commands for issue, suspend, revoke, reinstate, and void so state and audit events change together.
* Extend explicit opt-in uninstall cleanup to the additive next-generation domain storage while retaining data by default.
* Add lightweight domain/bootstrap tests. The legacy verification table remains authoritative until the dedicated migration/verification milestones.
* Add the internal golden-path Credential Series verification service with direct public-ID binding, checksum-before-DB rejection, read-time status evaluation, and fail-closed handling for missing/current-Version or database errors. The legacy public verification route remains authoritative until real WordPress/MySQL and migration parity gates pass.
* Add a guarded source-only WordPress/MySQL integration-gate harness for real schema/index, repository, lifecycle transaction, verification, and legacy-registry non-mutation checks on disposable/staging installs.
* Add a pure legacy-row migration mapper and compatibility flag so stored/ambiguous legacy Expired records cannot become Valid when moved to Credential Series/Versions; preserve date-derived expiry, Suspended, Withdrawn→Revoked semantics, custom fields and deterministic source idempotency.
* Add a non-writing legacy migration parity preflight that independently compares the expected 1.5 public verdict with the new Core Status Service verdict, summarizes status/error counts, and caps detailed errors before any future migration commit.
* Minimize the internal Series verification query to identity/lifecycle fields only and keep raw credential/snapshot/source data out of the generic verification transport shape.

= 1.5.0 =
* Fixed Elementor Flexbox Container sizing so all Certloom widgets occupy the available row and remain centered; Professional stays capped at 680px.
* Finalized the public identity as Certloom – Certificate Verification with the requested WordPress.org slug and text domain `certloom`.
* Fixed an activation fatal introduced during the pre-release rename by restoring the correct settings, fields, and import include paths.
* Restored Elementor width safety after the rename so Professional remains centered at up to 680px and Meridian, Halo, Axis, Nova, and Forge fill their Elementor containers without overflow.
* Aligned the native block, admin navigation, shortcode, direct-link parameter, REST namespace, and Elementor widget identifiers with Certloom while preserving the existing database schema and stored data.
* Prepared the corrected WordPress.org review-resubmission package and directory assets.

= 1.4.9 =
* Added an expiring restore-maintenance lock that pauses Certloom writes during a resumable complete restore.
* Split restoration into exact Replace mode and non-destructive Merge mode with preview counts for current-only certificates.
* Added registry and configuration checks so unexpected changes abort before the atomic restore commit.
* Made complete-backup generation reject mixed-state files when records or configuration change during export.
* Preserved public verification during restore and expanded restore-state cleanup.

For the complete release history, see `changelog.txt` included with the plugin.

== Upgrade Notice ==

= 1.5.6 =
Premium renderer-contract hardening: centralizes QR/font/asset truth, enforces deterministic z/rotation behavior, adds one render coordinator and receipt-integrity hashes, while keeping the live legacy registry/verifier authoritative and artifact output unexposed until real engine proof passes.

= 1.5.4 =
In-place development checkpoint adding premium-grade new-domain jobs/import/backup/restore infrastructure while keeping the proven legacy registry and public verifier authoritative until the real WordPress/MySQL cutover gate passes.

= 1.5.0 =
Review-resubmission release finalizing the Certloom identity, fixing activation after the rename, and preserving the existing certificate data and schema.

= 1.4.9 =
Restore-integrity update with exact snapshot replacement, optional certificate merge, global write locking, and concurrent-change detection.
