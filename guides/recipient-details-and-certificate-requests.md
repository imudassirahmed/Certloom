# Recipient details and certificate requests

In Certloom, the organization decides what a certificate requires. The recipient is the organization’s customer: they sign in to the organization’s website and supply the personal or enrollment details that the organization has enabled for them. Staff and trusted apps supply approvals, qualification and results.

This guide describes the current Program/recipient review build. Durable waiting requests support internal manual intake, deliberately qualified bulk intake, explicitly mapped signed version 2 app intake and the opt-in Tutor and WooCommerce customer-details flows. Other learning integrations retain their separately supported issuance flows while their pending adapters are completed.

## Set up the Program first

Follow Setup in this order: organization and profile, Program, required fields and their sources, certificate presentation/design, email, and Review.

Choose the profile that describes the organization’s certificates, then confirm the recommended fields. A template’s visible text does not decide who owns an answer or whether a recipient qualifies. Keep the Program’s requirements and field source settings authoritative.

When Review reports a required field missing from a design, add that field now, save it for later in Fields, or change the certificate design. A design can display a value only after its permitted source has supplied it. Keep identification numbers private unless the organization has deliberately approved their certificate/public use.

| Example | Where the information belongs | Who supplies it |
| --- | --- | --- |
| Father’s name or an approved personal ID | Recipient’s reusable personal details | Recipient, with approval if enabled |
| Enrollment or registration number that changes between Programs | That recipient’s exact Program registration | Recipient or organization, according to the field policy |
| Course duration or other shared Program information | Program’s shared values | Organization |
| Exam score, grade, attendance or award result | That specific achievement/source event | Organization or trusted app |

Enable customer collection only for permitted personal or registration details. Scores and eligibility keep their trusted source even when the customer has completed every visible form field.

## Let the customer complete their details

The shared details form is available through:

- The `[certloom_my_details]` shortcode on an account page.
- WooCommerce’s Edit account form.
- The recipient’s own WordPress profile, where that screen is available. Organization staff do not see recipient collection on their own admin profile by default.

WooCommerce normally redirects customers away from the WordPress admin profile. Use **My Account → Account details** on those sites, or publish an account page with `[certloom_my_details]` or `[certloom_my_credentials]`. Keep the site’s customer access policy in place; customers do not need Certloom staff permissions to complete their own details.

Use **Save details**, or press Enter in a single-line certificate field. This saves the certificate details separately from the surrounding WordPress or WooCommerce account form. Enter in a multiline field starts a new line. After submitting details for approval, use **Your Programs → Complete details** to review that Program’s saved answers.

The account must be linked to its exact Certloom recipient record. Enabled automatic account sync uses the WordPress user ID; matching an email address or name does not claim another person’s certificates. For existing unlinked records, staff review identity through the recipient profile’s account link controls, described below.

Personal details can be reused across Programs. Registration details belong to the exact active registration and appear in that context. Signing up, saving a form or creating a registration does not establish completion or permission to issue a certificate.

If a detail needs approval, the customer submits it first. Staff review the exact submission in **Credentials → Recipient reviews**. A later correction needs its own current approval. Editing an account does not rewrite an already issued certificate.

## Use Certificate requests

Customers have **In progress** and **Closed requests** in their account. Closed requests explain whether a certificate was issued or the organization closed the request; they expose no private source evidence. An unlinked account cannot read the former recipient’s requests.

## Connect an existing recipient account

Open the recipient profile and choose the exact WordPress account owned by that person. Confirm that you reviewed ownership before saving. An account already connected to another recipient needs explicit review and unlinking first; Certloom does not merge records based on matching email addresses or names.

Connecting an existing recipient preserves their pending requests so they can supply missing details. Unlinking closes unissued requests and outstanding submissions. If a certificate was already committed before a response failed, Certloom reconciles that certificate instead of cancelling it. Historical certificates remain unchanged. Unlink the current account before connecting a different one. Later profile edits and integration identity resolution cannot silently undo a deliberate unlink.

Suspended or deleted accounts cannot continue pending issuance. Restoring account access queues a fresh assessment of the original qualification and current requirements. Account deletion disconnects the bridge and closes outstanding work. Large outstanding queues require staged review; no partial unlink is saved.

## Choose how certificate names work

**Settings → Certificate names** applies once for the organization. The default is the recipient profile name. Automatically connected WordPress accounts follow their account name; an explicitly staff-reviewed connection preserves the organization’s recipient name.

For legal-name confirmation, create a text field in Fields manager: recipient supplied, reusable personal details, collected in the profile, requiring organization approval and permitted on certificate artwork. Select it as the **Reviewed name field** in Settings. It becomes required across Programs and appears in the customer’s own Personal details form.

The organization approves the exact submitted name. Certloom uses that approved value for the name in future certificate identity and artwork without overwriting the WordPress nickname. A proposed correction holds future issuance until reviewed. Existing certificates keep their original names; a historical correction belongs in the organization’s credential correction workflow.

