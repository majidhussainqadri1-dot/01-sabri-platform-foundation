# File 01 / V12 — Three source-gap remediations (2026-11-03)

This is **candidate-source remediation**, not a staging, production, companion, or deployment-parity acceptance. The historical `V12-43-REQUIREMENT-ACCEPTANCE-AUDIT.md` records deficiencies in its audited predecessor commit; this document records the subsequent fixes without rewriting that historical finding.

## G-01 — F01-FUT-003: pinned cross-repository architecture intake

`SPF_Governance_Control_Plane::lint_pinned_repository_sources()` reads only explicit `https://api.github.com/repos/<owner>/<repo>/contents/<path>?ref=<40-hex-commit>` contents with WordPress safe HTTP GET (no redirects, no arbitrary host, bounded payload, HTTPS). Each declaration must have canonical repo, exact commit, declared module owner, approved bounded manifest path and an independently supplied exact SHA-256. Invalid, missing or changed sources fail closed. Only checksum-verified JSON manifests enter `lint_architecture`, which checks canonical owner/route/shell conflicts. The response contains **per-source provenance** and source coverage truth.

**Remaining external dependency:** Real companion repository commit/path/digest references must be supplied and validated. This code does not assert that live deployed plugins correspond to repository files, that other repositories possess approved manifest files, or that every repo has been audited. No foreign repository writes occur.

## G-02 — F01-FUT-004: automatic candidate evidence collection

`SPF_Governance_Control_Plane::audit_v12_local_sources()` enumerates the exact **43** canonical V12 IDs, parses `TRACEABILITY.md`, validates exact local PHP source files and optional explicitly named test files against `SOURCE-CHECKSUMS.sha256`, checks declared implementation symbols, detects missing or repeated IDs and rejects path traversal. `tools/v12-traceability-audit.php` executes **after source QA** and writes a read-only, generated `build/v12-source-evidence.json` report bound to the checkout's claimed commit and CI run identifiers. No remote data or production credentials are needed.

**Trust boundary:** A source hash and a green local test step do not prove an independently completed GitHub workflow, an individually exercised negative test, or stage/live approval. `commit_verified=false`, `ci_result_verified=false`, and `coded_completion_claim_allowed=false` remain the conservative defaults until independent attestation. The production runtime traceability verifier separately remains deny-by-default. This scanner reports gaps; it does **not** claim 43/43 accepted.

## G-03 — F01-FUT-015: chaos fault-effect and recovery evidence

`SPF_Resilience_Lab::run_chaos()` continues to require the authorized reconciliation action, explicit `SPF_CHAOS_MODE`, and an allowlisted non-production environment. The fault hook gets a per-attempt 128-bit unpredictable challenge. Running the hook is no longer treated as a confirmed injection. A fault adapter must produce a bounded receipt with scenario, challenge, adapter identity, fault observation hash and recovery observation hash. An **independent observer** must affirm it through `spf_chaos_independent_observation_verified` before the result can assert `injected=true` and both evidence flags. Raw fault/recovery data are not written to the audit log, only a bounded evidence hash. Without those two gates the result explicitly says `unverified_no_success_claim`, not success.

**Remaining external dependency:** An independently authorized staging adapter and observer must prove a real and safely recoverable fault. These hooks are extension points, not an assertion that Hostinger or a companion service has already passed a chaos drill.

## Adversarial tests / acceptance

- `tests/v12-three-gaps-tests.php`: 2 pinned repositories, duplicate owner conflict, tampered hash rejection, missing exact commit rejection, genuine/forged local checksum, path traversal rejection, 43 canonical requirement scan, strict chaos challenge and fault/recovery evidence.
- `qa/run-tests.sh`: invokes the targeted tests and source evidence generator; all files must remain in the exact deterministic checksum inventory.
- This candidate remains **Draft** until separate 43-item independent reviews, real cross-file source declarations, operator acceptance, staging restore/rollback, Founder approval and deployment parity.

## Live-First incident truth

Repository main, source candidate, deployed package, DB schema/migration and live verification are distinct. Deployed version, DB version, migration state and live parity are **unverified**; prior Cloudflare 530 blocked the authorized inspection. **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔** No merge, staging deployment or production changes are performed by this remediation.
