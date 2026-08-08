# Release Checklist — File 01 1.2.0

## Repository / coding gates

- [x] File 01 plan and latest central-plan ownership boundaries reconciled.
- [x] 00–26 governance/dependency metadata and single-free-tier/donation-only law represented without taking domain ownership.
- [x] Final post-coding Review/Fix Round 1 completed.
- [x] Final fresh/adversarial Review/Fix Round 2 completed.
- [ ] Exact final branch/head recorded after checksum normalization.
- [ ] PHP 8.1/8.3 source QA green on that exact final head.
- [ ] WordPress/MySQL runtime, concurrency, upgrade and purge tests green on that exact final head.
- [ ] Deterministic ZIP, SBOM, dependency manifest, source manifest and SHA-256 verified for that exact final head.

## External release gates

- [ ] Hostinger backup and independent restore test evidenced.
- [ ] Fresh install and supported historical→1.2.0 upgrades accepted on Hostinger staging.
- [ ] Real File 00/20/21/24/25/26 contracts and failure modes accepted.
- [ ] Browser/device/accessibility/RTL/weak-network matrix accepted.
- [ ] Rollback rehearsal accepted without silent data loss.
- [ ] Founder staging acceptance recorded.
- [ ] Production change window, smoke tests, monitoring and rollback window approved.

Unchecked external items block live release; they do not redefine repository coding completion.
