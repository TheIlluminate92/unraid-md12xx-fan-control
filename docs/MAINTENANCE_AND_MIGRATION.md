# Safety maintenance and migration

## 0.4.14 / build 2026.10.20.01

This update preserves saved settings, identities, calibration, enablement, the healthy Auto curve, Manual speed choices, serial framing, reader-first sessions and existing RPM/SAS/operator pairing modes. No re-commissioning is required solely for this update. Review any reported fault instead of clearing existing commissioning.

- An unreadable temperature on **any active assigned disk** selects the configured sensor-failure speed in Auto; a valid reading from a peer no longer masks it. Sleeping disks retain their existing exception.
- SES parsing requires four individual Cooling elements. A zero, missing, disabled or failed fan is a fault. Overall/aggregate descriptors do not count as fans. Auto uses at least the configured failure speed for a fan telemetry fault; Manual remains an explicit operator target with the fault displayed.
- Ordinary commissioning now requires known, cool active disk temperatures below 35 C, a 20% required target, verified disk mappings for all candidate shelves, four healthy fan readings per candidate and no competing writer. Empty/unavailable mapping blocks the test. It monitors disks on all candidate shelves before the serial pairing is known, not unrelated internal SSD pools.
- Temperatures are checked before fan commands, during sample windows, and during identification/restoration waits. Increased demand aborts the calibration and restores at least the currently required cooling target; unreadable mapping restores 100%. A failed restoration remains a manual recovery situation, never a successful commissioning result.
- The supervisor checks fresh controller status and persistent faults, not only process survival. Fault/stale status produces rate-limited local alerts. It does not kill a live controller or create a second writer to fix a stale heartbeat. The stale budget includes the configured poll interval.

The new failure cases are covered by synthetic local regressions. Hardware validation of these new failure paths has **not** been performed. Previously documented 35% startup recovery hardware-validation limits still apply. Firmware with static SES RPM remains reduced-verification SAS/operator pairing; an acknowledgement is not physical RPM proof.

The new parser also accepted read-only SES snapshots from both production MD1200 shelves on 2026-10-20 (four healthy fans each). No fan command, service restart, failure injection or installation was performed for that check. This confirms output-format compatibility, not hardware behavior under failure.

## Installation and recovery

Use the canonical `md12xx.fancontrol.plg` registration. Back up `/boot/config/plugins/md12xx.fancontrol` independently before upgrading. Never uninstall an old alias to fix duplicate registrations: uninstall intentionally removes shared configuration and commissioning records.

The installer writes its payload to `/usr/local/emhttp/plugins/.md12xx.fancontrol.staged`, validates PHP and shell syntax, then stops the previous services and activates the staged tree. The previous successful runtime is retained at `.md12xx.fancontrol.previous`. A failed activation restores/restarts that tree only after the new services stop successfully. If a new writer cannot stop, recovery fails closed and the previous tree is retained for supervised recovery.

Runtime recovery does not restore configuration, plugin-manager registration metadata, or protect against power loss at every point. Retain a boot/config backup. Interrupted activation requires inspection of live/staged/previous trees before another install; do not run the scripts in those trees concurrently. Disabling/stopping this plugin does **not** prove that the enclosure reverted to automatic cooling. Confirm a known-safe hardware state before leaving it unattended.

## R730xd / R360 split

Run shelf control only on the host physically connected to the USB service adapters and SAS shelves. Do not start a copied configuration on both hosts. Stage the other server with hardware control disabled, use distinct network identity/licensing, and keep an independent backup.

Transfer configuration and commissioning records intact, then check adapter by-id paths, enclosure logical identities, mapped Unraid disks and fresh four-fan telemetry. Device numbers such as `/dev/sgN` are historical hints. Missing/changed disk names and ambiguous redundant SES paths require review; do not silently adopt another enclosure. Preserve working calibration when the same proven hardware pairing remains valid. Supervise any new commissioning.

R730/R730xd **chassis** control is planned, not implemented in this release. It requires a separate iDRAC/IPMI backend, exact model/firmware validation, exclusive ownership, temperature safeguards, secure credentials if remote access is used, and a tested return to Dell automatic control. MD12xx serial commands must never be used for chassis fans.

## OS compatibility

Unraid 7.3.3 is the current observed deployment baseline. Local PHP 8.3 regressions and CI PHP 8.3/8.4 cover software behavior; no 7.4 hardware certification is claimed. Validate the two-server move on the same stable OS first. Then test 7.4 plugin install/update/reinstall, authentication/CSRF, task tray, restart/reboot, disk renumbering and thermal/fan failure paths on a supervised test system.

As of 2026-10-20, [7.4.0-rc.1](https://docs.unraid.net/unraid-os/release-notes/7.4.0/) is a pre-release. [Unraid 8 plans](https://unraid.net/blog/unraid-8-qa) describe a new Fedora/uCore plugin/permission system; adaptation is required and this package does not claim Unraid 8 support. Review [internal-boot/TPM/ZFS rollback constraints](https://docs.unraid.net/unraid-os/release-notes/7.3.0/) before migration.
