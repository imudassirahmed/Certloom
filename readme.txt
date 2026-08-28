=== Certloom – Certificate Verification ===
Contributors: Ecapla
Tags: certificate verification, certificate, credentials, certificate registry, shortcode
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.82
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage certificates and publish exact-number verification with a native block, shortcode, CSV tools, and optional Elementor widgets.

== Description ==

> Release note for 1.6.82: I18N-03 now has a deterministic reviewed-language acceptance boundary over the frozen candidate-intake workflow. Human attestation must bind the exact candidate-intake ZIP, candidate PO, current POT and source-key set; reviewed acceptance additionally requires 100% translated coverage, zero fuzzy entries, safe placeholders/HTML/bidi/plurals, browser/meaning review evidence, and explicit mixed-direction review for RTL locales. Acceptance stays external, preserves the exact candidate archive and review evidence, emits deterministic PO/MO/WordPress-Jed integration artifacts plus a reviewed manifest proposal, and never mutates Certloom or manufactures linguistic review. No locale is newly claimed as reviewed in this release; genuine human translation/review content still precedes BAK-02.

> Release note for 1.6.80: I18N-03 now includes a deterministic, translation-free human-review intake kit so qualified translation teams receive the exact POT plus source/context/risk metadata, stable source-key identities, review checklist and SHA-256 evidence without Certloom manufacturing translations or changing locale support status. Review kits must be generated outside the plugin tree and actual candidate/reviewed PO content still requires genuine human linguistic review under the frozen 1.6.79 quality contract. BAK-02 remains after I18N-03 content acceptance.

> Release note for 1.6.79: I18N-03 now has a deterministic language-pack quality pipeline before any locale is allowed to be called reviewed. The shipped manifest binds twenty roadmap targets to the exact Certloom POT but keeps them planned until real human-reviewed PO content passes complete coverage, zero-fuzzy, placeholder/plural/HTML/bidi safety and deterministic MO + WordPress block-editor JSON certification. The release builder enforces the contract and Certloom registers its bundled `/languages` directory at the safe `init` boundary. No language is falsely marketed as reviewed in this release; first reviewed pack content follows before Scheduled Backup & Recovery.

> Release note for 1.6.78: I18N-02 separates administrator UI locale, Program credential locale and per-element Auto/LTR/RTL direction. Program Profiles can map normalized credential locales to published certificate designs by stable Template machine key with exact-locale → base-language → declared-fallback → existing Program design resolution, while Manual/Bulk Issuance freeze the effective locale, direction and design provenance into immutable review/version evidence. Configured locale designs fail closed if they are no longer published, and mixed-script certificates continue to use the existing first-strong element direction resolver. No locale-specific renderer, hard-coded Urdu RTL branch, remote translation service, browser-only design authority or production renderer/database authority is introduced. I18N-03 reviewed language quality packs are next; backups, Wizard and concrete LMS adapters remain separate.

Certloom helps certification bodies, training providers, associations, auditors, and other organizations manage certificate records and publish public verification on WordPress.

Visitors enter the complete certificate number to view its current status and only the fields approved for public display. Letter case is ignored, while spaces, punctuation, and hyphens must match. Records stay in the site's WordPress database; Certloom does not require an external verification service.

= WordPress-first and self-hosted =

Certloom provides a practical certificate registry without requiring a separate SaaS platform or a page builder. The native block and shortcode work with WordPress itself, while Elementor integration remains optional.

= Publishing methods =

* **Native WordPress block** with six designs.
* **Shortcode** using `[certloom]` in the Classic Editor, a Shortcode block, templates, or other page builders.
* **Clean verification links** using `/certloom/verify/{token}/` when pretty permalinks are enabled, with a query-string fallback on plain permalinks. Existing `?certloom_number=YOUR-NUMBER` links remain compatible. Direct verification requests receive noindex and private-cache protections, although the identifier may still appear in browser history, infrastructure logs, or site analytics.
* **Credential Profile** using `/certloom/credential/{token}/`, `[certloom_credential number="..."]`, the native Credential Profile block, or the semantic Elementor Credential Profile widget.
* **Local verification QR** on successful verification/profile results, encoding only the canonical Certloom verification URL with no remote QR service.
* **Optional Elementor verification widgets** when Elementor 3.10 or later is active: Professional, Meridian, Halo, Axis, Nova, and Forge. Their identities and saved settings remain preserved.

Each Elementor widget has isolated content and style controls. Structural responsiveness is automatic and container-aware. Certloom is independent and is not affiliated with or endorsed by Elementor.

= Certificate management =

* Dedicated indexed database table.
* Add, edit, search, filter, sort, paginate, delete, and bulk-delete records.
* Premium registry status views for All, Valid, Suspended, Withdrawn, Expired, and credentials expiring within 30 days.
* Status reasons for administrator lifecycle context, with optional public visibility controlled by Field Manager.
* Locked-private internal administrator notes that cannot be exposed through public verification output.
* Valid, Suspended, and Withdrawn workflow statuses; Expired is normally calculated from the expiry date.
* Protected review handling for explicitly restored historical Expired records.
* Optional secondary verification proof per credential, with dedicated generate/rotate/clear controls and no plaintext proof storage.
* Optional sequential numbering for new administrator-created credentials with prefix, suffix, start/floor, minimum digits, atomic collision-safe allocation, manual override, and a non-reserving preview. Existing issued numbers are never renumbered.
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
* Versioned streaming JSON backup for lossless restoration of certificates, settings, fields, retired values, workflow sources, timestamps, and durable sequential-numbering state.
* Restore preview before choosing exact snapshot replacement or a non-destructive certificate merge.
* Restore locking pauses Certloom writes during commit while public verification remains available.
* Chunked imports, indexed prefetching, deferred counts, and keyset exports reduce memory and query pressure on large registries.

= Security and privacy =

* Whole-number exact lookup; partial matches are never returned.
* Optional secondary proof is checked only after the exact certificate-number match; it is never searchable, routable, or accepted as a credential identifier.
* Public output includes only fields explicitly enabled by the site owner.
* Private-cache and noindex headers for REST verification and supported direct-link requests.
* Bounded input, basic rate limiting, accessible announcements, and safe no-JavaScript verification.
* Identical same-page lookups share one in-flight request and stale responses cannot overwrite newer searches.
* No analytics, telemetry, remote scripts, remote fonts, or external certificate processing.
* Suggested privacy-policy wording is added to WordPress's Privacy Policy Guide.
* WordPress Privacy Tools can export Certloom certificate/recipient data associated with a requested email without exposing internal administrator event fields.
* Personal-data erasure is conservative by default: issued certificate evidence is retained and reported; sites may opt into transaction-backed anonymization of mutable normalized Recipient profiles without rewriting immutable issued Versions.
* Private one-way identity history allows later access requests to find retained evidence after mutable email anonymization; it is never part of public verification output.
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

