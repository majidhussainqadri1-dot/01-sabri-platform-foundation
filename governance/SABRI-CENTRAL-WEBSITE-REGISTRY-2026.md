# Sabri Social Homeopathy Platform — Central Website Integration Registry
**Working draft: 2026-10-21.** Governance and consolidation index only. No code was moved, rewritten, or deployed by this file.

## Founder consent: non-delegable major decisions
**Founder directive — 21 October 2026 (Asia/Karachi), explicit.**
> "کوئی بھی بڑا فیصلہ میری مرضی کے بغیر خود نہ کرنا، اس ویب کا۔"

**Binding rule:** No major decision concerning **Sabri Social Homeopathy Platform for the Homeopathic World** may be made, approved, merged, deployed, or implemented autonomously. Obtain the Founder's **specific, explicit, prior approval** for each materially consequential decision. Silence, prior broad instructions, a tool's technical capability, a completed draft, passing tests, and absence of objections do **not** constitute consent.

This includes changes to product scope, governance and canonical ownership; integration, separation, migration or deletion of repositories/plugins; architecture, security/privacy policy, users' rights, clinical or payment/fee policies; database/data migrations and destructive operations; production/staging deployment, rollback with material impact; domain/branding; and public release or binding commitments.

**Permitted without additional approval:** read-only fact finding, non-destructive inspection, analysis, comparison, preparation of clearly labeled draft documents, proposed patches on isolated review branches, and tests in safe local environments — so long as they do not change deployed systems, policies or irreversible data. Explicitly mark recommendations **"تجویز — منظوری باقی"** until approved.

**Approval record required:** decision ID, exact proposal and options, likely consequences, risks, affected modules/files and users, test/migration/rollback plan, Founder's explicit response and date, approved scope, and verification outcome. No inferred or blanket authorization.

## Delegated authority for minor decisions
**Founder directive — 21 October 2026 (Asia/Karachi):** The assistant may independently make all **minor, routine, low-risk and reversible** decisions for the website, without repeatedly seeking Founder approval. This delegation complements, and does not repeal, the explicit prior-approval rule for **all major decisions**.

**Examples within delegated scope:** wording, editorial polish, nonmaterial layout/spacing, internal organization, consistent naming/casing per approved brand, routine documentation, harmless refactors with proven identical behavior, test-case selection, and non-destructive housekeeping that does not affect live operation or materially change product behavior.

**Non-delegable major decisions remain Founder-only:** business or monetization policies; product scope or architecture; identity/permissions and user rights; clinical/legal/privacy/security policy; schema/data migration or destruction; repository/module consolidation or deletion; materially changing production behavior; any staging/live deployment or high-risk rollback; financial operations and irreversible changes. The existence of a draft PR or successful tests is not an authorization to merge/deploy.

**Decision threshold:** when uncertain whether a decision is minor, treat it as major and request explicit approval with options, risks and an impact summary. Record autonomous minor decisions in change notes and test/review evidence as appropriate. No blanket approval for security-sensitive or production actions is inferred.

## Official and short website names
**Founder-approved naming decision (21 October 2026):**
- Full English name: **Sabri Social Homeopathy Platform for the Homeopathic World**
- Official short name: **Sabri Homeopathy**
- Website domain: **SabriHomeopathy.com**
- **Supersedes** any former designation of “Sabri Social Homeopathy Platform” as the official short name. Retain the full name as a formal project title where appropriate. This is a documentation/branding specification update only; no live site rename or deployment is implied.

## Purpose
Use **one central website integration control plane** but retain canonical domain ownership and independently testable components. Do not flatten all plugins into one PHP file or treat a GitHub repository as proof of the live deployment. This registry is currently hosted in File 01 as a transitional coordination document, **not** a declaration that File 01 owns all modules.

## Source inventory
GitHub connector returned these 34 accessible repositories. Their names are observed; branch/HEAD and live parity must be frozen separately for each one.