## Organization request operations

Open **Credentials → Certificate requests** to see durable requests and their activity.

Recent activity shows newest requests first. Choose a status to work through held requests and scheduled assessments. Each page loads at most 50 requests; **Load more requests** continues the list. **Refresh status** fetches current state from the server.

| What the screen shows | Next action |
| --- | --- |
| Missing customer information | Customer completes the enabled personal/registration form |
| Submitted detail awaiting approval | Organization reviews the exact submission |
| Missing or outdated trusted qualification/result | Organization or app supplies current trusted evidence |
| Missing design information or invalid settings | Organization corrects Program, field or design settings |
| Held after restore | Organization reviews source and ownership through the restore workflow |
| Issued | Use Credentials for later lifecycle changes |
| Cancelled | The request is closed; saved details and history remain |

**Activity history** shows received requests, assessments, trusted evidence updates and staff actions. It excludes raw answers, result values, source payloads and private source identifiers. Opening the list or history does not execute issuance.

### Recheck requirements

Resolve the underlying issue, then choose **Recheck requirements**. This queues a fresh assessment. It does not enter missing information, approve a submission, change the original source, or grant qualification.

Certloom reloads current qualification, ownership, Program requirements, submitted details, approvals and presentation settings before issuing. A request waiting for new qualification cannot be reopened by this button. Restored or unsupported sources remain held for their own review.

Managing the queue requires credential management permission. Requesting another assessment also requires issuance permission. If the request changed in another session, refresh its status before acting again. After a lost or rejected action response, fetch current status instead of repeating the action blindly.

### Cancel request

Choose **Cancel request**, read the inline confirmation, then confirm cancellation if this unissued intent should close. **Keep request** returns to the card without changing it.

Cancellation checks for an existing committed certificate first. If issuance already committed but the request receipt was interrupted, Certloom reconciles the issued receipt. The certificate remains issued.

A cancelled request does not resume automatically. Its recipient answers, source evidence and activity remain saved. Changes to an issued certificate use the separate credential lifecycle workflow.

### Remind customers and close old requests

Open **Recipient reminders and request deadlines** on the Certificate requests screen. These options start disabled. Choose a reminder interval and a maximum of one to three reminders per request, then select **Save request settings**. Reminders ask the customer to complete missing recipient-supplied details; they do not ask customers to enter trusted results or approve their own answers.

Set the interval to zero to turn reminders off. **Close unissued requests after days** controls how long a local request can remain open; zero leaves it open indefinitely. This deadline applies to the request, not to an issued certificate's validity. Expiry checks for an already committed certificate before closing anything, and retains the customer's answers and activity history.

Reminders and deadlines apply to existing local requests at their next background check. Optional completion and cancellation emails start when enabled; older closed requests are not emailed retroactively. At most one request notice is queued per recipient per day. Notices use the exact linked recipient's current address and contain no submitted answers. Failed or interrupted send attempts are retained for review and never automatically resent. Restored requests remain paused until the organization reviews their connections.

### Check background scheduling

Expand **Background checks and scheduling** on the Certificate requests screen. It shows due assessments, queued/running/paused/failed pending jobs, outstanding configuration changes and the scheduler’s status. Counts stop at **50+** and contain no customer answers or signing secrets. **Refresh status** reads the latest state without issuing a certificate.

On traffic-driven sites, WordPress runs scheduled checks when someone visits. If traffic-driven cron is disabled, the hosting service must run WordPress cron. Certloom reports this distinction; confirm the host’s schedule if assessments remain overdue.

Staff with issuance permission can choose **Queue due checks** to repair scheduling for saved pending work. This re-establishes the pending watchdog and queues bounded assessments. It does not supply results, approve details, grant qualification or resume deliberately paused jobs. Review paused or failed work through **Credential data tools → Background job history**. If scheduling fails, refresh the screen and investigate the scheduler before retrying; the scheduling transaction rolls back its changes.

## What a trigger must provide

A safe issuance trigger needs the exact recipient, Program and any registration/Batch context, a stable event identity, current trusted qualification, and a mapping for every organization/app-owned result. Customer forms supply only their permitted personal/enrollment answers.

An event identifies the reason to assess a certificate. The durable request records that intent. The worker decides whether current qualification and all requirements permit issuance. A replayed event must resolve to the same intent and canonical certificate, rather than create another certificate.

Current pending worker acceptance covers internal manual intake, deliberately qualified organization bulk intake, mapped signed version 2 app events and explicitly connected Tutor and WooCommerce completions. Follow the [signed app guide](app-results-and-signed-webhooks-v2.md) for exact identities, result mapping, a nonmutating test and evidence updates. Other first-party pending adapters require their own provider acceptance. See [Backup and recovery](backup-and-recovery.md) before moving these records to another site.

## Tutor completion with missing customer details

