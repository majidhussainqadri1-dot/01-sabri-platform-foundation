# Security Boundary

File 01 is a governance/registry runtime, not a security single point of failure.

Controls include capability checks, native-owner authorization hooks, CSRF protection through WordPress REST/admin mechanisms, idempotency keys, bounded mutation rate limits, optimistic record versions, owner-token activation locks, route collision checks, immutable release checksums, redacted diagnostics, tamper-evident audit hashes and non-destructive uninstall.

Secrets, provider credentials, identity documents, patient data, message bodies, payment data and sensitive incident runbooks are prohibited from File 01 registries and public repository documents.
