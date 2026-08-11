# File 01 — Eleventh Fresh Ten-Round Corrective Review — 2026-08-11

## Governing boundary
Repository/source and automated-runtime evidence only. It does not convert repository truth into Hostinger staging, live deployment, or sustained operational truth. File 01 remains the foundation/governance owner only; File 00 identity/authorization, File 20 shell, File 19 notifications, File 21 feed/publication, File 24 assurance, and other numbered domain owners remain canonical.

## Round-by-round record
| Round | Lens | Finding / correction | Closure |
|---|---|---|---|
| 1 | Seven-status lifecycle | **F01-R11-D001:** `staged` was mislabeled `Staging-Accepted`. Corrected so acceptance begins only at `approved`/`deployed`. | Fixed + regression. |
| 2 | Operational truth | **F01-R11-D002:** generic `verified=true` could satisfy Operational. Added release/package binding plus monitoring, support, backup/restore, SLO and observation evidence. | Fixed + regression. |
| 3 | Event dedupe | **F01-R11-D003:** explicit caller key was globally collision-prone. Scoped it to event/version/aggregate/privacy, with exact legacy replay lookup to avoid one-time upgrade re-emission. | Fixed + WP/MySQL regression. |
| 4 | Migration evidence | **F01-R11-D004:** backup evidence was not bound to module/from/to/environment. Added exact binding and fail-closed mismatch error. | Fixed + negative regression. |
| 5 | Purge evidence chain | **F01-R11-D005:** backup/File24 claims were not bound to exact purge plan/evidence chain. Added operation/plan/backup-hash/audit-head binding. | Fixed + purge regressions. |
| 6 | Architecture registry | **F01-R11-D006:** canonical entities/writes could silently normalize/collapse. Exact canonical keys and duplicate rejection are now mandatory. | Fixed + regression. |
| 7 | File 00 authorization boundary | No new defect found; sensitive mutations remain structured-claim fail-closed. | Clean. |
| 8 | Outbox replay/failure safety | No new defect found; ambiguous handler completion remains `reconciliation_required`. | Clean. |
| 9 | Ownership/package boundary | No new defect found; canonical folder and non-owner boundaries remain intact. | Clean. |
| 10 | Staging/live/operational + regression contract | **F01-R11-Q001:** an inherited Fresh-80 assertion still encoded the obsolete rule `staged = Staging-Accepted`; **F01-R11-Q002:** the first meta-regression guard then falsely treated the old text inside its own negative assertion as an active rule. Both QA contracts were corrected to verify semantics, not mere substring absence; product code was not weakened. | QA defect fixed + full regression rerun. |

**Defect-bearing rounds: 1, 2, 3, 4, 5, 6, 10.**  
**Clean rounds: 7, 8, 9.**

Added verification: `tests/eleventh-ten-round-review-tests.php` (10/10 review assertions); four negative/runtime regressions are integrated into `qa/wp-runtime-smoke.php` and mirrored in `qa/wp-eleventh-ten-round-smoke.php`; destructive purge smoke is updated. The existing corrective workflow therefore exercises the new runtime invariants without self-modifying workflow permissions.

## Acceptance boundary
Exact-head green CI/package proves only tested repository/source and disposable WordPress/MySQL scope. Hostinger staging, real companion coexistence, browser/device/a11y/RTL/weak-network, load/cache/cron, independent backup/restore + rollback, Founder staging acceptance, live cutover, and sustained Operational evidence remain separate mandatory gates.
