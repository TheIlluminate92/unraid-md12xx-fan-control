# Changelog

## 0.2.3 — 2026-09-01

- Opens the serial response reader before the command writer, matching the only session pattern that repeatedly applied both 50% and the following 20% on real MD1200 hardware.
- Marks a shelf uncommissioned when its test begins and refuses to restore commissioned status until independent SES telemetry proves RPM returned to the 20% range.
- Retries the 20% restoration once after a failed telemetry check and leaves the shelf uncommissioned on any remaining safety failure.

## 0.2.2 — 2026-09-01

- Adds a safe SES-to-disk mapping fallback for SAS HBAs that expose per-expander topology but no `/sys/class/enclosure` links.
- Prevents background read-only discovery from starting while commissioning is active and waits for an already-running probe to release the serial adapter.
- Makes the final 20% commissioning restore use the same guarded read/write console session after the lock is acquired.

## 0.2.1 — 2026-09-01

- Opens the BlueDress serial console read/write and drains command replies; real-hardware testing showed that write-only sessions could leave `set_speed` unapplied.
- Reports whether the console echoed the requested speed while retaining independent SES RPM telemetry as the proof of fan response.

## 0.2.0 — 2026-09-01

- Replaced the normal multi-select disk workflow with automatic SES-to-Unraid disk mapping.
- Added a guarded serial-to-SES identification test using independently measured 20% and 50% fan RPM response, with an unconditional 20% restore attempt.
- Requires the selected serial console to pass the read-only MD12xx identity check before any fan command is sent.
- Keeps the SES and disk selectors under a Manual mapping fallback for hardware where Linux does not expose enclosure-slot links.
- Refuses to commission ambiguous pairings or automatic mappings with no assigned Unraid disks.

## 0.1.3 — 2026-09-01

- Fixed the blank Unraid Settings page by resolving runtime files from the explicit plugin root rather than the page builder's `__DIR__` context.
- Added a packaging regression check for the Settings page loader path.

## 0.1.2 — 2026-09-01

- Moved development to the dedicated `TheIlluminate92/unraid-md12xx-fan-control` repository.
- Updated the plugin self-update and support URLs for the standalone repository.

## 0.1.1 — 2026-09-01

- Validated passive discovery against two real MD1200 shelves, two FTDI adapters, two unrelated Espressif serial devices, and an unrelated SES enclosure.
- Fixed a controller startup syntax error found by the Linux verification workflow.
- Added a small terminal installation banner.

## 0.1.0 — 2026-09-01

- Extracted the hardware-confirmed Dell MD1200 controller from WAZ Dashboard.
- Added standalone Unraid Settings UI and JSON status API.
- Added support for any number of explicitly configured MD1200 or MD1220 shelves.
- Added passive discovery for SES enclosures, serial adapters, and Unraid disks.
- Added optional background `_who` verification for likely FTDI adapters, gated off whenever any known fan controller is active.
- Uses the structured MD12xx response rather than requiring a `BlueDress` prompt.
- Preserved Auto, Manual, hysteresis, fail-safe, locking, RPM telemetry, and command reassertion behavior.
- Added a guarded per-shelf 20%/50% commissioning test.
- Defaults to disabled and blocks writes when a known legacy Docker controller is running.
