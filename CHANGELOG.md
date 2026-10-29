# Changelog

## Unreleased — IPMI first-fault evidence

- Preserve the first fault reason, timestamp, normalized sensor readings, preceding healthy sample and failed-command/query exit codes while control remains latched. Keep one private last-fault snapshot across reboot without rewriting flash on each poll.
- Show the active incident cause in Settings and distinguish legacy latches whose original cause is unavailable. Preserve supervisor-recovery context without claiming whether process loss, stale status or shutdown was responsible.
- Extend fake-IPMI lifecycle regressions for query failure, healthy recovery without re-arm, thermal handback and command failure. Existing immediate firmware fallback, thermal thresholds, ownership and explicit re-arm remain unchanged.
- Tracks #35. This diagnostic change does not establish or fix the recurring runtime trigger; supervised hardware investigation remains open. No installer/release or live rollout is included.

## 0.5.1 Beta — 2026-10-22 (build 2026.10.22.05)

- Fixes IPMI saves returning `Current session token required` after valid native Unraid CSRF validation consumes the POST token. Client token submission and native protection remain intact. Fixes #28.
- Makes global save success/errors visible when no shelves are configured; shared feedback now sits outside the optional MD12xx wrapper. Fixes #29.
- Adds actual endpoint regressions for consumed-token dispatch, curve/hysteresis persistence, invalid requests preserving configuration, and visible feedback. No fan curve, ownership or thermal guard policy changes.

## 0.5.0 Beta — 2026-10-22 (build 2026.10.22.04)

- Adds local R730/R730xd IPMI chassis control, bundled dependencies, independent Global/Individual curves, and hardware-aware sections under WAZ Cooling Control.
- Individual IPMI curves accept 15–100%; shared Global and shelf curves retain 20–100%. Curve maxima do not override emergency iDRAC handback. IPMI manual presets remain 20–100%.
- Each Individual curve now retains its own 0–10°C downshift hysteresis, defaulting to 1°C for IPMI. Global devices use the shared setting; legacy shelf settings are preserved. Upshifts and emergency handback remain immediate; curve/mode changes reset the previous Auto target.
- Live R730xd tests verified manual 80%, explicit return to firmware control, and idle Auto operation. Load, 15% hardware response, real crash recovery, and reboot tests remain pending.
- Published through the canonical Unraid plugin update channel with existing settings and commissioning retained. Builds 2026.10.22.01 and .02 are preserved as preview history.

## 0.4.14 — 2026-10-20

Partial active-temperature loss and failed/missing fans now fail safe in Auto. Commissioning monitors thermal demand and preserves higher cooling on abort. Status-aware supervision and staged runtime validation/rollback preserve saved commissioning. Adds safety/activation regressions and migration documentation. New failure paths are software-tested; supervised hardware validation remains pending.

See [maintenance and migration](docs/MAINTENANCE_AND_MIGRATION.md) for behavior, rollback boundaries, validation and server-split guidance.

## 0.4.13 — 2026-10-12 (build 2026.10.12.01)

- Reject noncanonical installer names and duplicate internal-name registrations before runtime/configuration changes.
- Document backed-up registration migration, filename-keyed update caching, and sequential duplicate checks; no automatic server cleanup.
- Use TheIlluminate92 attribution while retaining project credits and separate application/build metadata.


## 0.4.12 - 2026-10-11

- Fix Installed Plugins reporting test2 as up-to-date after the final release. Unraid uses text comparison, under which `0.4.11` sorts below `0.4.11-test2`; semantic minor versions also compare incorrectly across values such as `0.4.9` and `0.4.11`.
- Use update build `2026.10.11.01` for Unraid plugin-manager ordering and keep application version `0.4.12` in Settings and the status API. Expose `pluginVersion` separately in the API.
- Add a regression that compares the new installer against all retained installers using Unraid's `strcmp` behavior. Update both builders and preserve historical immutable packages.
- Keep the shipped identity tracking and guarded 35% recovery behavior unchanged. Successful commissioning and calibration remain valid.

## 0.4.11 - 2026-10-11

- Fix missing shelf RPM after SCSI enumeration changes: save the primary SES enclosure identity during verified setup and resolve it to the current device/address at startup and every telemetry cycle.
- Validate enclosure type, model, readable unique identity, and disk topology. Reject a disk at an old SES address and expose actionable mapping faults instead of querying the wrong target.
- Fix legacy commissioning that discovered the correct serial/SES match but discarded it by filtering to a stale address. Prefer exact identity matches; automatic RPM pairing still requires a unique response and verified restoration.
- Add the **Quiet startup recovery (35%)** setting, enabled by default. It permits one bounded background attempt per boot for a previously RPM-commissioned shelf whose startup identity check fails; normal startup identity resolution remains read-only.
- Gate quiet recovery on saved identities, prior calibration, known disk temperatures below 35°C, a 20% Auto target, exact current disk mapping, four healthy positive fan readings near the commissioned 20% baseline, a primary/active serial console, and no competing writer. Do not lower a faster-running shelf or probe an unconfigured enclosure.
- Require a unique 35% RPM response, the same saved identity and commissioned disks, and telemetry-proven return to 20%, with one restoration retry. Recheck temperatures while sampling; abort and restore at least the required cooling target if demand rises. Never silently replace an identity or commission a legacy shelf.
- Pause normal writes during recovery, serialize operator/automatic test launches, and cap the worker at 180 seconds plus a 20-second termination/restoration allowance. Failed attempts stay visible and do not repeat after watchdog restarts in the same boot.
- Preserve server-authoritative identities, commissioning, and calibration across Settings saves and host/device renumbering; redact identities from diagnostic exports. Legacy address-only configurations require one supervised re-pairing per shelf. Moving hosts requires configuration transfer and review of serial adapters and disk assignments; redundant paths remain ambiguous in this release.
- Consolidate duplicate disk-state parsing and shared response/restoration logic, use accurate speed labels in captures, normalize installer line endings before hashing, and clean up current release instructions. Preserve immutable historical test installers/checksums.
- Validation: local Linux and GitHub checks cover identity parsing, moved-host topology, swapped enumeration, stale disk targets, commissioning selection, settings preservation, thermal/baseline recovery gates, command blocking, and package/security checks. The affected top MD1200 shelf passed live test2 commissioning and verified restoration after one retry. The conditional 35% startup recovery path has not been exercised on live hardware; this final release is published at the owner's request with that limitation recorded.

