# Security

- File 00 is the identity/capability authority. Sensitive actions require versioned, short-lived, user/action/object/purpose-bound claims.
- Generic WordPress Administrators receive only view/manage bootstrap capabilities; release approval, Founder governance and purge are not granted.
- Every mutable owner record uses expected versions, transactions/locks or unique reservations.
- REST mutations require a 16–191 character idempotency key and reserve it before executing the callback.
- Route destinations are same-origin and canonical owner changes are rejected.
- Audit is tamper-evident and recursively redacts secret/private keys.
- Destructive purge is disabled by default, unavailable over REST, requires Founder claim, active-hold check, structured backup/restore evidence and File 24 assurance.
- Private operations material, secrets, credentials, patient records, message bodies and payment data must not enter this public repository or ordinary diagnostics.

Report suspected vulnerabilities privately to the platform owner. Do not post credentials, exploit payloads against the live service, or sensitive evidence in public issues.
