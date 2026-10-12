# Current release: 0.4.13

`md12xx.fancontrol.plg` is the normal update installer; `md12xx.fancontrol-0.4.13.plg` is its immutable versioned copy. Both are listed in `SHA256SUMS`. Installed Plugins shows build **2026.10.12.01**; Settings and the API show application **0.4.13**. The date-based build fixes Unraid update ordering from the test installers.

```text
https://raw.githubusercontent.com/TheIlluminate92/unraid-md12xx-fan-control/main/releases/md12xx.fancontrol.plg
```

Use Unraid's normal plugin update flow, or paste the URL into **Plugins → Install Plugin**. Back up `/boot/config/plugins/md12xx.fancontrol` before updating. Updates keep configuration; uninstall deliberately deletes it.

Shelves successfully commissioned on test2 keep their identities and calibration. Legacy address-only configurations require one supervised Identify & test per shelf with control disabled. Review assigned disks and positive RPM before enabling Auto.

**Quiet startup recovery (35%)** is selectable in Settings and enabled by default. It is limited to one bounded attempt per boot for previously RPM-commissioned, cool shelves with verified baseline telemetry. It must prove unique response, the same saved identity/disks, and restoration before accepting recovery. Normal identity resolution remains read-only; a healthy startup does not ramp fans. Full gates and timeout behavior are documented in the main README.

Automated Linux/GitHub validation passed, and the affected top MD1200 shelf passed commissioning/restoration on test2. The conditional 35% recovery path has not been exercised on hardware. Version 0.4.11 is published as the final release at the owner's request with that validation boundary recorded. MD1220 and alternative hardware/topologies retain the project's existing Beta limitations.

Earlier versioned Beta and test installers remain immutable history, including test1's failed migration attempt and its correction in test2. Rollback requires the appropriate saved configuration backup; installing an old package does not migrate new settings backward.

Versioned artifacts must be saved as `md12xx.fancontrol.plg` before deliberate installation. Existing duplicates require a reviewed registration migration; do not uninstall the alias. See [migration plan](../docs/PLUGIN_UPDATES.md).