Yes. The form can submit a normal same-page verification request and render the result on the server. When a secondary proof is configured, that proof is submitted only in the POST body and is never added to the shareable verification URL. JavaScript provides the faster in-page experience.

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

= 1.6.71 =
* Add ANL-02 Issuance Analytics from append-only `credential.issued` events and immutable Version snapshots, with bounded 7/30/90/365-day daily/weekly/monthly trends.
* Report issuance by Program, Template, organization/issuer and Batch without treating relationship-correction Versions as new issuance or borrowing mutable current relationships for historical attribution.
* Add a current non-revoked issued-Version 30/60/90-day expiry forecast plus permanent-current counts; keep renewal reminders/re-certification in separate REN-01.
* Add Verification / Issuance tabs to the Analytics workspace and complete the ADM-02 dashboard analytics preview with issuance/verification charts, failed lookups and top credential/Program/Template summaries.
* Keep ANL-02 read-only: no visitor telemetry, duplicate analytics table, backup-format expansion, ANL-03 engagement metrics or IMP-03 export scope; normalized-domain and renderer production authority remain closed.

= 1.6.49 =
* Eliminate PHP 8.4 implicit-nullable parameter deprecations with explicit nullable declarations that remain PHP 7.4 compatible.
* Add translator context to ambiguous Search action/field labels and entity/maintenance status labels before formal translation packs mature.
* Keep the native Verification block editor and server-rendered Search default on the same contextual translation key.
* Extend deterministic translation-source extraction to preserve Gutenberg JavaScript `_x()`/plural contexts and cache-bust the changed Verification block editor asset.
* Keep external renderer/database/WordPress 7.1/PERF-01 gates unchanged and fail closed.

= 1.6.48 =
* Harden public rate limiting with a strict network ceiling so User-Agent and IPv6 interface-address rotation cannot create unlimited fresh capacity; use integer minute buckets and fail-closed persistent-counter reads.
* Prefer durable private job storage outside the public webroot before WordPress/system temp, while preserving explicit configuration and path/symlink protections.
* Move translated runtime/provisioning bootstrap work to `init` priority 1 for WordPress 6.7+ translation timing correctness.
* Ship a reproducible `languages/certloom.pot` covering PHP, native-block JavaScript, and translatable block metadata.
* Generate new normalized PublicCredentialIds with the GF(2^5) v2 checksum while retaining legacy v1 compatibility; final normalized-domain acceptance requires v2.
* Make spreadsheet formula neutralization fail safe on malformed UTF-8.
* Keep WordPress 7.1 metadata, `PERF-01` database index changes, renderer authority, normalized-domain authority, Template publication, Preview, and Designer proof-gated.

= 1.6.47 =
* Fix Credential Profile block localization so translatable default heading/not-found copy is resolved at runtime instead of being pinned as literal English `block.json` attribute defaults.
* Keep editor and server defaults aligned through `wp.i18n` and PHP translation functions while preserving user-saved custom block text unchanged.
* Bump only the changed Credential Profile block metadata/editor asset version to 1.6.47 so WordPress/browser caches cannot retain the old editor script.
* Record the legacy-registry `created_at`/`issue_date` index gap as a `PERF-01` real-database EXPLAIN/migration follow-up; do not ship an unproven schema change without MySQL/MariaDB execution.
* Keep `PDF-04`, normalized-domain acceptance-v4, Template publication, authoritative Preview and Designer authority unchanged and fail-closed.

= 1.6.46 =
* Close the final normalized-domain acceptance publication TOCTOU gap by retaining one owner-matched maintenance lease from the complete graph fingerprint through atomic publication.
* Revalidate the exact base-gate bundle, Certloom runtime payload, WordPress/PHP/database environment, and all six stored proof/report bytes while the publication boundary is retained.
* Recheck the frozen normalized graph/configuration boundary immediately before rename, require explicit owner-active lock continuity, and fail closed if the boundary is lost.
* Withdraw newly published acceptance evidence if maintenance-lock cleanup cannot be confirmed, so an uncertain cleanup cannot leave a successful-looking canonical acceptance directory.
* Advance normalized-domain acceptance bundle/index to v4; keep normalized-domain authority and renderer authority closed until the genuine external database and PDF-04 gates pass.

= 1.6.45 =
* Bind completed staging restore and cutover evidence to the normalized-domain generation that existed when each rehearsal passed, so later official domain mutations immediately stale those proofs.
* Advance staging restore/cutover evidence to v5 and normalized-domain acceptance bundle/index to v3 so older post-rehearsal-state-unbound evidence cannot qualify.
* Make the final real-host acceptance collector compute a fresh full normalized-domain graph fingerprint and require exact parity with the cutover rehearsal hash, configuration hash, record/entity counts, and generation.
* Catch direct SQL/data drift that bypasses Certloom's domain-generation marker while exporting only privacy-safe graph hashes/counts and a hashed generation identity.
* Keep normalized-domain authority and renderer authority closed until the genuine external database and PDF-04 gates pass.

= 1.6.44 =
* Advance `RuntimeEnvironmentEvidence` to v2 with a privacy-safe configured database/table-instance SHA-256, closing same-software cross-database acceptance ambiguity.
* Advance all six normalized-domain stored evidence schemas and real-host gate evidence/base/final acceptance contracts so older instance-unbound evidence fails closed.
* Require the final acceptance collector to pin the exact current stored-evidence schema versions independently of provider validation.
* Harden the real-host base-gate runner so rejected in-source destinations cannot create parent-directory residue before source-isolation validation.
* Keep normalized-domain authority and renderer authority closed until the genuine external database and PDF-04 gates pass.

= 1.6.43 =
* Add canonical `RuntimeEnvironmentEvidence` binding normalized-domain acceptance to the exact WordPress, PHP, database server/version, environment type, and multisite execution boundary as well as the exact Certloom payload.
* Advance isolated DB proof to v3, gated-domain acceptance to v6, and populated preflight/staging migration/restore/cutover evidence to v3 so older environment-unbound evidence cannot qualify.
* Make multi-slice preflight/migration/restore/cutover runs fail closed if the runtime/database environment changes after the run begins, even when Certloom bytes and version remain unchanged.
* Make real-host gate environment capture delegate to the same production contract and reject final acceptance when any stored proof came from a different WordPress/PHP/database environment.
* Add dedicated behavioral environment evidence coverage and cross-environment acceptance regressions while keeping renderer and normalized-domain production authority closed pending genuine external acceptance.

