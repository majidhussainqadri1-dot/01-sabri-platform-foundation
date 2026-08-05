# Rollback

## Code rollback
Deactivate 1.0.0 and reinstall the previous immutable package only after confirming database compatibility.

## Reconciliation rollback
Use the exact confirmation `ROLL BACK FILE 01 RECONCILIATION`. This restores the File 01 legacy option snapshot and clears File 01 quarantine markers.

## Activation rollback
Activation failures restore the captured File 01 option snapshot and do not mutate companion data.

## Live rollback gate
A live rollback is not authorized until Hostinger backup/restore evidence, rollback package checksum, maintenance window, smoke tests and Founder approval are recorded.
