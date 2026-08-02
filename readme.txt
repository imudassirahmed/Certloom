=== CertVerify – Certificate Verification ===
Contributors: Ecapla
Tags: certificate, verification, credentials, block, shortcode
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage certificates and publish exact-number verification with a native block, shortcode, CSV tools, and optional Elementor widgets.

== Description ==

CertVerify helps certification bodies, training providers, associations, auditors, and other organizations manage certificate records and publish public verification on WordPress.

Visitors enter the complete certificate number to view its current status and only the fields approved for public display. Letter case is ignored, while spaces, punctuation, and hyphens must match. Records stay in the site's WordPress database; CertVerify does not require an external verification service.

= WordPress-first and self-hosted =

CertVerify provides a practical certificate registry without requiring a separate SaaS platform or a page builder. The native block and shortcode work with WordPress itself, while Elementor integration remains optional.

= Publishing methods =

* **Native WordPress block** with six designs.
* **Shortcode** using `[certverify]` in the Classic Editor, a Shortcode block, templates, or other page builders.
* **Direct links** using `?certverify_number=YOUR-NUMBER` for QR-code destinations. These requests receive noindex and private-cache headers, although the number may still appear in browser history, infrastructure logs, or site analytics.
* **Optional Elementor widgets** when Elementor 3.10 or later is active: Professional, Meridian, Halo, Axis, Nova, and Forge.

Each Elementor widget has isolated content and style controls. Structural responsiveness is automatic and container-aware. CertVerify is independent and is not affiliated with or endorsed by Elementor.

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
* Administrator-only Historical restore mode for trusted CertVerify exports containing protected legacy Expired records.
* Versioned streaming JSON backup for lossless restoration of certificates, settings, fields, retired values, workflow sources, and timestamps.
* Restore preview before choosing exact snapshot replacement or a non-destructive certificate merge.
* Restore locking pauses CertVerify writes during commit while public verification remains available.
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

1. Install the ZIP through Plugins > Add New > Upload Plugin, or upload the `certverify` folder to `/wp-content/plugins/`.
2. Activate CertVerify.
3. Review CertVerify > Field Manager and choose any additional fields that may appear publicly.
4. Add records manually or through CertVerify > Import & Export.
5. Open CertVerify > Appearance & Embed and choose the block, shortcode, or optional Elementor method.
6. Review CertVerify > Access & Settings for permissions, branding, privacy, and uninstall preferences.
7. After widget CSS changes, use Elementor > Tools > Regenerate CSS & Data and clear site caches.

== Frequently Asked Questions ==

= Can I use CertVerify without Elementor? =

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

Not by default. Enable permanent data removal in CertVerify > Access & Settings before deleting the plugin only when that is intended.

= Where can I get support? =

After publication, use the Support tab on the WordPress.org plugin page. Include the WordPress, PHP, and CertVerify versions, clear reproduction steps, and relevant error text. Never post private certificate data, backup files, credentials, or personal information in a public topic.

== Screenshots ==

1. Native CertVerify block in the WordPress editor.
2. Professional certificate-verification widget.
3. Meridian and Halo Elementor widgets.
4. Axis, Nova, and Forge Elementor widgets.
5. Certificate administration and Appearance & Embed screen.
6. Field Manager with active and retired custom fields.
7. CSV mapping workflow.

== Changelog ==

= 1.4.9 =
* Added an expiring restore-maintenance lock that pauses CertVerify writes during a resumable complete restore.
* Split restoration into exact Replace mode and non-destructive Merge mode with preview counts for current-only certificates.
* Added registry and configuration checks so unexpected changes abort before the atomic restore commit.
* Made complete-backup generation reject mixed-state files when records or configuration change during export.
* Preserved public verification during restore and expanded restore-state cleanup.

For the complete release history, see `changelog.txt` included with the plugin.

== Upgrade Notice ==

= 1.4.9 =
Restore-integrity update with exact snapshot replacement, optional certificate merge, global write locking, and concurrent-change detection.