= 1.6.42 =
* Add a canonical source-only real WordPress/MySQL acceptance ceremony so four gate transcripts are captured under one exact run identity instead of being manually stitched together.
* Advance real-host transcript evidence to v2 with exact Certloom payload, gate/helper SHA-256, WordPress/PHP/database environment, and one machine-readable `EVIDENCE_JSON` record.
* Add atomic `run-base-gates.sh` publication with exact required PASS-marker validation, transcript hashing, source isolation, and stale/cross-run/cross-environment rejection.
* Add a privacy-safe normalized-domain acceptance collector that joins those four real-host gates with fresh passing database proof, gated-domain proof, populated preflight, staging migration, staging restore, and staging cutover evidence.
* Publish only canonical hashes plus privacy-safe reports for stored proof/rehearsal results; never export raw backup paths, ownership tokens, credential values, or failure examples. Normalized-domain authority and renderer authority remain closed until genuine external acceptance passes.

= 1.6.28 =
* Add a seventh permanent `PDF-04` fixture covering rectangle, ellipse, line, local image contain, logo cover, signature fill, opacity, and non-text arbitrary rotation.
* Advance renderer acceptance to v6, independent verification report to v4, and candidate bundle to v3 with explicit primitive-capability coverage; six-fixture evidence is now stale.
* Harden the concrete mPDF candidate for non-text SVG rotation and verified local raster cover placement, and re-prove raster asset identity immediately before both mPDF and GD consumption.
* Freeze all six legacy 1.6.27 golden artifact hashes and Render Plan hashes unchanged while adding the seventh capability case.
* Keep `PDF-04`, production PDF/PNG/JPEG, Template publication, authoritative Preview, Designer authority and normalized-domain authority closed until a real renderer-capable host passes the seven-fixture suite.

= 1.6.27 =
* Add concrete, authority-closed `MpdfPdfRenderer` and `GdRasterRenderer` candidates so PDF-04 no longer depends on external one-off RendererInterface implementations.
* Add a narrow native-GD driver, deterministic 96-DPI PNG/JPEG execution, exact QR/shape/line/image placement, and bounded Arabic/Perso-Arabic shaping for the permanent Urdu/mixed-script fixtures.
* Add length-preserving deterministic normalization of volatile mPDF timestamp/UUID/file-ID metadata before strict A/B evidence comparison.
* Keep shared Render Plan v1 and all frozen plan hashes unchanged; fail closed for unresolved multi-face fallback and typography/image cases that have not yet earned production acceptance.
* Wire the canonical source-only production bootstrap/runtime probe to the concrete candidates while keeping them unregistered and `accepted.json` absent.
* Keep PDF-04 and all renderer-backed product authority open/gated until the real dependency-prefixed candidates pass the six-fixture PDF/PNG/JPEG acceptance suite.

= 1.6.25 =
* Add a canonical source-only PDF-04 production-candidate producer that executes the six frozen fixtures through the real `ArtifactRenderCoordinator` for PDF + PNG + JPEG, twice, using exact content-addressed fonts.
* Bind candidate manifests to actual source archives, dependency locks, renderer identity/version/formats/requirements/context, and reject host-local paths/URLs from portable renderer context.
* Emit ArtifactReceipt v4 for every production-candidate artifact and require byte-identical A/B output before a bundle can be verified.
* Keep the candidate-evaluation authority gate source-only under `tests/`; no evaluation bypass or `accepted.json` is shipped in the installable plugin.
* Add a standard external bootstrap/producer workflow so the next renderer-capable disposable host can generate the exact bundle consumed by Certloom's independent v3 verifier/v5 acceptance chain.
* Preserve PDF-04 as open and keep Template publication, production artifacts, authoritative Preview, Designer authority and normalized-domain readiness gated.

= 1.6.24 =
* Expand `PDF-04` acceptance end-to-end from PDF+PNG to **PDF+PNG+JPEG**, matching the roadmap and existing artifact-format contract.
* Advance `RendererAcceptanceContract` to v5, `RendererVerificationReport` to v3, `RendererReleaseManifest` to v2, and the acceptance bundle to v2 with exact JPEG renderer/artifact bindings.
* Add deterministic JPEG golden references for all six permanent fixtures and independently decode the verification QR from both PNG and JPEG outputs.
* Add candidate-to-frozen-golden structural comparison for PDF, PNG, and JPEG so mutually consistent but visually wrong output cannot satisfy production acceptance.
* Preserve the shared Render Plan, exact-font, receipt-v4, no-network, deterministic, release-authority, and runtime-authority boundaries; 1.6.24 intentionally ships no accepted renderer manifest.

= 1.6.23 =
* Enforce release-bound renderer authority inside the single ArtifactRenderCoordinator choke point before any renderer can execute.
* Separate release acceptance for Template publication from per-host artifact runtime checks; publication no longer depends on whether this particular host can currently load PDF/PNG engines.
* Authorize artifact formats independently, so PDF execution checks the accepted PDF candidate/runtime and PNG checks the accepted PNG candidate/runtime without accidental cross-format coupling.
* Add an application-level artifact-authority contract for explicit testability while the production default remains the fail-closed RendererAuthorityGate.
* Keep `PDF-04`, production artifacts, authoritative Preview, Designer authority, and normalized-domain readiness flags gated; 1.6.23 intentionally ships no accepted renderer manifest.

= 1.6.22 =
* Add `RendererReleaseManifest` v1: a release-bundled, version-bound authority record derived only from production-candidate `RendererAcceptanceContract` v4 evidence.
* Add a fail-closed `RendererAuthorityGate`; renderer authority is never a mutable option/admin toggle and automatically becomes stale across a Certloom version change.
* Bind future runtime authority to exact accepted PDF/PNG renderer ID, version and candidate-manifest SHA-256, plus live host requirement checks before artifact execution.
* Wire Template publication to the release-bound authority gate with transaction locking, immutable current-revision ownership checks, optimistic revision proof and idempotent publication. Current 1.6.22 intentionally has no `accepted.json`, so publication remains disabled.
* Add a read-only System Status renderer-authority row and production `resources/renderer/README.txt` documenting the closed gate.
* Add a dedicated renderer-authority regression suite and preserve all existing renderer/Template/verification/data-safety gates.

= 1.6.21 =

* Bind exact independent-verifier runtime/tool/package/script provenance for PDF-04.
* Add RendererVerificationReport v2 and fail-closed cross-linked acceptance import.
* Advance RendererAcceptanceContract to v4; development references still cannot qualify.
* Add the standard source-only production-bundle verification entrypoint.
* Keep production PDF/image, Template publication, authoritative Preview and Designer authority gated.

