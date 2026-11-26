# WAZ Cooling Control IPMI support and validation

The plugin remains `md12xx.fancontrol` for configuration and updates. Its display name is WAZ Cooling Control. Existing MD12xx identities, commissioning, Auto settings and manual choices are retained. IPMI settings are separate and start disabled with iDRAC automatic control selected.

## Independent device curves

The existing Auto curve is the Global curve. Each shelf and IPMI independently selects **Global** or **Individual**. Global shares the curve definition, never the temperature: shelves use their own assigned disks; IPMI uses the hottest installed CPU and an independent inlet guard. Individual retains its own 2â€“10 point curve even when switched back to Global. Global/Individual combinations are supported.

Configured shelves remain visible when disconnected so their faults can be reviewed. Unavailable device sections are hidden; **Show unavailable controls** reveals setup and compatibility information. Shelf and chassis enablement are separate.

Each Individual curve retains a separate downshift hysteresis (0â€“10Â°C). IPMI defaults to 1Â°C; existing shelf configurations inherit their previous shared value when no individual setting is saved. Global devices use the Global hysteresis. Speeds rise immediately; a downshift waits until temperature is below the higher speed's entry threshold minus hysteresis. For example, a 40Â°C entry point with 1Â°C hysteresis remains active at 39Â°C and drops below 39Â°C. Zero disables the hold. Curve/mode changes and firmware/fault transitions reset IPMI Auto history. Thermal/sensor handback always bypasses hysteresis. This behavior is covered by software fixtures; live hardware validation is pending a native plugin update.

Individual IPMI curves allow 15â€“100% fan targets. Global and MD12xx curves retain a 20% minimum; IPMI manual presets remain 20â€“100%. The last curve point is the maximum for normal curve control, but emergency iDRAC handback can exceed it. A curve targets a temperature range without guaranteeing it under every workload or inlet temperature.

## IPMI implementation and dependencies

Initial support is limited to Dell PowerEdge R730/R730xd on x86-64 Unraid. The bundled ipmitool 1.8.19 is built from upstream commit `19d78782d795d0cf4ceefe655f616210c9143e62` in official Alpine image `sha256:21f3e19bafab57a9ff7aa9dd571dac72f868370472c10f450cc0416cc1aca553` with musl 1.2.5 and OpenSSL 3.5.9. The executable is static PIE: no separate userspace library package or other IPMI plugin is needed. Licenses and dependency SHA-256 checksums ship in `bin/`; the installer verifies their exact bytes before stopping any service.

The device drivers come from Unraid's own matching kernel. Startup loads `ipmi_devintf`/`ipmi_si` on supported Dell hosts. The backend always uses the local `open` transport; it does not accept BMC addresses, passwords, arbitrary commands, power actions or firmware operations. The embedded upstream executable includes other transports, but the plugin's command runner cannot invoke them. A small local manufacturer dictionary includes Dell's public IANA PEN 674.

`scripts/build-ipmitool-container.sh` builds the dependency inside the pinned Alpine environment with `/work` as its only bind mount. The upstream archive SHA-256 is checked before building. Package repositories may evolve; the source/base are pinned, but byte-identical recompilation is not claimed.

## Safety and recovery

- IPMI requires complete healthy CPU, inlet and six-fan telemetry before manual or curve control.
- CPU â‰¥70Â°C, inlet â‰¥35Â°C, invalid or ambiguous telemetry, or a failed fan command returns control to iDRAC and latches plugin control off pending review and save.
- In Auto with recent healthy, trusted telemetry, one incomplete or failed sensor read hands control to iDRAC. The hold starts after the BMC acknowledges handback. Auto resumes only after 60 seconds and two fresh, complete samples from the same sensors at least five seconds apart. Recovery that takes 90 seconds, another fault within five minutes of recovery, or a controller restart during the hold requires manual re-arm. Manual control still requires review after a failed read.
- A command acknowledgement is not physical fan-response proof. RPM telemetry is shown separately.
- A persistent ownership marker is written before entering manual mode, bound to a hash of the local chassis identity, and removed only after an acknowledged iDRAC automatic command. It is not rewritten every poll.
- The independent supervisor restores iDRAC control after controller death or stale status. Startup handles an outstanding ownership marker before applying settings. Copied ownership from another chassis cannot issue commands there.
- A failed automatic restoration retains ownership, reports manual recovery required and prevents ordinary removal. Do not run another chassis fan controller concurrently.

## Validation status

### Recurring latch investigation â€” 2026-10-28

[Issue #35](https://github.com/TheIlluminate92/unraid-md12xx-fan-control/issues/35) tracks a second owner-reported unexpected chassis-control latch. Subsequent healthy readings do not identify the original trigger. Prior controller code overwrites the triggering reason on the next poll and repeatedly touches the empty latch, so its modification time cannot date the incident. The original reason for the reported episodes remains unknown; #27's hardware validation is still incomplete.

The 0.5.2 diagnostic change preserves the first incident in runtime `ipmi-fault.json` and a private persistent `ipmi-last-fault.json` beside the IPMI configuration. Only the latest incident is retained. The snapshot records normalized readings, the preceding healthy sample and command/query results; it does not copy raw configuration, host identities or raw SDR output. Settings shows the active incident's reason and time. Historical last-fault evidence is returned separately and must not be mistaken for an older latch's unknown trigger.

These writes occur once per newly latched incident, with no per-poll history updates. In the unreleased November 26 candidate, ipmi-rearm-required.json is the active persistent fence. An interrupted transient check retains its separate recovery marker. Firmware handback is still attempted if the active marker cannot be written. Any outstanding ownership record remains a restart fence until the durable requirement is established. A faulted status uses the incident identifier for an explicit re-arm. Re-arm requires acknowledged firmware mode with no outstanding owner and fresh healthy readings below the thermal limits. Saving firmware settings preserves the requirement. The first-fault history remains after re-arm. API and supervisor status reject future, invalid, missing or expired timestamps.

Software fixtures use fake IPMI and relocated paths. Physical reboot, native WebGUI acceptance, rollback and real-load behavior remain open. The older October 28 logging update and explicit latch reset retain their historical hardware record.

The native-installed preview successfully read both CPU temperatures, the inlet and all six fan RPMs on an R730xd running Unraid 7.3.3. A manual 80% command produced approximately 14,040â€“14,400 RPM; explicit firmware handback succeeded and returned fan speeds to approximately 8,760â€“9,000 RPM. Idle Auto/Global operation at 25% produced approximately 5,520â€“5,640 RPM with CPUs at 37/36Â°C. Individual Auto operation with a 40% curve ceiling also passed at idle. Synthetic tests cover sensor loss, thermal guards, command failure, restore failure, ownership, mixed curves and a 15% individual target. Load, live 15% response, actual process-death recovery and reboot validation remain pending. Existing MD12xx commissioning was preserved. The owner authorized Beta publication with these testing limits recorded.
