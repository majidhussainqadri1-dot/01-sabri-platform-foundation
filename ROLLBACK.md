# Rollback

## Activation/schema rollback

Activation and schema upgrade snapshot every File 01 table into a generated shadow table, record exact File 01 options, Administrator bootstrap capabilities and schedules, and restore them on failure. Newly created tables are removed; pre-existing tables are atomically replaced from shadows.

## Legacy reconciliation rollback

Rollback first invokes every canonical-owner rollback command using its stored receipt, then restores exact legacy options and all pre-existing values/existence states for File 01 quarantine metadata.

## Release rollback

Release evidence records are append-only. A deployed/staged release moves to `rolled_back` with required execution and post-verification evidence; history is never rewritten.

## Package rollback

Keep the prior verified ZIP and checksum, database/files backup, restore instructions, cache/index/queue reconciliation steps and a bounded rollback window. A code rollback must not reverse-destroy data added by the new version.