= 1.6.20 =

* Add `RendererCandidateManifest` v1 for exact package/source/dependency-lock provenance and the CertloomVendor in-process/no-network/no-process policy.
* Advance `RendererAcceptanceContract` to v3 so production evidence must bind an independently verified artifact bundle and exact candidate-manifest hashes.
* Add a source-only acceptance-bundle verifier that rechecks actual PDF/PNG hashes, deterministic A/B bytes, page/raster geometry, QR decode, PDF-to-PNG parity, frozen manifests, candidate source/lock bytes, and ArtifactReceipt v4 identity.
* Ensure development golden references can exercise the verifier but can never qualify as `PDF-04` production acceptance.

= 1.6.19 =
* Add renderer-independent `certloom-render-plan` v1 so future PDF/image adapters consume one shared Certloom-owned physical composition plan.
* Centralize cross-adapter geometry, rotation, physical text alignment/unit conversion, image fit/crop, shape/line primitives, and QR module placement before adapter execution.
* Freeze a deterministic six-fixture render-plan manifest and advance renderer acceptance evidence to v2 with exact plan-hash binding.
* Advance ArtifactReceipt to v4 so future artifact receipts bind the exact shared render-plan hash.
* Keep `PDF-04`, artifact authority, Template publication, authoritative Preview, Designer output, and normalized-domain authority gated until real external acceptance passes.

= 1.6.18 =
* Add an exact local-font delivery boundary for artifact rendering: the content-addressed FontManifest must resolve to byte-verified local TTF/OTF assets before a text renderer executes.
* Reserve coordinator-owned runtime font state so caller-supplied filesystem paths cannot enter the portable render-request identity or renderer boundary.
* Add strict `PDF-04` renderer-acceptance evidence requiring all six permanent fixtures, PDF+PNG hashes, exact-font/RTL/17.5° rotation/QR decode/visual-parity/local-only/no-network/fail-soft/determinism checks.
* Add a development-only renderer runtime probe for the future dependency-prefixed mPDF benchmark candidate; it performs no network or process execution and currently reports missing mbstring, gd, and the bundled prefixed renderer.
* Correct stale ArtifactReceipt documentation from schema v2 to the actual production schema v3 and update Setup Wizard/System Status wording to reflect current QR/Template truth.
* Keep `PDF-04`, Template publication, authoritative rendered Preview, Designer authority, and all normalized-domain readiness flags gated until their external acceptance environments genuinely pass.

= 1.6.17 =
* Add premium Template assignment administration across Global, Issuer, Program, and Batch scopes with searchable/paginated targets and Template candidates.
* Add explicit None/inherit semantics, effective assignment source and fallback-depth visibility, and one shared Batch → Program → Issuer → Global policy used by repository and admin workflows.
* Add optimistic assignment concurrency checks and in-transaction Template re-locking so archive/assign races fail closed rather than leaving live assignments on archived Templates.
* Prevent archiving actively assigned Templates and fail closed when a stored active assignment references an unavailable/archived Template.
* Add integrity-checked candidate metadata without pretending it is authoritative rendered Preview; publishing/Preview/Designer remain production-renderer gated.
* Fix the main Templates list pagination omission and update the normal-authority Templates placeholder so it accurately reflects the implemented-but-gated Template system.
* Expand rollback-only and disposable WordPress/MySQL acceptance proofs to exercise Template set/stale/clear/fallback/archive semantics, and advance acceptance evidence to v4 so older receipts cannot certify TPL-03.
* Keep normalized-domain write/admin/data-tool flags false.

= 1.6.16 =
* Add six premium factory template families across A4/Letter portrait/landscape variants (24 canonical layouts) as versioned, immutable preset drafts.
* Add safe preset synchronization that advances only untouched known factory revisions and never overwrites customer-edited drafts; explicit factory restore creates another immutable revision.
* Add deterministic SVG/PNG visual-reference acceptance for every starter layout and retain the production renderer publication gate.
* Reserve the `certloom-preset-` machine-key namespace, keep installed factory identities immutable, and correct the Template list Draft Revision display alias.

= 1.6.15 =
* Add first-class versioned Template storage with stable machine identity, immutable revisions, canonical `certloom-layout` v1 hashes, optimistic concurrency, and fail-closed corruption checks.
* Add a gated premium Template administration surface for draft creation, metadata edits, immutable revision saves, strict JSON import/export, and explicit renderer-gated publishing.
* Add deterministic assignment foundations for Batch → Program → Issuer → Global fallback without creating a second credential source of truth.
* Expand complete-domain backup/restore to format v4 with Template/Revision/Assignment ownership while preserving valid v1/v2/v3 backup compatibility.
* Expand Replace/Merge restore relationship validation, durable mapping, private workspace table identities, uninstall ownership, and real-DB acceptance inventories for the new Template entities.
* Recover and fix a restore-workspace collision risk discovered during the backward audit by assigning unique private-table codes to every Template entity.
* Advance normalized schema contract to v15 and acceptance-proof evidence contract to v3 so older evidence cannot falsely approve the expanded schema.
* Keep all normalized-domain authority flags false and keep Template publishing/authoritative preview behind the production renderer acceptance gate.

= 1.6.14 =
* Add opt-in center-logo verification QR using only a byte-validated local WordPress Site Icon, high error correction, bounded center geometry, and safe fallback to the established plain QR when the logo is unavailable.
* Add opt-in local Code 128 rendering of the already-authoritative human certificate number, with printable-ASCII fail-soft behavior, quiet zones, human-readable caption, AJAX/no-JS parity, and no new barcode lookup namespace.
* Add independent raster decode acceptance for 18 logo-QR combinations and 9 Code 128 certificate-number patterns, plus expanded mobile/RTL/forced-colors browser resilience.
* Add local barcode health reporting and QR-logo readiness to System Status while keeping both enhancements disabled by default for existing sites.
* Tighten QR-logo asset safety to local uploads PNG/JPEG only, 48–1024px, at most 256 KiB, with realpath confinement and byte-level image/MIME revalidation.
* Reconcile roadmap terminology: the canonical Growth Plan places QR/Templates/Designer in Phase 3 Pro commercial core; later engineering docs called the dependency slice Phase 4. Feature IDs remain authoritative and the original QR-01 artifact/bulk derivatives remain renderer-gated rather than silently marked complete.

