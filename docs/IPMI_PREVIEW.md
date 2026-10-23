# Combined cooling preview

The plugin remains `md12xx.fancontrol` for configuration and updates. Its display name is WAZ Cooling Control. Existing MD12xx identities, commissioning, Auto settings and manual choices are retained. IPMI settings are separate and start disabled with iDRAC automatic control selected.

## Independent device curves

The existing Auto curve is the Global curve. Each shelf and IPMI independently selects **Global** or **Individual**. Global shares the curve definition, never the temperature: shelves use their own assigned disks; IPMI uses the hottest installed CPU and an independent inlet guard. Individual retains its own 2–10 point curve even when switched back to Global. Global/Individual combinations are supported.

Configured shelves remain visible when disconnected so their faults can be reviewed. Unavailable device sections are hidden; **Show unavailable controls** reveals setup and compatibility information. Shelf and chassis enablement are separate.

Individual IPMI curves allow 15–100% fan targets. Global and MD12xx curves retain a 20% minimum; IPMI manual presets remain 20–100%. The last curve point is the maximum for normal curve control, but emergency iDRAC handback can exceed it. A curve targets a temperature range without guaranteeing it under every workload or inlet temperature.

## IPMI implementation and dependencies

Initial support is limited to Dell PowerEdge R730/R730xd on x86-64 Unraid. The bundled ipmitool 1.8.19 is built from upstream commit `19d78782d795d0cf4ceefe655f616210c9143e62` in official Alpine image `sha256:21f3e19bafab57a9ff7aa9dd571dac72f868370472c10f450cc0416cc1aca553` with musl 1.2.5 and OpenSSL 3.5.9. The executable is static PIE: no separate userspace library package or other IPMI plugin is needed. Licenses and dependency SHA-256 checksums ship in `bin/`; the installer verifies their exact bytes before stopping any service.

The device drivers come from Unraid's own matching kernel. Startup loads `ipmi_devintf`/`ipmi_si` on supported Dell hosts. The backend always uses the local `open` transport; it does not accept BMC addresses, passwords, arbitrary commands, power actions or firmware operations. The embedded upstream executable includes other transports, but the plugin's command runner cannot invoke them. A small local manufacturer dictionary includes Dell's public IANA PEN 674.

`scripts/build-ipmitool-container.sh` builds the dependency inside the pinned Alpine environment with `/work` as its only bind mount. The upstream archive SHA-256 is checked before building. Package repositories may evolve; the source/base are pinned, but byte-identical recompilation is not claimed.

## Safety and recovery

- IPMI requires complete healthy CPU, inlet and six-fan telemetry before manual or curve control.
- CPU ≥70°C, inlet ≥35°C, missing/unhealthy telemetry, or a failed command returns control to iDRAC and latches plugin control off pending review/save.
- A command acknowledgement is not physical fan-response proof. RPM telemetry is shown separately.
- A persistent ownership marker is written before entering manual mode, bound to a hash of the local chassis identity, and removed only after an acknowledged iDRAC automatic command. It is not rewritten every poll.
- The independent supervisor restores iDRAC control after controller death or stale status. Startup handles an outstanding ownership marker before applying settings. Copied ownership from another chassis cannot issue commands there.
- A failed automatic restoration retains ownership, reports manual recovery required and prevents ordinary removal. Do not run another chassis fan controller concurrently.

## Validation status

The native-installed preview successfully read both CPU temperatures, the inlet and all six fan RPMs on an R730xd running Unraid 7.3.3. A manual 80% command produced approximately 14,040–14,400 RPM; explicit firmware handback succeeded and returned fan speeds to approximately 8,760–9,000 RPM. Idle Auto/Global operation at 25% produced approximately 5,520–5,640 RPM with CPUs at 37/36°C. Synthetic tests cover sensor loss, thermal guards, command failure, restore failure, ownership, mixed curves and a 15% individual target. Load, live 15% response, actual process-death recovery and reboot validation remain pending. Existing MD12xx commissioning was preserved.
