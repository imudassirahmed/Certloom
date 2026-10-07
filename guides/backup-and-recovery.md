# Back up and recover Certloom

Open **Certloom → Settings → Recovery snapshots**. A snapshot saves Certloom's certificate records, Programs, recipient details and requests, certificate and email designs, settings, delivery history, and referenced artwork, fonts and static email attachments together.

Keep a WordPress database and files backup too. WordPress accounts, courses, products and other website files belong to the website. Certloom reviews their connections during recovery instead of importing those objects or changing user roles.

## Save a snapshot

Choose **Create snapshot**, then follow **Recovery activity** until the capture completes. Download the completed `.cvrecovery` file from **Your recovery snapshots** and store it somewhere private. It contains organization and recipient information.

Manual snapshots remain available until you remove them. Scheduled snapshots follow the configured retention setting. Recovery creates a safety snapshot before replacement; safety retention keeps at least three recent copies. Review the completed copy before removing an older one. Removal requires the exact word **REMOVE** and deletes only the selected copy.

## Review a recovery

1. Select the `.cvrecovery` file and choose **Upload and review**. Wait for the safety capture to complete.
2. Review each recipient account, course, product and media connection. Search the current website by name or ID and choose the exact matching item. Suggested connections from the same installation still need review.
3. Choose **Disconnect** for anything you cannot confirm. An unresolved search is not a disconnection. A disconnected account cannot access the recovered recipient's credentials until staff review and link it later.
4. Certificate artwork and email attachment connections require the exact archived file bytes. Restore or upload those files to the website's Media Library first, or explicitly disconnect them. Matching filenames alone are insufficient.
5. Confirm that you reviewed all connections, then select **Verify connections**. Check the verified record counts and replacement explanation.
6. Type **REPLACE** and choose **Confirm and recover** only when ready to replace the current Certloom records and settings. To leave current records in place, choose **Discard review** instead.

Review is private to the organization administrator who uploaded the file. If the file, target records or selected connections change, refresh and review again before confirming. An older review cannot authorize a newer replacement.

## Follow progress and recover interruptions

Follow the exact job in **Recovery activity**. **Pause**, **Resume** and **Retry** act on that job; read its guidance before retrying. Certloom keeps its recovery controls available while an interrupted recovery is being resolved.

Before replacement commits, the organization can cancel a paused recovery using the offered return action and its **RETURN** confirmation. This preserves the previous data and completes cleanup. A running worker must finish or release its lease first. Once replacement has committed, this cancellation action cannot undo it; use a separately reviewed safety snapshot if another recovery is needed.

Previously issued certificate snapshots keep their original content. Recovered app mappings, email automation, pending requests and queued delivery remain paused for organization review. Sent delivery history is retained and is not replayed. Connections from another installation need their destination credentials set up again. Review these sources deliberately before enabling future work.

For recipient collection and request deadlines, see [Recipient details and certificate requests](recipient-details-and-certificate-requests.md).