= 1.6.13 =
* Add WordPress-local QR Code Model 2 generation for successful public verification and Credential Profile results, with no remote QR service or new credential identifier.
* Encode only the canonical verification URL; secondary proof codes and private/internal credential fields never enter the QR payload.
* Reuse one renderer-independent QR encoder contract for public presentation and future artifact adapters, with fixed four-module quiet zone and scan-safe black/white modules.
* Add administrator QR visibility control, local QR System Status health reporting, AJAX/no-JS parity, and mobile/RTL/forced-colors browser coverage.
* Add independent PHP-SVG → raster → OpenCV scan acceptance for pretty, long-token, and query/Unicode fallback verification URLs.
* Suppress proof-less standalone Credential Profile links for credentials protected by secondary verification proof while keeping copy/print/canonical verification QR safe.

= 1.6.12 =
* Add Random non-guessable (100-bit Crockford-style) and RFC-4122 UUID v4 human certificate-number strategies alongside Manual and Sequential.
* Preserve Manual as the upgrade default, explicit-number override behavior, immutable issued numbers, exact verification, and the separate normalized checksummed public credential ID.
* Keep Random/UUID stateless so switching strategies never resets or advances the durable Sequential cursor; generated previews are explicitly non-reserving samples.
* Validate numbering strategy before complete-backup creation and during restore preflight, accepting Manual/Sequential/Random/UUID and rejecting unknown/tampered strategies before mutation.
* Extend the real WordPress/MySQL numbering gate to prove Random/UUID generation and sequential-cursor independence.
* Keep Program/Template-scoped numbering, normalized-domain authority, identity-gated portals, and protected artifacts dependency-gated.

= 1.6.11 =
* Add opt-in sequential numbering for new administrator-created credentials while keeping Manual as the upgrade/default mode and preserving explicit manual-number overrides.
* Add validated prefix/suffix, sequence start/floor, minimum digit padding, and a non-mutating next-number preview; existing issued certificate numbers are never renumbered.
* Reserve sequence values atomically in WordPress-owned option state, skip existing manual/generated collisions, and leave database uniqueness as the final concurrency gate.
* Persist the next-sequence cursor in complete backup v4, retain valid v1/v2/v3 restore compatibility, restore the cursor for Replace, and preserve the current site's cursor during Merge.
* Make allocation fail closed on corrupt state/database probes and require manual certificate entry when automatic numbering health/preview is unavailable.
* Keep Program/Template scoped sequences gated until their authoritative relationships are accepted; no legacy custom-field simulation is introduced.

= 1.6.10 =
* Add optional secondary verification proof bound to exact certificate-number lookup; proof is never a second identifier, route, or search key.
* Store only validated cost-10 bcrypt proof hashes; reserve proof state from Field Manager, CSV, public output, generic admin writes, and personal-data export while preserving complete administrative backup/restore.
* Submit optional proof through POST bodies only, with generic not-found behavior plus fixed bcrypt work for valid-shaped public misses to reduce existence timing leakage.
* Add advanced brand name/tagline/surface/text/Site Icon controls for Certloom-owned clean verification/profile routes without overriding shortcode or Elementor design settings.
* Enforce readable branded text contrast and harden legacy certificate JSON reads/maintenance so corrupt structured state fails closed instead of being treated as empty.
* Fix retired custom-field sensitivity restoration so restored fields keep their prior classification while remaining private.

= 1.6.9 =
* Separate interactive verification results from the dedicated screen-reader live-status announcer.
* Add route skip-link/focus target, stronger keyboard focus, 44px action targets, reduced-motion, higher-contrast, and forced-colors resilience.
* Make embedded Credential Profile headings default to H2 with bounded H2-H6 controls; the dedicated clean profile route intentionally owns the H1.
* Add `dir="auto"`/plaintext bidi handling for certificate input and public values, plus mobile input spellcheck/autocapitalize hardening.
* Fix AJAX status icons so Suspended/Withdrawn/Expired results match server-rendered status vocabulary and keep semantic verification time markup.
* Add a real-browser hostile-theme/reflow/RTL/accessibility-media fixture while keeping all six existing verification widgets compatible.

= 1.6.8 =
* Added Public profile / Verification only / Private per-credential guest visibility.
* Added Public-safe / Personal / Sensitive / Internal field classifications and verification/profile exposure contexts.
* Added strict server action authorization for current copy/profile/print actions; future artifact/share/claim actions remain denied until implemented.
* Prevented clean-route titles from revealing identifiers before visibility checks and reserved `__certloom_*` for Certloom-owned policy state.
* Began the Phase 2 verification/frontend redesign without changing data authority: the legacy registry remains the authoritative public verifier until normalized-domain acceptance passes.
* Added clean privacy-safe verification and credential-profile routes with readable safe identifiers, URL-safe transport tokens for complex certificate numbers, plain-permalink fallback, no-store/noindex/referrer protections, and no new credential identity.
* Added a shared legacy public presentation model for exact lookup, effective status, issuer context, verification timestamp, visible fields, and non-mutating actions; server/no-JS, REST/AJAX, shortcode, block, Elementor, and clean routes now consume the same presentation decisions.
* Added Credential Profile parity through `[certloom_credential]`, a native `certloom/credential` block, and one semantic Elementor `certloom-credential` widget while preserving all six existing verification widgets.
* Preserved the established REST `found + fields` response and added a backward-compatible `presentation` envelope for richer clients.
* Fixed the no-JavaScript verification-unavailable path so it fails gracefully instead of calling a missing helper, and isolated the new result-action CSS namespace from the existing `.cvx-action` verifier container.
* Added a maintained public-surface inventory covering identifiers, authority, caching/robots, privacy, rate-limit ownership, and compatibility.

= 1.6.6 =
* Added WordPress Privacy API exporter/eraser integration across current-authority legacy certificate evidence and normalized Recipient/credential data when normalized storage exists.
* Added opt-in transaction-backed mutable Recipient anonymization that preserves immutable Versions/Events, appends audit evidence, and is idempotent across restored sites through an explicit private lifecycle marker.
* Added private portable one-way email identity history so later data-subject export requests can still find retained credential evidence after mutable email removal; history is pseudonymous, never public, unique, and hard-bounded to 64 entries without silent trimming.
* Advanced normalized schema to v14 and complete-domain backup format to v3 while preserving valid v1 and v2 restore compatibility, including in-memory synthesis of new privacy fields.
* Hardened privacy failure semantics for schema probes, corrupt legacy custom fields, corrupt issuer settings, pagination, settings persistence, and immutable-evidence retention reporting.
* Expanded the disposable WordPress/MySQL premium gate to include the complete Batch/Signer table/index set and privacy identity storage; future-domain authority remains disabled until that external gate actually passes.

