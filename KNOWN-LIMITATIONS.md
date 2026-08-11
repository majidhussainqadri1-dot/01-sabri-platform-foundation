# Known External Acceptance Boundaries

No unresolved blocker/critical product-code defect is knowingly accepted in the reviewed **2.0.1 repository candidate scope** after the latest completed corrective round. This statement is repository-scoped, not a live-system assertion. The following remain external evidence gates rather than automatically satisfied by source/CI:

- Hostinger staging reality freeze of the exact deployed File 01 build, database/schema and migration state.
- Hostinger fresh install and every supported upgrade path on the actual WordPress/PHP/MySQL/LiteSpeed environment.
- Real companion contract/coexistence evidence required by the current File 01 plan and dependency/ownership boundaries.
- Browser/device, keyboard, screen reader, 200–400% zoom, RTL and weak-network acceptance.
- Representative production-size load/soak, cache, cron, queue and provider-failure measurements.
- Independent backup/restore and full rollback rehearsal with real operational evidence.
- Founder staging acceptance, production change approval, exact deployment parity, smoke testing and post-deployment monitoring.

Until those pass, `Staging-Accepted`, `Live-Deployed`, and `Operational` remain pending even when `Coded`, `Packaged`, and `Automated-QA Green` are complete.
