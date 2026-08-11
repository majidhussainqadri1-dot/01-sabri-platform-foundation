# Hostinger Staging Acceptance — File 01 2.0.0

This is the evidence checklist for the **2.0.0** staging candidate. Each item requires dated evidence, operator, result and evidence location. A repository/CI claim is never a substitute for the exact deployed staging state.

## Candidate contract identity

- Software version: `2.0.0`
- Database/schema version: `1.2.0`
- Contract version: `2.0.0`
- Canonical installable package name: `01-sabri-platform-foundation-2.0.0-FUTURE-FOUNDATION-SUPERSET-CANDIDATE.zip`
- Exact repository head and package SHA-256: capture from the final successful GitHub Actions artifact used for deployment; do not infer them from `main`, an older PR run or a filename.

## Staging Reality Freeze — capture before changing anything

Record the following before install/upgrade so repository truth and deployed truth cannot be confused:

- Repository candidate HEAD and tested tree.
- Currently deployed File 01 plugin version, deployed files/package and, where possible, deployed artifact checksum.
- Current database/schema version, relevant File 01 tables/columns/row counts and migration/options state.
- Relevant runtime error/log evidence and active WordPress/PHP/MySQL/cache/cron configuration.
- Active companion module versions/contracts used by this staging environment.
- Existing backup identity and isolated restore evidence.

If the exact deployed source/package cannot be verified, record: **“Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔”**

## Acceptance checklist

- [ ] Verify exact deployed ZIP checksum and internal `SOURCE-MANIFEST.sha256` against the exact GitHub artifact selected for staging.
- [ ] Create full database/files backup and prove restore in isolated staging.
- [ ] Fresh WordPress installation and activation; no public pages/posts/shell/feed created.
- [ ] Upgrade every supported historical File 01 software path to `2.0.0` with structured backup evidence; verify schema `1.2.0`, compensation and idempotency.
- [ ] Activate/deactivate/reactivate idempotently; schedules and Administrator bootstrap caps remain correct.
- [ ] Validate all 14 InnoDB tables, indexes, charset/collation and UTC timestamps.
- [ ] File 00 structured claims: view/manage/release/Founder/purge role boundaries and suspended/revoked/stale cases.
- [ ] File 20 shell placement and no duplicate navigation.
- [ ] File 21 ownership and File 20/21 reversible reconciliation receipts.
- [ ] File 24 backup/restore and purge-assurance adapters; purge only in a disposable restored copy.
- [ ] File 25 visual/accessibility boundary; no second theme/profile UI.
- [ ] File 26 registration/discovery boundary where the current dependency manifest requires it; no parallel search/ranking truth.
- [ ] REST permission/IDOR/CSRF/replay/rate/idempotency/concurrency tests.
- [ ] Release `planned → built → verified → staged → approved` transitions; direct promotion denied. Mere `staged` state is not Staging-Accepted.
- [ ] Privacy export/erasure/hold/retention; audit chain remains valid.
- [ ] System Check under WP-Cron and configured external cron; outbox retry/dead-letter.
- [ ] Current Chromium, Firefox, Safari, Edge; Android/iOS; keyboard, screen reader, 200–400% zoom, Urdu/Arabic/RTL and weak network.
- [ ] Production-size load/soak and database/cache/queue/provider failure tests.
- [ ] Full rollback rehearsal and post-rollback System Check.
- [ ] Founder functional, security, privacy, copy and staging acceptance.

No unchecked item may be silently represented as complete. `Staging-Accepted`, `Live-Deployed` and `Operational` remain separate evidence states.
