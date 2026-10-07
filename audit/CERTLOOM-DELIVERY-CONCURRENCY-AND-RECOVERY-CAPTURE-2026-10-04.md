# Delivery concurrency and recovery capture — 4 October 2026

This completes the delivery-write and bounded delivery-storage capture checkpoint. It does not complete whole-state recovery, canonical issuance concurrency, reminders/expiry, native bulk/app commits or the outstanding widget journeys. Release validation and LearnDash remain separate later stages.

## Production repairs

Email and external-publication repositories previously read a status without locking it, then updated by delivery key alone. They accepted zero affected rows as success. Two workers could claim the same queued record, merge old evidence or overwrite a newer result. External publication also imported a nonexistent `Infrastructure\Serialization\SafeJson` class; actual repository execution exposed that failure. Its import now uses the existing application JSON helper.

`DeliveryWriteBoundary` provides checked short transactions behind the existing maintenance/write fence. It locks the entire current delivery row, makes the decision from that row, advances DomainGeneration inside the transaction and checks a conditional update of the delivery key, status and attempt token. Only one affected row confirms a mutation. Confirmed terminal replays return a no-op without merging new evidence or advancing the generation. Creation is fenced and requires one inserted row; a destination's existing dedupe receipt remains authoritative.

The persisted attempt's SHA-256 proof is required to finish a sending record. A result from an older attempt cannot mark a retry sent, failed or uncertain. A preflight failure without an attempt cannot overwrite an active transport. Success requires a sending record; accepted and uncertain outcomes never move backwards. The email handler includes the claiming attempt in both accepted and rejected transport results. A rejected claim stops before transport, and failed result persistence remains an error with the private envelope retained. No external transport runs inside these database transactions.

External stale-job recovery now locks and rechecks the actual job lease. A heartbeat after candidate selection is respected. It locks the delivery, reconciles persisted acceptance or marks that exact attempt uncertain, and closes the job in the same checked transaction. A failed job write rolls back the delivery transition and generation. Restored executable jobs are not introduced by this work.

## Bounded lossless capture

`WholeStateDeliveryRecord` has an explicit inventory of every current email/destination column. It preserves SQL null separately from empty strings and transports exact storage bytes as canonical base64. Primary identities must be canonical positive decimal strings within PHP's integer range; magnitude is checked before casting to avoid PHP 8.5 overflow diagnostics. The byte/digest checks never deserialize PHP values, parse evidence into execution instructions, normalize status values or grant semantic/import permission.

`WholeStateDeliveryRepository` captures both actual tables with checked count, maximum ID and storage-byte descriptors. Sixteen-row primary-index keyset pages return at most 4 MiB raw storage. The evidence body is length-guarded in SQL, so a racing oversized value cannot bypass the row ceiling. Generation reads bracket descriptors and each value page; final descriptor/generation fingerprints reject changed snapshots. Failed reads cannot become empty successful pages.

Current explicit operational bounds are 256 KiB per raw row, 100,000 combined delivery records and 256 MiB aggregate raw storage. Oversized state fails visibly rather than being omitted. These bounds belong to this new capture implementation; existing domain v5 and legacy v4 reader/hash contracts are unchanged.

This is a paged read model and storage codec, not the complete `deliveries.jsonl` stream/coordinator. The fixed automation-runs option already belongs to the whole-state option inventory; combining it with these rows, complete reference/assets capture, all remaining configuration-writer fences, common manual/scheduled orchestration, semantic account/object review and recoverable DB/file publication remain open. No backup control is relabeled as complete and no executable work is resumed.

## Accepted evidence

- **163 guarded WordPress/MySQL checks per runtime:** `output/program-recipient-20261004/delivery-capture-final-php85.json` and `delivery-capture-final-confirmed-php82.json`. Creation, claims, exact-attempt results, terminal no-ops, SQL errors/zero writes, generation failures, active maintenance, renewed leases, atomic recovery faults, actual schema inventories, multi-page byte-for-byte capture, all primary-index range EXPLAIN plans, writer races and oversized values are covered. Final accepted runs preserve every original business/core row and option; current cron contains no owned test jobs.
- **14 independent-worker checks per runtime:** `delivery-concurrency-accepted-php85.json` and `delivery-concurrency-accepted-php82.json`. For each store, two real sessions overlap in a confirmed InnoDB lock wait for competing claims, acceptance versus uncertainty, and uncertainty versus late acceptance. Exactly the winning operation persists. No email or provider publication is performed. This accepts delivery-state races, not independent canonical issuance/approval/cancellation races.
- **58 standalone record checks per runtime**, plus **16 focused executions** without failures, skips or diagnostics in `delivery-capture-final-focused`. Domain/workflow/envelope/authoring and delivery regressions remain accepted. Four added handler fault/proof cases pass in **two further executions** in `delivery-handler-faults-accepted` (39 handler/delivery checks each).
- **24 PHP syntax checks** pass across the two runtimes; the scoped tracked diff has no whitespace errors. The installed Elementor PHP 8.5 bootstrap still emits its existing external nullable-parameter deprecation; accepted probes have no Certloom diagnostics.
- **Lance read-only delivery screen:** `output/playwright/delivery-admin-read-20261004.log` and its desktop/mobile screenshots accept a visible 1,440px/390px page, one visible title, no document overflow/fatal message and zero page script errors. No send/resend is invoked; this is a related-screen check, not full email transport or widget acceptance. The original Integrations tab URL/viewport are restored.

## Fixture preservation and diagnostics

All single-process fixtures use checked rollback. Independent workers require committed disposable delivery rows; their random identities, exact expected evidence, original business fingerprints and cleanup receipts are recorded outside the webroot. Cleanup locks and compares each owned row, removes only those rows, and preserves current unrelated options. When a background option changed, cleanup advanced the operational generation rather than restoring a potentially stale marker. All owned concurrency directories/journals are removed. No SQL snapshot is restored, credential/version is issued, recipient/account is created or external message is sent by these checks. Disposable inserts may consume unused auto-increment values.

Earlier diagnostic runs detected ordinary cron/WooCommerce asynchronous-scheduler option progress, so a broad original-options equality check correctly refused its initial acceptance. The harness now disables its own Action Scheduler async dispatch using the installed provider's `action_scheduler_allow_async_request_runner` filter. The rollback probe permits only the two explicitly observed scheduler scopes (`cron` and `action_scheduler_lock_async-request-runner`) to progress, requires no owned test job in current cron, and reports these changes separately; every other option/business difference still fails. Final accepted runs have no such differences. A too-short synchronization deadline also produced an incomplete race attempt; extending the fixture wait fixed that harness timing. All diagnostic fixture rows were removed through exact owned cleanup while preserving current site progress.

## Next engineering work

1. Finish the complete delivery stream, reference/asset components and the common snapshot coordinator; fence the remaining non-domain configuration writers.
2. Implement reviewed, recoverable DB/file restoration with suspended delivery/automation/request execution and exact identity mappings.
3. Add optional bounded recipient notices and request expiry with their own durable contract; pending requests have no issued Version, so they cannot be forced into the current issued-email schema.
4. Accept independent canonical worker/approval/cancellation races, native bulk upload-map-review-commit and native v2 app connection commits.
5. Complete the remaining widget/role/error/editor journeys before the separate release and LearnDash stages.
