# Changelog

## 0.1.0 — 2026-09-01

- Extracted the hardware-confirmed Dell MD1200 controller from WAZ Dashboard.
- Added standalone Unraid Settings UI and JSON status API.
- Added support for any number of explicitly configured MD1200 or MD1220 shelves.
- Added read-only discovery for SES enclosures, serial adapters, and Unraid disks.
- Preserved Auto, Manual, hysteresis, fail-safe, locking, RPM telemetry, and command reassertion behavior.
- Added a guarded per-shelf 20%/50% commissioning test.
- Defaults to disabled and blocks writes when a known legacy Docker controller is running.

