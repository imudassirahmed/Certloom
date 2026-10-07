# Pending workflow health and scheduling checkpoint

Date: 3 October 2026. Product implementation remains in progress; this checkpoint does not grant release or LearnDash acceptance.

## Behavior

The organization’s Certificate requests screen now has a private, read-only scheduling panel. Fourteen bounded indexed queries count due requests and pending job states, capped honestly at 50+. The panel reports configuration dispatch, the pending watchdog, traffic-driven cron and app configurations needing review without returning customer values, payloads, connection names or secrets.

Queue due checks requires credential management and issuance permissions, current write authority, and an exact confirmation body. It restores the pending watchdog and schedules the existing pending/configuration handlers. It does not directly issue, approve, qualify or run unrelated job types. A deliberately paused pending job prevents repair. A checked transaction rolls back scheduler, jobs and configuration dispatch when scheduling fails; affected option caches are invalidated.

## Accepted local evidence

Environment: authorized Local `ecapla.local`, WordPress 7.1.2, MySQL 8.4.0, PHP 8.5.1 and 8.2.29. Mail and external HTTP are disabled in rollback probes.

- `tests/wp-integration/run-pending-request-admin.php`: 91 checks on each runtime. Final evidence: `output/program-recipient-20261003/pending-health-php85-fault-accepted.json` and `pending-health-php82-fault-accepted.json`. Both preserve tracked rows, Certloom options and cron. Checks cover private read/error headers, read-only permissions, exact command bodies, all query plans, honest 50+ counts, paused work, missing-watchdog reads, injected scheduling failure rollback and malformed configuration.
- Fourteen focused pure suite executions pass without failures, skips or diagnostics in `output/program-recipient-20261003/pending-health-final-pure`. New PHP files and the pending-request administration JavaScript pass syntax checks.
- Visible Lance native controls pass in `output/playwright/pending-health-controls-20261003-retry.txt`. The real health GET is HTTP 200 with private/no-store headers. The native scheduling POST is HTTP 200 with zero checks queued, after confirming the site had no pending work. Keyboard Enter closes the disclosure. At 390 px, document width is 375 px with no horizontal overflow.
- Desktop and mobile screenshots, `output/playwright/pending-health-desktop-20261003.png` and `pending-health-mobile-20261003.png`, were visually inspected: labels, statistics, scheduler explanations, action and job-history link fit without clipping.

An earlier browser attempt reported a hidden page; selecting the authorized Lance tab and repeating produced the accepted visible run. The installed Elementor nullable-parameter deprecation remains an external PHP 8.5 diagnostic. No full database restore was performed.

## Remaining

Optional reminders and request expiry, advanced queue filters, supported first-party pending adapters, recipient native browser journeys, coherent whole-state recovery/reconciliation and actual worker/issuance concurrency remain separate checkpoints. The health panel does not imply that these are complete.
