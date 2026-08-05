# Known Limitations and External Gates

This candidate deliberately does not claim the following without real environment evidence:

- Hostinger staging installation, upgrade and rollback;
- actual WordPress `dbDelta`, MySQL/InnoDB transaction and concurrent-lock behavior;
- active-theme, File 20 shell, File 21 Home/News, File 00 identity and File 24/25 contract coexistence;
- LiteSpeed/object-cache invalidation behavior;
- SMTP, cron and infrastructure-provider availability;
- real backup restore, browser/device, keyboard, screen-reader, zoom and RTL acceptance;
- Founder staging sign-off, live deployment, monitoring or operational SLOs.

The code fails closed for privileged integration when File 00 is present without its current assertion adapter. File 01 never substitutes for domain-native authorization.
