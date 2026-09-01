# Contributing

Bug reports, hardware results, documentation fixes, and conservative safety improvements are welcome.

## Hardware reports

Include the plugin and Unraid versions, shelf model, HBA model/driver, USB serial adapter chipset, number of populated bays, whether automatic disk mapping worked, and the complete commissioning PASS/FAIL summary. Use **Export local diagnostics** when useful; inspect the archive before attaching it. The plugin never uploads it automatically.

Do not post hostnames, IP or MAC addresses, disk serial numbers, share names, credentials, or unredacted `/dev/serial/by-id` identifiers.

## Code changes

Keep discovery read-only, fail closed on ambiguous hardware, retain independent SES RPM proof for commissioning, and preserve the final 20% restoration check. Run `bash tests/verify.sh` on Linux before submitting a change.

Changes that add outbound networking, cloud services, automatic telemetry, Docker socket access, broad filesystem access, or a speed command in discovery will not be accepted without an explicit redesign and security review.