| # | Repository | Verification pending |
|---|---|---|
| 1 | [00-sabri-membership-core](https://github.com/majidhussainqadri1-dot/00-sabri-membership-core) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 2 | [01-sabri-platform-foundation](https://github.com/majidhussainqadri1-dot/01-sabri-platform-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 3 | [02-sabri-authentication](https://github.com/majidhussainqadri1-dot/02-sabri-authentication) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 4 | [03-sabri-profiles-and-doctors](https://github.com/majidhussainqadri1-dot/03-sabri-profiles-and-doctors) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 5 | [04-sabri-news-feed-and-publishing](https://github.com/majidhussainqadri1-dot/04-sabri-news-feed-and-publishing) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 6 | [05-learn-sabri-classical-homeopathy](https://github.com/majidhussainqadri1-dot/05-learn-sabri-classical-homeopathy) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 7 | [06-homeopathy-encyclopedia-foundation](https://github.com/majidhussainqadri1-dot/06-homeopathy-encyclopedia-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 8 | [07-doctors-directory-and-discovery](https://github.com/majidhussainqadri1-dot/07-doctors-directory-and-discovery) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 9 | [08-worldwide-clinic-and-appointments-foundation](https://github.com/majidhussainqadri1-dot/08-worldwide-clinic-and-appointments-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 10 | [09-global-doctor-onboarding-and-verification-completion](https://github.com/majidhussainqadri1-dot/09-global-doctor-onboarding-and-verification-completion) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 11 | [10-video-wall-and-educational-broadcasting-foundation](https://github.com/majidhussainqadri1-dot/10-video-wall-and-educational-broadcasting-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 12 | [11-reels-and-short-video-discovery-foundation](https://github.com/majidhussainqadri1-dot/11-reels-and-short-video-discovery-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 13 | [12-pdf-library-and-digital-reading-foundation](https://github.com/majidhussainqadri1-dot/12-pdf-library-and-digital-reading-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 14 | [13-sabri-welcome-intro-animation](https://github.com/majidhussainqadri1-dot/13-sabri-welcome-intro-animation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 15 | [14-global-clinic-usp-and-conversion-sections](https://github.com/majidhussainqadri1-dot/14-global-clinic-usp-and-conversion-sections) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 16 | [15-radar-symptom-and-remedy-analysis-foundation](https://github.com/majidhussainqadri1-dot/15-radar-symptom-and-remedy-analysis-foundation) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 17 | [16-ai-sabri-homeopathy-study-guide-temporary-bridge](https://github.com/majidhussainqadri1-dot/16-ai-sabri-homeopathy-study-guide-temporary-bridge) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 18 | [17-sabri-network](https://github.com/majidhussainqadri1-dot/17-sabri-network) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 19 | [18-sabri-marketplace](https://github.com/majidhussainqadri1-dot/18-sabri-marketplace) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 20 | [19-sabri-unified-notifications](https://github.com/majidhussainqadri1-dot/19-sabri-unified-notifications) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 21 | [20-sabri-unified-application-shell](https://github.com/majidhussainqadri1-dot/20-sabri-unified-application-shell) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 22 | [23-sabri-doctor-founder-publishing-dashboard](https://github.com/majidhussainqadri1-dot/23-sabri-doctor-founder-publishing-dashboard) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 23 | [24-sabri-platform-security-privacy-compliance-and-resilience-center](https://github.com/majidhussainqadri1-dot/24-sabri-platform-security-privacy-compliance-and-resilience-center) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 24 | [25-sabri-public-ui-profile-timeline-visual-experience](https://github.com/majidhussainqadri1-dot/25-sabri-public-ui-profile-timeline-visual-experience) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 25 | [26-search-discovery-recommendations-knowledge-graph-classification](https://github.com/majidhussainqadri1-dot/26-search-discovery-recommendations-knowledge-graph-classification) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 26 | [cf-01-clinical-records-prescription-follow-up](https://github.com/majidhussainqadri1-dot/cf-01-clinical-records-prescription-follow-up) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 27 | [cf-02-support-appeals-case-management](https://github.com/majidhussainqadri1-dot/cf-02-support-appeals-case-management) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 28 | [cf-03-payments-billing-donations-financial-operations](https://github.com/majidhussainqadri1-dot/cf-03-payments-billing-donations-financial-operations) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 29 | [cf-04-central-media-processing-secure-delivery](https://github.com/majidhussainqadri1-dot/cf-04-central-media-processing-secure-delivery) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 30 | [cf-05-analytics-metrics-institutional-intelligence](https://github.com/majidhussainqadri1-dot/cf-05-analytics-metrics-institutional-intelligence) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 31 | [cf-06-localization-translation-operations](https://github.com/majidhussainqadri1-dot/cf-06-localization-translation-operations) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 32 | [sabri-unified-application-shell](https://github.com/majidhussainqadri1-dot/sabri-unified-application-shell) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 33 | [sabri-complete-home-news-feed](https://github.com/majidhussainqadri1-dot/sabri-complete-home-news-feed) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |
| 34 | [sabri-universal-post-composer](https://github.com/majidhussainqadri1-dot/sabri-universal-post-composer) | Inspect exact HEAD, plugin slug, schema, dependencies and deployed parity before import |

## Source manuscripts currently available (9)
1. Consolidated Governing Master Plan (179 source pages).
2. Traffic Analytics and Disease Intelligence, combined (57 pages).
3. File 16 Homeopathy AI (38 pages).
4. File 17 Communication Network (71 pages).
5. File 18 Marketplace (38 pages).
6. CF-01 Clinical Records (54 pages).
7. CF-02 Support and Appeals (49 pages).
8. CF-05 Analytics (52 pages).
9. CF-06 Localization (53 pages).

Preserve these full source documents in the separate unified Word planning book; **591 original source pages** in all. Other File 00–26 / CF-03–04 plans are not part of this available Word subset. Do not assert completeness of missing sources.

## Distinct truths / release gate
- **Repository reality:** exact repo, branch and commit SHA, package source checksum.
- **Staging reality:** exact deployed staging artifact + schema/migrations + acceptance evidence.
- **Live reality:** active runtime/plugin version, deployed files/package/checksum, DB/schema tables/rows, wp_options, migration flags, logs and dependencies.
- GitHub fixes are not live fixes. If deployed and repository code differ, suspend normal debugging and perform Deployment-Parity Audit before any patch.
- If deployed artifact cannot be obtained, report literally: **"Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔"**
- Root cause only after exact-deployed-state verification; correction -> test -> package -> staged approval -> deploy -> live retest -> parity confirmation.

## Documentation reconciliation queue (not automatic policy overrides)
- **C-01**: July plan's PKR 400 educational membership vs later free-tier/donation-only plan; require a dated approved decision and contract review.
- **C-02**: File 17 source mentions differing historical repository aliases; observed GitHub repository is `17-sabri-network`.
- **C-03**: CF-05 / CF-06 filenames indicate v1.1 September amendments, whereas opening pages retain v1.0 August identifiers.
- **C-04**: apparent 20/21/22 GitHub alias/duplicate repositories need exact-HEAD canonical-owner audit.
- **C-05**: conditional future modules remain activation-gated; existence of source repositories is not proof of production activation.

## Canonical separation
File 00 identity; 01 shared registry; 17 communication; 20 shell; 21 publication truth; 24 assurance; 25 UI/presentation; 26 search/discovery. Clinical, payments, support, analytics and localization owners must be activated only after approved change-control and owner/data migration. Read models, search, analytics, caches and UI are not canonical write authorities.

## Required defect report
**Defects found / Fixed / Unresolved / Blocked / Verification pending** plus **Tests passed / failed / not run**. For every Live incident report **Repository HEAD / Deployed Version / DB Version / Migration State / Live Verification Status** separately.

## Next controlled actions
1. Inventory ALL 34 exact HEAD SHAs, licenses, WordPress slugs, DB tables and dependencies.
2. Freeze actual live runtime and assess repo↔deployed parity.
3. Decide centralized monorepo or orchestrated multi-repo through approved architecture ADR, with history preserved.
4. Migrate incrementally behind draft PRs; run full cross-module contract/security/migration tests; stage and re-test.
5. Deploy only with authorized backups, rollback, founder approval and live verification.

**This document itself implements no website feature and fixes no live defect.**
