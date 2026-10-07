# Connect an app that supplies results

Certloom can receive trusted qualification and scalar results from a server you control. Version 2 works with customer completion and approval: the app supplies earned results, the customer supplies permitted personal/enrollment details, and Certloom issues once after checking both. Version 1 completion-only clients retain their existing endpoint, payload and signature.

## Organization setup

1. Configure your Program, requirements and published design. In Fields, set each app result to **Achievement → Source**, with collection set to **Never**. Personal details use the recipient writer. Fixed training hours or similar values use Program defaults.
2. Use automatic certificate numbering. Connect each existing Recipient through an exact **Recipient External ID**; matching email or name does not link an account. A customer who needs to complete details must have an explicitly linked WordPress account.
3. Open **Automations → Integrations → App connections with results**. Choose the Program and optional Batch; show its result fields. Enter a name and map your app's incoming field names, such as `Score`, to the corresponding Certloom result field.
4. Copy the one-time signing secret into the sending server. Keep it out of browser JavaScript, public widgets and logs.
5. Send the test below. A successful test returns readiness/blocker codes and creates no request, registration, certificate, numbering allocation or delivery. Send live intake only after reviewing its result.

Required staff-owned one-off results cannot be concealed by this connection. Make the intended result source explicit in Fields and review its effect on existing Programs first. Every mapped target must be applicable in Global → Issuer → Program → Batch.

When enrollment fields are applicable, pass that customer's exact existing `registration_id`; Certloom checks its Recipient, Program and Batch. Zero is allowed when no enrollment context is needed. This API does not guess an enrollment or create one from an email address.

## Routes and payload

The connection gives you a source key such as `v2-0123456789ab`. With WordPress's usual REST URL, the live URL is:

```text
https://your-site.example/wp-json/certloom/v2/integrations/webhook/v2-0123456789ab
```

Append `/test` for the nonmutating test. Use the REST URL shown in your site's screen; WordPress may use a subdirectory or a different REST URL structure.

```json
{
  "schema_version": 2,
  "event_id": "course-42/attempt-7/completed",
  "recipient_external_id": "student-001",
  "registration_id": 0,
  "completed_at": "2026-10-03T08:00:00Z",
  "evidence": {
    "eligibility": "eligible",
    "checked_at": "2026-10-03T08:00:00Z",
    "valid_until": null,
    "fields": {
      "Score": { "value": "0", "revision": "attempt-7/revision-1" }
    }
  }
}
```

Use actual UTC timestamps in `YYYY-MM-DDTHH:MM:SSZ` format. `completed_at` cannot be in the future. Qualification is `unknown`, `eligible` or `not_eligible`. An eligible result needs `checked_at`; optional `valid_until` must be later and is checked again before issuance. Unknown or expired qualification stays held even when every customer detail is complete.

Every supplied value is a string. This preserves a zero score and leading-zero text identifiers. Each result has a stable source revision. Only mapped incoming fields are accepted; no names, emails, customer answers, arbitrary nested payloads or repeating result tables are supported. Requests are limited to 32 KiB and at most 30 mapped results.

## Signing recipe

Sign on the sending server with HMAC-SHA256. The signing input is five UTF-8 parts separated by **literal newline characters**, with no extra newline after the body:

```text
certloom-webhook-v2
POST
/certloom/v2/integrations/webhook/v2-0123456789ab/test
CURRENT_UNIX_TIMESTAMP
EXACT_JSON_REQUEST_BODY
```

The signed route starts at `/certloom/v2`, excluding the site origin and WordPress REST prefix. `/test`, live intake and an evidence-update route need different signatures. JSON whitespace is part of the signed body. Send:

```text
Content-Type: application/json
X-Certloom-Timestamp: CURRENT_UNIX_TIMESTAMP
X-Certloom-Signature: sha256=LOWERCASE_HEX_HMAC
```

The timestamp has a five-minute window. Keep server clocks synchronized. Use the [small PHP request builder](../examples/webhook-v2-request.php), which builds bytes and headers without sending a request. Its signing recipe is checked against the actual receiver contract.

```php
require 'webhook-v2-request.php';

$request = certloom_webhook_v2_request(
    $source_key,      // From your Certloom connection.
    $signing_secret,  // From your server's secret store.
    'test',
    $payload         // The array matching the JSON example above.
);

// POST $request['body'] unchanged with $request['headers'].
// Use your connection URL + /test. Switch mode to 'intake' only for live intake.
```

## Receipts and later evidence

A test returns HTTP 200, `status: tested`, a `ready` flag, the current mapping fingerprint and bounded blocker codes. Missing customer information can be expected; a configuration or trusted-source blocker needs organization action. The live and update routes return HTTP 202 on accepted work, with `request_ref`, current `revision`, request status, a replay flag and an issued `version_id` when available. These are private responses with `no-store`; they contain no customer field values.

Use a stable intake `event_id` for one connection and achievement. Retry the **identical original JSON bytes** with a fresh timestamp/signature. A changed body under the same event is a conflict. Exact accepted retries return the original request, including an issued receipt. Separate connections have separate event identities.

New qualification or a corrected pending result uses:

```text
/certloom/v2/integrations/webhook/SOURCE_KEY/requests/REQUEST_REF/evidence
```

```json
{
  "schema_version": 2,
  "event_id": "attempt-7/new-evidence-2",
  "expected_revision": 3,
  "evidence": {
    "eligibility": "eligible",
    "checked_at": "2026-10-03T08:10:00Z",
    "valid_until": null,
    "fields": {
      "Score": { "value": "85", "revision": "attempt-7/revision-2" }
    }
  }
}
```

Use the request's current revision and a new update-event identity. Send the complete current evidence set. Exact retries reconcile the recorded update, even after issuance; changed update bytes conflict. A new update cannot change recipient, Program, Batch, registration or connection. Issued/cancelled work cannot be reopened or overwritten; an issued correction uses Certloom's correction/reissue lifecycle.

HTTP 401 indicates an invalid signature/timestamp or unavailable connection. HTTP 409 indicates a payload, identity, mapping, revision or replay problem; fix it instead of repeatedly retrying the same invalid event. HTTP 503 holds live intake when current writing authority is unavailable. Do not interpret accepted waiting work as an issued certificate or successful email delivery.

## Customer and staff journey

The customer sees the waiting request on their account and chooses **Complete details**. The exact request form shows applicable personal and enrollment requirements, including details deferred until issuance. It excludes app results. Approval, when configured, applies to the exact submitted value. Current source evidence, account ownership, requirements, design/privacy and numbering are checked again by the canonical worker. Completing a profile never creates qualification.

Staff use **Certificate requests** for history, recheck or cancellation. Configuration repair does not substitute new evidence. Pausing/remapping a connection holds work accepted under the older mapping revision; review/cancel affected work before the app sends a new intake identity. Rotating the signing secret preserves mapping authority and makes the old secret stop authenticating.

Whole-state recovery and restored-source reconciliation are still part of the remaining implementation. Restored requests stay paused; backup data alone does not authorize automatic execution. Local contract/journey checks do not grant production release authority or prove every third-party provider environment.
