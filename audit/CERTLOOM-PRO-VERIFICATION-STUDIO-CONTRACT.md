# Pro Verification Studio entitlement and rendering contract

Feature implementation: [shared Studio renderer](../src/Infrastructure/Verification/ProVerificationStudio.php),
[entitlement boundary](../src/Infrastructure/Verification/ProVerificationAccess.php),
[Elementor widget](../elementor/class-certloom-pro-verification-widget.php),
[native block](../blocks/verification-studio/block.json).

## Commercial provider interface

Core evaluates `apply_filters( 'certloom_pro_feature_enabled', false,
'verification_studio' )`. A trusted Pro companion must return the boolean `true`
only when its server-validated entitlement authorizes this named feature. Any
other result, an absent provider or a thrown adapter error keeps Pro disabled.
Respect the existing value for unrelated feature names. Resolve entitlement
locally or from the provider's validated cache; avoid a network request for every
widget/preview render.

There is no commercial license SDK/provider in the current repository. This
contract is an integration seam, not an implemented activation service. Do not
replace it with a permanently true callback, request parameter, block attribute,
editor capability or editable WordPress option. Renderer/domain release authority
is separate and must not be treated as a commercial license.

When entitlement changes, the companion must invalidate any full-page, CDN or
fragment cache that contains licensed HTML, and reopen/reload active editors so
their insertion catalogs refresh. Elementor's widget output cache is disabled
by the dynamic-content declaration, but that cannot purge a third-party page
cache. No browser-provided entitlement flag is a rendering authority.

## Disabled behavior

Elementor registers `certloom-pro-verification` to preserve saved documents, but
`show_in_panel()` returns false and its controls explain the unavailable feature.
The native block remains registered for saved content; its editor inserter is
disabled. Both render through the standard verifier without a Pro frame or
Custom style output. Existing verification remains available after downgrade or
an adapter failure. Standard widgets/blocks remain unchanged.

## Enabled behavior and data boundary

The dropdown is exactly Campus, Authority, Executive, Celebration, Essential and
Custom. Usage-profile recommendation is a default, rather than a mandatory
style. Only Custom serializes the bounded responsive numbers, fixed enums and
six-digit colors. Elementor's advanced selectors require the Custom class so
saved settings cannot affect a preset. User content is escaped by the existing
shared renderer; there is no raw HTML/CSS entitlement shortcut.

Each rendered instance has a UUID-backed scope, including independently requested
REST editor previews. Accessible label/ARIA references are rewritten with their
IDs. The block preview is inert to prevent submitting an editor preview form.

The Studio adds presentation controls around the existing verifier. It does not
create a new REST lookup, expand anonymous field visibility, enable production
downloads, alter issued snapshots or override the global public certificate
position. Designed certificates replace the search panel; details and uploaded
images appear below the retained input. Current privacy, status, sharing and
request-deduplication logic remains authoritative.

## Integration acceptance still required

Test the actual companion's activation, expiry, revocation, offline validation,
adapter failure and cache invalidation using its real commercial contract.
Verify both editors after entitlement changes and existing saved pages after
removing the companion. The local temporary provider used for feature QA was
removed; it is not packaged as a production activation mechanism.