= 1.6.5 =
* Added transaction-backed Program/Batch relationship correction that locks the Credential Series, re-proves relationships, creates a successor immutable Version, preserves the human certificate number, advances the current Version pointer, and appends a dedicated audit Event atomically.
* Added bounded reviewed bulk Program/Batch correction orchestration that delegates to the single-record command, uses WordPress-secret HMAC review authenticity, 15-minute expiry, optional administrator binding, and stale `updated_at` rejection.
* Expanded the disposable real WordPress/MySQL integration harness to prove relationship correction, immutable Version advancement, Batch assignment, and stale-review failure.
* Defined the `PRV-02` personal-data lifecycle contract: mutable profile data and immutable issued/audit evidence are distinct; generic erasure must never silently rewrite historical Versions, and retained evidence must be reported truthfully.
* Kept normalized-domain authority and all three future-domain production readiness flags disabled pending real acceptance.

= 1.6.4 =
* Phase 1 continuation: added acceptance-gated Batches/Cohorts, full local issuer/brand administration, signer profiles and Batch → Program → Issuer → Global signer hierarchy.
* Added Batch membership search/pagination, stable Batch codes and Signer machine keys, Credential Series Batch relationships, immutable Version lineage, and append-only event-history presentation.
* Expanded complete-domain backup/Replace/Merge restore to Batches and Signers using format v2 while preserving valid v1 backup compatibility, including legacy Series rows without `batch_id`.
* Hardened issuer/recipient/program repository read failures, Program→Issuer validation, restore DB-bound validation, multilingual issuer-slug round trips, uninstall ownership, and capability cleanup.
* Kept relationship-changing Program/Batch bulk actions gated until they can use immutable correction semantics rather than raw historical rewrites.
* Kept all three future-domain production readiness flags disabled.

= 1.6.3 =
* Phase 1 Part D: reviewed advanced bulk management, non-blocking onboarding, and the formal Phase 1 exit audit.
* Added frozen-selection bulk review/commit for workflow status, expiry dates, and eligible custom fields, with optimistic concurrency so stale reviews cannot overwrite later edits.
* Added date-range registry filters, per-user column preferences, preserved list state, onboarding progress, and a real public verification test step.
* Completed the planned premium application shell and credential workspace while showing future/gated modules as honest informational states rather than fake active controls.
* Program/Batch bulk assignment, normalized Recipient/Program production exposure, and immutable activity history remain gated by their real domain dependencies.
* Kept all three future-domain production readiness flags disabled.

= 1.6.2 =
* Phase 1 Part C: completed premium Recipient and standalone Program administration behind the existing normalized-domain acceptance gate.
* Added full recipient identity, WordPress-user association, external ID, safe custom fields, bounded credential history, Program description/issuer/default-validity/metadata, and credential activity.
* Preserved unsupported nested/typed metadata rather than coercing it through reduced editors and validated relational references before mutation.
* Kept the proven legacy registry authoritative and all future-domain production readiness flags disabled.

= 1.6.1 =
* Phase 1 Part B: premium certificate registry, lifecycle presentation, and integrated field UX.
* Added status-count registry views and an indexed 30-day Expiring soon filter, including a direct Dashboard deep link.
* Registry columns now honor the shared Field Manager labels and explain whether status is workflow-controlled, date-derived, or legacy-review protected.
* Added protected Status reason and locked-private Internal notes fields without a database-table migration.
* Rebuilt the certificate editor and Field Manager inside the premium admin shell with responsive/accessibility-focused layouts.
* Enforced locked-private field visibility in schema normalization, field-save handling, and public-field selection.
* Bulk lifecycle changes remain intentionally deferred to REG-04; future Credential Series authority/admin/data-tool gates remain disabled.

= 1.6.0 =
* Phase 1 Part A: premium Certloom operations Dashboard from the original master roadmap.
* Added bounded live totals/status/expiry/monthly issuance metrics, recent certificates, quick actions, and current-production system health.
* Moved the existing registry to a dedicated Certificates submenu while preserving its proven authority and behavior.
* Added responsive/accessibility-focused premium admin styling without external assets or telemetry.
* Future Credential Series authority/admin/data-tool gates remain disabled.

= 1.5.16 =
* Add an explicitly opted-in local/development/staging-only final authority-cutover rehearsal after fresh passing populated migration and restore rehearsals.
* Exercise the production cutover coordinator, require the committed receipt to match the exact completed migration count/max-ID/generation boundary, prove the legacy write guard becomes read-only, enable the real Series verification gate, then return to a clean legacy baseline.
* Introduce fail-closed authority semantics: missing/clean state means legacy, exact `domain` means next-domain authority, and any other non-empty value is corrupt protected state that blocks both legacy writes and public Series verification.
* Treat prepared, malformed and committed cutover receipts as protected state rather than normalizing them to a clean pre-cutover baseline.
* Require exact read-back after prepared receipt, authority, committed receipt and public-verification marker writes; filter-altered option persistence cannot silently complete cutover.
* Make boot/recovery and all migration/restore rehearsal prerequisites recognize partial/corrupt cutover evidence, and preserve that lineage across retain-data uninstall/reinstall instead of reopening a false clean-legacy state.
* Keep all production future-domain write/admin/data-tool flags disabled; the staging rehearsal never constitutes production cutover approval.

= 1.5.15 =
* Add an explicitly opted-in local/development/staging-only populated future-domain backup/restore rehearsal after the populated migration rehearsal; it uses the production backup, Replace, Merge and rollback code and never changes credential authority.
* Add a deterministic whole-domain fingerprint over the populated credential graph and configuration so Replace, identical Merge and forced rollback must return to the exact same semantic state even when the internal generation marker advances.
* Require exact crash-replay Replace entity counts and Merge action counts, require identical Merge to insert zero records, and force a real post-swap configuration mismatch to exercise the production rollback path.
* Make restore semantic review independently bounded and integrity-bound, require the canonical entity manifest, and verify exact option values after persistence so WordPress filters cannot silently alter restored configuration.
* Hold domain backup snapshots behind the shared maintenance boundary and make private backup/source/staging cleanup failures explicit and retryable.
* Harden both future-domain and currently authoritative legacy restore private-table creation/rollback so failed pre/post DROP cleanup is never reported as clean success.
* Harden import upload/preflight private-file/transient cleanup and staging rehearsal execution-lock cleanup on abnormal failure paths.
* Keep all future-domain write/admin/data-tool flags disabled; real staging execution, final cutover rehearsal and production PHP certificate rendering remain separate gates.

