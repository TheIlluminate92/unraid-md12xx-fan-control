# Changelog

## 0.4.3 - 2026-09-01

- Supervises the fan controller, restarts unexpected exits with a bounded 5–60 second backoff, and raises rate-limited local Unraid failure and recovery notifications.
- Shows watchdog restart state in Settings and includes redacted watchdog state in local diagnostics.
- Fails closed instead of starting a second writer when an existing controller cannot be stopped.
- Documents hardware prerequisites, adds Community Apps screenshots, and sets the tested minimum to Unraid 7.3.2.
- Adds a separate, user-initiated GitHub issue link beside the local diagnostics exporter.
- Warns that attachments to the public repository are public and requires users to confirm that they reviewed diagnostic archives before submission.
- Adds optional `.zip`, `.gz`, and `.tar.gz` diagnostic upload fields to the bug and hardware-report issue forms.
- Adds explicit AI-assisted development credits to Settings, the README, acknowledgements, and Community Apps metadata.
- Retains the local-only controller contract: no GitHub credentials, API calls, automatic uploads, analytics, or telemetry.

## 0.4.2 - 2026-09-01

- Refreshes every shelf's serial-adapter choices immediately after a selection so adapters already used elsewhere are disabled without reloading the page.
- Keeps automatic SES mappings, disk assignments, commissioning state, and RPM calibration server-authoritative so a stale Settings page cannot erase proven hardware while saving unrelated changes.
- Keeps five-second status refreshes read-only with respect to the page's configuration draft and falls back to the last saved calibration when collecting a form submission.

## 0.4.1 - 2026-09-01

- Uses Unraid's stock Disk Settings icon metadata for a reliable Settings tile across themes.
- Makes the Auto curve point count resize after typing or changing the requested count.
- Makes Refresh discovery save only discovery options and run one guarded pass immediately.
- Shows controller fault details, target reasons, telemetry state, and command state in the app.
- Prevents duplicate serial, SES, and disk assignments across shelf definitions.
- Blocks configuration and controller writes while Identify & test is running and stops tests safely during update or uninstall.
- Fixes completed test jobs rebuilding the form on later page loads.
- Supports both ZIP and tar.gz result downloads and removes hardware identifiers from redacted diagnostics.
- Hardens inline configuration encoding and session-expiry error handling.
- Treats stale controller state as a visible fault and recovers abandoned commissioning locks.
- Keeps the commissioning lock through the final mapping write and saves the measured 20%/50% RPM calibration.
- Verifies normal control commands against independent SES telemetry, detects later RPM drift, and retries failed responses.
- Requires intermediate targets to rise meaningfully above the commissioned 20% RPM baseline.
- Selects fail-safe speed for incomplete or changed disk mappings and clears commissioning after control-critical mapping edits.
- Covers duplicate Unraid names for one block device without selecting a flash alias as a shelf temperature source.
- Keeps Settings open when downloading diagnostics or commissioning results.
- Confirms control-affecting saves while the controller is already live.
- Passes a clean two-shelf hardware run covering automatic pairing, disk mapping, simultaneous Manual 50% control, independent RPM verification, and restoration to Auto/20%.

## 0.4.0 - 2026-09-01

- Adds fully app-driven per-shelf Identify & test with live progress and server-side continuation.
- Adds a packaged Settings icon and fixes shelf-name editing so status refreshes never steal focus.
- Uses generic competing-controller messages and reports shelf writes as blocked during a conflict.
- Lowers the default hardware telemetry interval to five seconds while configuration changes remain immediate.
- Removes installation-specific wording from the public interface and documentation.

## 0.3.0 — 2026-09-01

- Expands Manual mode to 20% through 100% in 10% increments.
- Automatically disables active FTDI connection testing after every configured shelf has passed commissioning; passive inventory remains available.
- Makes uninstall a complete reset by removing persistent configuration, commissioning results, runtime state, and the installed plugin file.
- Adds compact expandable setup directions, a visible Beta/testing notice, a shelf/fan icon, and a prominent associated-disk summary for each shelf.
- Reworks the Auto curve into 2–10 user-selected points with stacked temperature and speed fields.
- Adds a local diagnostics export with privacy redaction and no automatic upload.
- Adds Community Apps profile/wrapper metadata, credits, contribution guidance, and structured bug/hardware-report issue forms.
- Adds local-only security policy checks and a generic synthetic 24-bay MD1220 topology fixture alongside the existing MD1200 tests.

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
- Added a small installation banner.

## 0.1.0 — 2026-09-01

- Extracted the hardware-confirmed Dell MD1200 controller into a standalone plugin.
- Added standalone Unraid Settings UI and JSON status API.
- Added support for any number of explicitly configured MD1200 or MD1220 shelves.
- Added passive discovery for SES enclosures, serial adapters, and Unraid disks.
- Added optional background `_who` verification for likely FTDI adapters, gated off whenever any known fan controller is active.
- Uses the structured MD12xx response rather than requiring a `BlueDress` prompt.
- Preserved Auto, Manual, hysteresis, fail-safe, locking, RPM telemetry, and command reassertion behavior.
- Added a guarded per-shelf 20%/50% commissioning test.
- Defaults to disabled and blocks writes when a known legacy Docker controller is running.