In **Integrations → Tutor LMS**, expand **Collect customer details after completion**. Select the course, Certloom Program and optional Batch, then connect them. Use this flow when the Program requires personal or enrollment details that customers should enter themselves. The learner must have an exact connected WordPress account, and automatic certificate numbering must be enabled.

Each course uses one issuance flow. Remove or pause its existing completion-only mapping before enabling customer-detail collection. Connecting a course does not backfill historical completions. Changing flows cannot issue another certificate for a completion already issued by the previous flow.

Tutor's actual confirmed completion creates a durable request and its exact enrollment context. Missing details appear in the learner's account under **In progress → Complete details**. The organization approves submitted details when configured. Certloom then reloads the original stored completion, current account connection, Program requirements and design before issuing through the shared background worker.

This connection supplies course completion, enabled customer personal/enrollment fields and shared Program values. It cannot supply arbitrary exam scores or results. A Program requiring those app-owned results needs a source that explicitly supplies them, such as a mapped signed app connection; Certloom refuses an incompatible Tutor connection.

Use **Pause** to hold future assessment through this connection, or **Remove** to disconnect it. Requests retain their history. If the original completion is removed or changes before issuance, the request stays held for current trusted qualification. Paused restored requests also require review; reconnecting an app or completing a profile does not resume them automatically. Once issued, later source changes belong in the separate credential lifecycle workflow.

## WooCommerce completion and customer details

In **Integrations → WooCommerce**, open **Collect customer details after completion**. Choose the purchased product, Certloom Program and optional Batch. The Program must have a compatible published design and automatic numbering. Remove or pause an active completion-only mapping for that product before enabling this flow.

For a larger product library, use **Search products by name, SKU or ID** above the product selector. Search reaches beyond the initial twenty choices; product IDs and SKU labels help distinguish similar names. Your selected product stays selected while you search or retry a failed search. The same search is available in the completion-only flow.

A registered customer's **Completed** order creates one request per purchased product, with its own exact enrollment. Payment or Processing status does not qualify; product quantity does not create multiple certificates. Guest checkout cannot claim a recipient by billing name or email. The customer signs in and supplies the enabled personal and enrollment details, while your organization handles approvals.

Certloom rechecks the original completed purchase, linked account, details, approvals and design before issuing. A refund or changed purchase facts holds an unissued request for review. This flow supplies purchase completion, customer details and shared Program values; it does not invent exam scores or other required app-owned results. HPOS and legacy order storage are supported through their authoritative data stores.

Removing and recreating a Tutor or WooCommerce connection does not make the same native completion a new event. A new connection or Program cannot replace an existing request's original authority. Cancelled requests remain closed; issued requests keep their original certificate. Switching to completion-only also cannot issue a second certificate for an existing pending intent. Review the original request or credential instead. Historical course backfill and source-driven automatic revocation are separate workflows.

## Find requests that need your attention

In **Certificate requests**, choose a Program, status, who needs to act, the requirement to resolve, and how long ago the request was received. A request can need more than one person or source, so it can match multiple responsibility views. Closed requests stay in their history and do not appear as active approval or configuration tasks.

Use **Check more activity** when the selected view has more activity to inspect, even if the first results contain no matches. Changing filters starts a fresh view. Use **Load more Programs** if the Program you need is beyond the initial choices. Opening or filtering this screen does not issue certificates; each request retains separate recheck/cancel controls and private activity history.

## Bulk issuance with customer details

Choose the Program and optional Batch in **Import / issue from file**, then choose **Download Program CSV**. The file contains headers only. It includes exact Recipient IDs, staff-owned achievement columns, an enrollment ID when needed, and certificate numbers only when numbering is manual. It contains no stored customer answers.

Customer-owned fields come from the website account, enrollment fields from the exact participation, shared values from Program defaults, and app-owned results from their trusted source. Adding these values as CSV columns cannot replace their authority. Text identifiers retain leading zeros; a score of zero is a supplied value.

The default missing-details policy stops the review. To keep already-qualified customers waiting for their own details, select **Create waiting requests for qualified customers**. Required staff results, account connections and issuing configuration must already be valid. After review, confirm that the waiting recipients have earned the certificate, separately from confirming the CSV. A customer cannot create this qualification through their profile.

Processing a mixed CSV issues ready rows and creates durable requests for waiting rows. Requests are visible in the customer’s account before their first certificate. **Complete details** opens that request’s Program requirements, including reusable details configured for collection just before issuance. Enrollment details are bound to the selected enrollment; a sibling enrollment cannot supply them.

Submitting a detail that needs approval leaves the request waiting for the organization. After approval, the worker rechecks the current account, exact participation, qualification, field requirements and design. Worker retries recover the same request or issued receipt. A replay cannot overwrite an issued certificate.

If a row changes after review, review it again. Repair failed rows and prepare a new review for unresolved rows; do not blindly repeat successful rows. Manual certificate numbers are validated again at final issuance. On-site request progress is available now; this workflow does not send automatic reminder emails.

The same explanation is available in the WordPress **Help → How certificates are issued** tab on Certloom screens.