= 1.5.14 =
* Harden the official legacy migration coordinator itself with mandatory two-pass semantic fingerprint verification before populated target writes can begin.
* Run every official migration commit slice behind the shared maintenance boundary so legacy writes cannot race the source check, source read, and target write inside one slice; a change between slices is detected by the frozen source boundary and blocks the next slice.
* Treat any started official migration as durable future-domain state that makes the additive schema mandatory on every subsequent boot/reactivation even while all public/admin/data-tool feature gates remain off.
* Preserve migration/cutover lineage across uninstall/reinstall whenever credential data is retained, including the legacy source-generation marker needed to validate the frozen migration boundary; operational locks/jobs/proof sessions are still removed.
* Add an explicitly opt-in, local/development/staging-only populated migration commit rehearsal that uses the production migration coordinator/writer, verifies row/status/event parity and a second idempotent writer replay, and never changes credential authority.
* Require a fresh isolated database proof, gated-domain acceptance proof, populated migration preflight, empty future-domain tables, healthy schema, no restore/cutover state, and all future-domain feature gates off before the staging rehearsal can begin.
* Bind a multi-request staging rehearsal to one Certloom version and one legacy/target generation; an upgrade or source/target change makes the rehearsal stale/fail closed rather than mixing migration semantics.
* Keep the rehearsal deliberately non-resettable: after a failed or completed populated rehearsal, reclone/restore the staging database before rerunning it.
* Keep all live source-of-truth authority and future-domain write/admin/data-tool flags unchanged.

= 1.5.13 =
* Add an administrator-only, nonce-protected populated legacy migration preflight after a fresh passing gated Credential Series acceptance proof.
* Scan the real 1.5 registry in bounded resumable slices through the production legacy migration mapper and Core Status Service while keeping the future Credential Series tables untouched.
* Freeze source count, max ID, and the Certloom generation marker; invalidate the proof if that live boundary changes.
* Perform a second complete read/fingerprint pass and require the semantic fingerprint to match the mapping pass before the proof can pass.
* Keep copyable migration reports privacy-safe: no certificate numbers, holder names, or custom-field values; only bounded numeric legacy row IDs plus review messages are shown locally to administrators.
* Reserve the live legacy generation marker before external certificate/settings/field mutations, so a durable source change cannot succeed while migration-boundary evidence silently remains old.
* Keep official migration state, source-of-truth authority, and all future-domain write/admin/data-tool flags unchanged.
* Keep populated migration commit, domain restore promotion/rollback, final cutover, and production PHP certificate rendering as separate mandatory acceptance gates.

= 1.5.12 =
* Add an administrator-only, nonce-protected gated Credential Series acceptance proof after a fresh passing isolated database proof.
* Deliberately provision and verify Certloom’s real additive future-domain schema on the host, then exercise real Recipient/Issuer/Program/Credential repositories, immutable Version constraints, lifecycle events, Status Service and public-ID verification using synthetic data only.
* Run every synthetic domain write inside one rollback-only real database transaction, restore the domain-generation marker, and require all future-domain tables to be empty again before the proof can pass.
* Refuse the proof when any future-domain feature gate is enabled, migration has started, cutover is committed, domain restore/recovery state exists, or pre-existing future-domain rows are present.
* Compare the proven legacy registry aggregate boundary before/after the proof and exercise synthetic Valid, Suspended, Withdrawn→Revoked and date-expired migration semantics without reading individual live certificate rows.
* Store only privacy-safe, internally consistent, version/time-bound proof evidence; stale or tampered results cannot remain a current Ready signal.
* Keep live legacy migration, restore promotion/rollback, final source-of-truth cutover, and production PHP certificate rendering as separate mandatory acceptance gates.
* Keep all next-domain authority/admin/data-tool flags disabled.

= 1.5.11 =
* Add an administrator-only, nonce-protected isolated database proof under Access & Settings. It uses a Certloom scratch table only and never reads certificate records, provisions the gated Credential Series domain, or changes credential authority.
* Exercise WordPress dbDelta(), explicit InnoDB creation, primary/unique/lookup indexes, duplicate-key enforcement, transactional rollback/commit, and a bounded independent-connection row-lock contention probe where the host permits it.
* Verify scratch-table removal, transaction cleanup and short-lived proof-lock cleanup explicitly; the proof result is privacy-safe and contains no database credentials, database/table names, certificate values, URLs, or filesystem paths.
* Bind stored proof evidence to the Certloom version and proof time; results from another version, older than seven days, or implausibly future-dated are shown as stale and must be rerun. Corrupt/internally inconsistent stored proof results fail closed instead of displaying a false pass.
* Expand read-only System Status to report gated-domain maintenance and schema-maintenance state, including active, stale, and corrupt protected state without repairing it as a side effect.
* Keep valid expired maintenance leases visible as warnings without permanently blocking the isolated proof; active or corrupt protected state still fails closed.
* Restore WordPress.org-facing changelog continuity for the audited 1.5.8, 1.5.9, and 1.5.10 checkpoints.
* Keep all next-domain authority/admin/data-tool flags disabled; this release still does not switch verification authority or ship public certificate artifact generation.

= 1.5.10 =
* Complete more live complete-restore abort/rollback cleanup so maintenance, staging, workspace tracking, and recovery-journal uncertainty remains explicit and retryable.
* Add privacy-safe read-only System Status under Access & Settings; viewing diagnostics never provisions schema, repairs locks, or exposes certificate/site/database/path/user/plugin-inventory data.
* Keep real WordPress/MySQL and production PHP renderer acceptance as deliberate external gates.

= 1.5.9 =
* Make ordinary legacy activation independent from still-gated future-domain tables while retaining fail-closed behavior when future-domain storage is genuinely required.
* Add race-proof final cutover coordination with shared maintenance locking, fresh source snapshot parity, committed cutover receipts, and protected recovery ownership.
* Harden private operational storage and live legacy migration/import/restore lock cleanup.

= 1.5.8 =
* Require and verify InnoDB for every next-domain table, harden checked transaction-control semantics, and make maintenance/restore/import cleanup failures durable and retryable.
* Strengthen committed and rolled-back restore replay so recovery evidence remains until owner-matched maintenance cleanup is proven.

