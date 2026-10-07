Certloom renderer authority

This directory intentionally contains no accepted.json in the current unaccepted renderer release.
A future release may bundle accepted.json only after exact dependency-prefixed renderer candidates pass the complete PDF-04 production acceptance ceremony and human review.

Renderer authority is immutable and release-bound. A valid manifest must match the exact Certloom plugin version, exact reviewed production-payload fingerprint, acceptance/report/verifier provenance, and exact PDF/PNG/JPEG candidate identities. No WordPress option or request parameter may bypass this gate.

Current rendering contracts

ProductionRendererRuntime now registers the bundled CertloomVendor-prefixed mPDF 8.3.1 and native GD adapters. CredentialDownload serves authenticated, nonce-bound current valid credentials from the immutable issuance snapshot to credential viewers and linked owners. Registration does not grant authority; accepted.json is still required. Snapshot layouts using another family, weight or style fail closed rather than substituting a font.

vendor/ contains the exact 682 dependency files from PDF candidate 8.3.1+certloom.6. dependencies.lock.json records the upstream dependency versions; individual packages retain their license notices. fonts/ contains the exact Debian DejaVu 2.37-8 regular and bold faces and the DejaVu license notice. runtime.json records the frozen candidate hashes and explicit sans-serif face mappings: weights 400/500 use regular; weights 600/700 use bold. The 400/600 mappings and face bytes match the frozen eleven-fixture acceptance corpus. The added 500/700 mappings cover the built-in catalog and are separately exercised by run-template-rendering-live.php; they are also bound by the production payload fingerprint. Other families, italics and weights remain unsupported. These libraries and fonts are runtime inputs, not generated test artifacts. The upstream complete font collection remains in the frozen mPDF vendor payload to preserve its accepted candidate identity; trimming it requires a new candidate freeze and acceptance.

BundledRendererDependencies loads only the frozen CertloomVendor class map and prefixed function files. It avoids the shared global Composer bootstrap and file IDs, which can collide with another plugin's unprefixed dependencies. Vendor bytes remain unchanged.

The certloom-render-plan schema v1 is shared by PDF, PNG and JPEG adapters. Exact local font and image bytes are resolved by the coordinator before adapter execution; rendering cannot fetch remote assets or spawn processes. ArtifactReceipt v4 binds the artifact, scene, shared plan and renderer identity.

The certloom-renderer-acceptance v9 contract requires all eleven fixtures, including four Schema-4 fixtures, deterministic A/B artifacts, frozen golden and current-oracle comparisons, primitive coverage, and QR decoding in every format. RendererVerifierEnvironment v2 binds the verifier scripts and canonical production-verification entrypoint. VerificationReport v7 records the independent results. A successful automated check does not replace human artifact review.

Authorized renderer release packaging

Use tests/renderer-candidate/verify-production-bundle.sh to publish a new external three-file verification set. build-production-acceptance.php and finalize-production-acceptance.php validate that evidence and stage an external authority directory. After review, the release builder accepts it through CERTLOOM_RENDERER_AUTHORITY_DIR and revalidates the exact production payload. Generated candidates and evidence do not belong in source control.
