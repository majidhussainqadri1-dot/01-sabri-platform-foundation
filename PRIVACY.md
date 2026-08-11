# Privacy and Data Lifecycle

File 01 stores governance/operational metadata only: manifests, contracts, routes, release evidence, health snapshots, flags, audit, idempotency, outbox, privacy requests/holds and migration evidence. It does not own identity documents, patient charts, message bodies or payment credentials.

WordPress privacy exporters expose File 01 records linked to the requesting user. Erasure removes transient idempotency linkage and pseudonymizes File 01 privacy-request linkage. Hash-chained audit and append-only release-state facts are retained unchanged under the approved governance purpose; rewriting their actor fields would destroy integrity. Active legal/privacy holds block erasure.

Scheduled retention removes bounded health, expired idempotency and old outbox records. Audit-chain archival/deletion requires a separately verified checkpoint process and is not performed by ordinary retention.