## 0.4.11-test3 - 2026-10-11

- Add an optional once-per-boot 35% startup recovery test for previously RPM-commissioned shelves whose identity cannot be verified at startup.
- Require cool known disks, exact disk mapping, proven 20% baseline RPM, primary/active serial identity, unique response, and verified restoration. Abort on increased cooling demand; never adopt a different enclosure identity.
- Bound the worker, serialize startup/operator test launches, and preserve prior commissioning and calibration on failed recovery.
- Clean up duplicate disk-state parsing and misleading fixed 50% capture labels; keep one shared response/restoration workflow and an explicit recovery checkbox.
- Retain versioned published test installers and checksum history; keep the current install/test instructions separate from prior validation reports.

## 0.4.11-test2 - 2026-10-11

- Fix legacy re-pairing that found the correct serial-to-SES identity but then discarded it by filtering candidates to a stale saved address.
- Prefer the exact identity match during commissioning; retain independent RPM proof and final restoration checks.
- Without an exact identity match, automatic commissioning tests discovered candidates and requires a unique RPM response instead of trusting historical addresses.
- Reject duplicate identity matches before fan-speed testing; add regression coverage for the hardware report from test1.

## 0.4.11-test1 - 2026-10-11

- Normalize installer line endings before hashing so published checksums remain valid across Windows and Linux builds.

- Persist the primary SES enclosure identity during verified RPM, SAS, or physical commissioning.
- Resolve identities at startup and on every telemetry cycle so device and SCSI target renumbering cannot redirect a shelf pairing.
- Block control on missing, duplicate, unreadable, or model-mismatched identities; reject disks masquerading as saved SES targets.
- Require one supervised re-pairing for legacy address-only configurations; preserve server-authoritative identities across Settings saves and redact them from diagnostic exports.
- Cover moved-host topology, swapped enumeration, stale disk targets, ambiguous identity, and startup command blocking with synthetic regressions.
- Publish a versioned test installer on the PR branch for supervised Unraid validation; real-hardware upgrade and reboot validation are still pending.

## 0.4.10 - 2026-09-25

- Correlate the EMM `sas_address` ELI with the SES configuration-page enclosure identifier when RPM proof fails.
- Preserve independent RPM commissioning where it works and mark SAS-matched shelves as lacking live RPM fan-response verification.

## 0.4.9 - 2026-09-25

- Add operator-confirmed physical shelf pairing for firmware with static SES fan RPM, with a timed 50% ramp and 20% restore.
- Keep reduced verification visible during control and require serial acknowledgement; preserve disk-temperature and fail-safe control.
- Add opt-in EMM identity, version, and command-list capture.

## 0.4.8 - 2026-09-25

- Records a 30-second baseline, then samples 50% commissioning telemetry every five seconds until one unique enclosure has two consecutive higher-RPM readings within a 10% stability band or a 60-second safety timeout is reached.
- Includes the complete timed 20% and 50% RPM sample histories in commissioning result archives.

## 0.4.7 - 2026-09-02

- Rejects nested, traversal, control-character, and malformed serial adapter paths before any hardware access.
- Uses method-specific GET and POST action parsing in the WebGUI API, matching Unraid's plugin security guidance.
- Adds regression checks for crafted serial paths and method-specific request handling.
- Uses the public Unraid support thread in release and Community Apps metadata.

## 0.4.6 - 2026-09-02

- Shows the friendly **MD12xx Fan Control** name and a useful description on Unraid's Plugins page while retaining the stable internal package ID.
- Adds release checks that require the Plugins-page display metadata to remain packaged.

## 0.4.5 - 2026-09-02

- Uses Unraid's supported stock `server` icon on the Plugins page while retaining the existing Settings tile icon.
- Restores the GitHub bug and hardware-report issue forms using native attachment-capable text areas.
- Records a successful overnight Auto-mode soak on the two-shelf MD1200 test system with no reported control issues.

## 0.4.4 - 2026-09-01

- Expands hardware requirements with the tested Dell service/password-reset cable, FTDI USB-to-serial adapter, SAS HBA/SES path, and matching-EMM-firmware recommendation.
- Documents the independent command and verification paths and states explicitly that HBA access is read-only.
- Clarifies commissioning failure recovery and active/primary EMM selection.
- Marks MD1400, MD1420, split-mode, daisy-chain, redundant-path, and alternate-EMM configurations as unsupported or unverified until proven by hardware reports.
- Adds a Community Apps `Requires` field and expands the Beta hardware report to collect enclosure, cable, HBA, firmware, and topology details without unique identifiers.
- Documents the GitHub private vulnerability-reporting enablement path and warns against public disclosure.
- Records the passing Community Apps Validate and Scan results.

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
