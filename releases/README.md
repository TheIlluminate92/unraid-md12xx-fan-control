## Local beta candidate 0.5.4 / 2026.11.26.04

This candidate uses reported numeric temperatures from active mapped disks for
shelf Auto. Reading age remains unverified. Its unique versioned filename is
md12xx.fancontrol-0.5.4-2026.11.26.04.plg. See the [candidate policy](../docs/MAINTENANCE_AND_MIGRATION.md#november-26-shelf-auto-candidate-054--2026112604).

## Versioned beta 0.5.4 / 2026.11.26.03

The [versioned installer](md12xx.fancontrol-0.5.4.plg) passed PHP 8.3 and 8.4 verification and a controlled R730 installation. It retains the chassis re-arm requirement across restarts. If shelf temperature or standby freshness is unverified, shelf Auto uses the configured fail-safe or maximum effective curve speed, whichever is higher. Commissioning ramps require verified inputs. See [maintenance and migration](../docs/MAINTENANCE_AND_MIGRATION.md) for rollback requirements. The rolling installer documented below is still 0.5.3.

# Current Beta release: 0.5.3

`md12xx.fancontrol.plg` is the current update installer. `md12xx.fancontrol-0.5.3.plg` is its immutable versioned copy. Installed Plugins shows build **2026.11.26.01** and Settings shows **0.5.3**. An isolated Auto sensor-read fault gives iDRAC control for at least 60 seconds. Two complete samples are required to resume. Repeat and hard faults still require explicit re-arm. See [IPMI safeguards](../docs/IPMI_PREVIEW.md) for limits.

```text
https://raw.githubusercontent.com/TheIlluminate92/unraid-md12xx-fan-control/main/releases/md12xx.fancontrol.plg
```

Use Unraid's normal plugin update flow, or paste the URL into **Plugins → Install Plugin**. Back up `/boot/config/plugins/md12xx.fancontrol` before updating. Updates keep configuration; uninstall deliberately deletes it.

Shelves successfully commissioned on test2 keep their identities and calibration. Legacy address-only configurations require one supervised Identify & test per shelf with control disabled. Review assigned disks and positive RPM before enabling Auto.

**Quiet startup recovery (35%)** is selectable in Settings and enabled by default. It is limited to one bounded attempt per boot for previously RPM-commissioned, cool shelves with verified baseline telemetry. It must prove unique response, the same saved identity/disks, and restoration before accepting recovery. Normal identity resolution remains read-only; a healthy startup does not ramp fans. Full gates and timeout behavior are documented in the main README.

### Historical 0.4.11 validation

Automated Linux/GitHub validation passed, and the affected top MD1200 shelf passed commissioning/restoration on test2. The conditional 35% recovery path has not been exercised on hardware. Version 0.4.11 is published as the final release at the owner's request with that validation boundary recorded. MD1220 and alternative hardware/topologies retain the project's existing Beta limitations.

### Installer history and recovery

Earlier versioned Beta and test installers remain immutable history, including test1's failed migration attempt and its correction in test2. Rollback requires the appropriate saved configuration backup; installing an old package does not migrate new settings backward.

Versioned artifacts must be saved as `md12xx.fancontrol.plg` before deliberate installation. Existing duplicates require a reviewed registration migration; do not uninstall the alias. See [migration plan](../docs/PLUGIN_UPDATES.md).

Read [maintenance and migration](../docs/MAINTENANCE_AND_MIGRATION.md) for the new safety checks, staged runtime rollback, hardware-validation limits and R730xd/R360 split.

This is a Beta release. Supervised R730xd fan response, explicit firmware handback, and idle Auto operation passed. Load, live 15% response, real process-death recovery and reboot tests remain pending; review [IPMI validation](../docs/IPMI_PREVIEW.md). New IPMI control starts disabled. Update using Unraid's plugin manager; this release does not change existing device curves automatically.
