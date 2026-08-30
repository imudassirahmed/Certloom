CERTLOOM 1.6.98-qa.7 — QA UNGATED UX-OPS-05 CHECKPOINT

STAGING ONLY. NOT FOR PRODUCTION. NOT ACCEPTANCE EVIDENCE.

This derivative is built from the corrected 1.6.98 UX-OPS-05 source. It bypasses normalized-domain, public-verification and renderer release-authority readiness checks in memory for hands-on QA. It does not create accepted.json manifests, migration/cutover receipts or renderer dependencies. Actual renderer runtime requirements still apply.

QA.6 exposed the root form-integrity bug behind the apparently inert Add Program action: the quick-field dialog created an invalid nested form inside the Program/Batch POST form. QA.7 removes that nested form, hardens responsive popup padding/spacing and label/control layout, and provides visible required/error/saving feedback across Program, Recipient, Issuer, Batch and Signer editors. The earlier UX-OPS-03/04 corrections remain included. Hands-on acceptance on a real WordPress staging admin remains pending and is the next user acceptance gate.
