# Release Checklist

- [ ] Exact branch/head recorded; source clean; no secrets/private runbooks.
- [ ] Every FR/NFR maps to code, test and acceptance evidence.
- [ ] Review/Fix Round 3 complete; Fresh Adversarial Review/Fix Round 4 complete.
- [ ] PHP 8.1/8.3 source QA green.
- [ ] WordPress/MySQL runtime, concurrency, migration and purge tests green.
- [ ] Deterministic ZIP, SBOM, dependency manifest, source manifest and SHA-256 verified.
- [ ] Hostinger backup and independent restore test evidenced.
- [ ] Fresh install and 1.0.0→1.1.0 upgrade accepted.
- [ ] Real File 00/20/21/24/25 contracts and failure modes accepted.
- [ ] Browser/device/accessibility/RTL/weak-network matrix accepted.
- [ ] Rollback rehearsal accepted without silent data loss.
- [ ] Founder staging acceptance recorded.
- [ ] Production change window, smoke tests, monitoring and rollback window approved.

Unchecked staging/production items block merge-to-release and live deployment.