= 1.5.7 =
* Harden immutable Version creation so structured snapshots are encoded before generation/reservation side effects; failed post-reservation paths verify compensation and conservatively preserve ownership if cleanup itself fails.
* Add a shared fail-closed structured-JSON boundary across recipients, issuers, programs, credential snapshots, lifecycle events, jobs, and Merge-restore state so corrupt or unsupported nested data cannot silently become empty state.
* Reject syntactically valid JSON scalars during restore preflight for columns that require structured arrays, preventing corrupt state from reaching staging/promotion.
* Strengthen artifact output inspection, exact Unicode font-coverage enforcement, central QR parity, and the single Artifact Render Coordinator execution boundary.
* Add deterministic development-only SVG/PNG/PDF golden references for English, Urdu RTL, mixed script, long-name shrink, arbitrary 17.5-degree rotation, and QR; the raster QR reference independently decodes to the exact verification URL.
* Add a real WordPress/MySQL premium integration gate covering InnoDB/index requirements, rollback behavior, global certificate-number ownership, immutable-snapshot side-effect safety, idempotency cleanup, and a two-process `SELECT ... FOR UPDATE` row-lock proof.
* Expand the verified checkpoint to 45/45 standalone regression suites, 69/69 focused rendering assertions, 187/187 PHP syntax checks, 3/3 JavaScript syntax checks, and 6/6 deterministic rendering-reference fixture families.
* Keep all next-domain authority/admin/data-tool flags disabled; this release still does not expose public PDF/PNG/JPG generation or switch verification authority.

= 1.5.6 =
* Make z-order unique and deterministic, normalize equivalent rotations, and reject rotated elements whose actual bounding boxes leave the page.
* Independently inspect local PNG/JPEG bytes, MIME, SHA-256, dimensions and pixel limits instead of trusting resolver metadata.
* Centralize QR encoding into one validated QR Model 2 module matrix shared by every future output adapter.
* Bind text rendering to exact content-addressed TTF/OTF family/weight/style variants and reject silent font substitution.
* Add Artifact Render Coordinator as the single rendering execution boundary with fail-soft capability checks and bounded outputs.
* Upgrade artifact receipts to schema v2 with canonical metadata-integrity hashing, documented correctly as consistency/tamper evidence rather than an authenticity signature.
* Reject malformed integer/boolean canonical values and verification URLs containing credentials or fragments.
* Verify 43/43 standalone suites, 61/61 focused rendering assertions and 180/180 PHP syntax checks while keeping authority cutover and real artifact output gated.

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

= 1.5.16 =
Adds the staging-only final authority-cutover rehearsal and fail-closed cutover authority/receipt persistence safeguards while keeping the proven 1.5 verifier authoritative in production.

= 1.5.15 =
Adds a staging-only populated backup/Replace/Merge/rollback rehearsal and hardens restore/import cleanup while keeping the proven 1.5 verifier authoritative.

= 1.5.14 =
Hardens the real migration coordinator and adds an explicitly opted-in staging-only populated migration commit/parity rehearsal while keeping the proven 1.5 verifier authoritative.

= 1.5.13 =
Adds a read-only, resumable preflight of the actual populated legacy registry plus stricter pre-mutation generation-boundary safety, while keeping the proven 1.5 verifier authoritative.

= 1.5.11 =
Adds a safe, administrator-triggered real-host database capability proof and expands read-only maintenance diagnostics while keeping the proven 1.5 registry/verifier authoritative.

= 1.5.7 =
Premium integrity checkpoint: hardens immutable Version side-effect safety, structured JSON/restore validation, deterministic rendering references, exact font/output checks, and the real WordPress/MySQL proof harness while keeping the live legacy registry/verifier authoritative until external gates pass.

= 1.5.4 =
In-place development checkpoint adding premium-grade new-domain jobs/import/backup/restore infrastructure while keeping the proven legacy registry and public verifier authoritative until the real WordPress/MySQL cutover gate passes.

= 1.5.0 =
Review-resubmission release finalizing the Certloom identity, fixing activation after the rename, and preserving the existing certificate data and schema.

= 1.4.9 =
Restore-integrity update with exact snapshot replacement, optional certificate merge, global write locking, and concurrent-change detection.



== 1.6.35 development checkpoint ==
The PDF-04 production-candidate producer now refuses any output that resolves inside the Certloom source tree and rejects `.`/`..` output segments before recursive directory creation. This prevents unaccepted PDF/PNG/JPEG evidence from contaminating source/build handoffs and aligns producer isolation with the existing candidate-freezer and authority-finalizer boundaries. The focused producer regression is 20/20. PDF-04 remains open and no accepted renderer manifest or third-party candidate vendor tree ships in the installable plugin.

== 1.6.34 development checkpoint ==
The documented PDF-04 operator bootstrap is now executable as written after copying outside the source tree. A source-only bootstrap factory validates the exact frozen PDF/GD evidence, loads the prefixed mPDF autoloader from the frozen runtime, reuses the canonical seven-fixture font manifest instead of reconstructing a divergent family, verifies the exact content-addressed fixture font, and creates the strict PDF/PNG/JPEG producer config. A new 13-check bootstrap regression prevents external-__DIR__, undefined-helper, font-manifest drift, candidate identity, and symbolic font/temp path regressions. PDF-04 remains open and no accepted renderer manifest or third-party candidate vendor tree ships in the installable plugin.

== 1.6.33 development checkpoint ==
PDF-04 now requires direct QR scanability from the actual rasterized PDF as well as the PNG and JPEG artifacts. The acceptance contract advances to v7 and the independent verification report to v5; missing or false `qr_pdf_decode` evidence fails closed. The frozen development oracle directly proves the PDF path through Poppler rasterization + OpenCV QR decode. PDF-04 remains open and no accepted renderer manifest or third-party candidate vendor tree ships in the installable plugin.

== 1.6.32 development checkpoint ==
The independent PDF-04 verifier now has explicit fail-closed resource boundaries before it opens candidate evidence: non-symbolic in-bundle paths, bounded evidence/artifact sizes, exact frozen raster DPI/geometry, a five-million-pixel raster ceiling, single-page PDF metadata, and bounded PHP/Poppler subprocess execution. Focused resource-boundary regression adds 11 low-level cases plus a dedicated source suite. PDF-04 remains open and no accepted renderer manifest or third-party candidate vendor tree ships in the installable plugin.

== 1.6.31 development checkpoint ==
Renderer candidate freezing now uses a single private byte snapshot for namespace validation, deterministic source evidence, dependency-lock validation, and the executable runtime tree, closing the prepared-tree TOCTOU gap. Symbolic candidate-root, dependency-lock, and recipe arguments are rejected before path resolution. Focused freeze regression is 31/31. PDF-04 remains open and no accepted renderer manifest or third-party candidate vendor tree ships in the installable plugin.

== 1.6.30 development checkpoint ==
Renderer candidate namespace isolation is now fail-closed before PDF-04 execution. The freezer rejects unprefixed root or child mPDF namespace declarations, unprefixed Composer autoload exposure, and comment-only prefix evidence while preserving the deterministic 1.6.29 source/lock/runtime freeze chain. PDF-04 remains open and no accepted renderer manifest or third-party candidate vendor tree ships in the installable plugin.
