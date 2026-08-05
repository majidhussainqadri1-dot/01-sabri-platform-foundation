# File 01 Runtime QA Notes

This file records the final GitHub Actions runtime-test harness corrections for version 1.1.0.

- WP-CLI `eval-file` scripts omit `declare(strict_types=1)` because WP-CLI evaluates file contents inside its runtime wrapper.
- All runtime test paths are absolute and execute as the disposable WordPress administrator.
- The release gate still requires PHP 8.1/8.3 source QA, WordPress/MySQL runtime assertions, concurrent idempotency, destructive-purge smoke, deterministic packaging and external Hostinger staging acceptance.
