# Safety maintenance and migration

Published versioned beta **0.5.4 / 2026.11.26.04**. The rolling installer remains at **0.5.3 / 2026.11.26.01**. The plugin includes bundled
R730/R730xd IPMI chassis support; see the [README](../README.md) and
[current IPMI scope, fault evidence and validation limits](IPMI_PREVIEW.md).
The 0.4.14 notes below retain their historical release scope. Their statement
that chassis control was planned is not the current implementation status.
Source review does not establish installation or hardware acceptance.

## November 26 MD1200 standby candidate (0.5.4 / 2026.11.26.05)

An enabled, commissioned MD1200 in Auto can use its proven 20% baseline when
every currently assigned disk has a clear Unraid standby report. This requires
complete current automatic mapping, matching enclosure identity, saved
independent RPM calibration, healthy SES fans and the existing exclusive
writer guards. The decision is per shelf. A manual disk mapping does not
establish current complete topology for this decision.

The target reason identifies the Unraid standby report and its unverified age.
An active disk uses the existing thermal curve on the next normal poll. Missing
or invalid active temperature, ambiguous standby, changed or incomplete
mapping, or unhealthy fan evidence keeps the existing fail-safe. No disk wake
or SMART query is added. This is independent of the separate commissioning
and quiet startup recovery workflows, which still require verified current
observations. Versioned .04 remains the prior rollback package. The rolling
installer remains at its published version.

## November 26 shelf Auto release (0.5.4 / 2026.11.26.04)

The accepted shelf Auto policy uses valid positive numeric Unraid reports for
active mapped disks. Reading age is unverified. The hottest usable active disk
feeds the existing Global or Individual curve and its downshift hysteresis.
A native normal standby report can exclude that disk without querying or waking
it. The normal green active/standby indicator and an explicit native spin flag
are accepted if present and consistent. A blank temperature or asterisk alone
does not establish standby. [Unraid WebGUI device list](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix/nchan/device_list)

Auto uses the greater of the failure setting and effective curve maximum for
uncertain mapping, ambiguous disk state, a missing or invalid active reading,
or zero usable active readings. This includes all-standby shelves. The existing
hardware identity, writer and commissioning guards continue to apply. The
release infers no reading age from file timestamps or polling cadence.

Explicit Manual, chassis recovery, and diagnostic redaction retain .03 behavior.
Normal and quiet commissioning ramps still require independently verifiable
current observations. The published .03 installer remains available for
compatibility rollback. Its policy chooses sustained shelf Auto fail-safe when
temperature or standby age is unverified.

## November 26 safety release (0.5.4 / 2026.11.26.03)

This release retains a chassis re-arm requirement across a controller
restart or loss of runtime files. An interrupted transient sensor check and
historical fault evidence remain distinct. An earlier package cannot interpret
the new active re-arm marker. Before considering an older runtime as rollback,
establish firmware control independently, persist chassis settings as disabled
and in firmware mode before any older writer can start, and carry the active re-arm requirement and first-fault evidence into the
operator handoff. Retain the current private configuration checkpoint.

The shelf controller can read reported disk temperatures and standby from the
Unraid cache but cannot verify when a particular observation last succeeded.
File modification time, polling cadence, and numeric or standby values alone
cannot establish freshness. Auto uses the greater of the validated failure
speed and the effective shelf curve maximum while input freshness or
automatic disk membership is unverified. Enclosure identity, writer and
commissioning guards still apply. Reported temperature and standby are
labeled as unverified, and the commissioned assignment stays in configuration.

Ordinary Identify & test, the manual identification ramp, and quiet startup
recovery remain blocked at preflight pending verifiable disk observations.
An interrupted test uses the existing conservative restore path, including
a higher saved manual target and 100% when ordinary commissioning cannot
verify candidate mapping. Existing RPM, enclosure identity and writer checks
are retained. Ordinary explicit Manual operation and chassis firmware control
keep their existing policies. No SMART query, refresh or disk wake is used.

On the current Unraid source this means sustained Auto fail-safe cooling,
which can increase noise and power use. When disk observations cannot be
verified, a fresh controller heartbeat does not clear the hold.

Verification is isolated from hardware. Other hardware requires separate
release and native acceptance. Existing 0.5.3 installer artifacts are unchanged.

## Current IPMI recovery: preserve evidence before acting

IPMI is the local management interface used to hand chassis cooling back to
Dell's iDRAC firmware controller. Before any separately approved re-arm, preserve
the first-fault record described in [IPMI scope and fault evidence](IPMI_PREVIEW.md).
Keep unresolved ownership markers: they indicate that automatic control has not
yet been confirmed. A failed return to iDRAC automatic control requires supervised
manual recovery, not another concurrent fan writer or repeated settings saves.
A successful settings save does not prove the recurring trigger is fixed.

Load testing, live 15% fan response, actual IPMI process-death recovery and reboot
validation remain pending in the current source record. A shelf-controller
recovery result is not proof of chassis IPMI recovery. Follow the linked current
record and the responsible machine owner's acceptance limits; this guide does
not authorize a test, re-arm, fan command or service restart.

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

In **0.4.14**, R730/R730xd chassis control was planned, not implemented in that release. The current implementation is described in the linked IPMI record above. That earlier plan required a separate iDRAC/IPMI backend, exact model/firmware validation, exclusive ownership, temperature safeguards, secure credentials if remote access is used, and a tested return to Dell automatic control. MD12xx serial commands must never be used for chassis fans.

## OS compatibility

Historical baseline for the October 20 record: Unraid 7.3.3. Accepted R730 Operations observations on November 8 recorded Unraid 7.3.4 and controller 0.5.2; they are bounded host observations, not broader compatibility certification. Local PHP 8.3 regressions and CI PHP 8.3/8.4 cover software behavior; no 7.4 hardware certification is claimed. Any future OS migration requires its own supervised plugin install/update/reinstall, authentication/CSRF, task tray, restart/reboot, disk-renumbering and thermal/fan failure checks. This documentation update performs none of those actions.

As of 2026-10-20, [7.4.0-rc.1](https://docs.unraid.net/unraid-os/release-notes/7.4.0/) is a pre-release. [Unraid 8 plans](https://unraid.net/blog/unraid-8-qa) describe a new Fedora/uCore plugin/permission system; adaptation is required and this package does not claim Unraid 8 support. Review [internal-boot/TPM/ZFS rollback constraints](https://docs.unraid.net/unraid-os/release-notes/7.3.0/) before migration.
