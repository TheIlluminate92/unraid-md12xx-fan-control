# MD12xx Fan Control for Unraid

Standalone, host-native fan control for Dell PowerVault MD1200 and MD1220 disk shelves.

> **Beta:** MD1200 control and SES telemetry have been verified on real hardware. MD1220 uses the same Dell MD12xx enclosure family and is included for testing, but its serial fan response still requires independent hardware confirmation.

## Features

- Supports one or more MD1200/MD1220 shelves.
- Passively inventories candidate SES devices, persistent serial adapter paths, and Unraid disks.
- Automatically maps the shelf's current Unraid disk names through standard enclosure links or the verified SES device's exact SAS expander.
- Optionally verifies likely FTDI serial consoles with a read-only `_who` query while all fan controllers are stopped.
- Auto mode controls each shelf from its assigned disks independently.
- Manual choices: 20%, 30%, 40%, and 50%.
- Reads independent fan RPM telemetry through `sg_ses`.
- Uses stable SCSI addresses and `/dev/serial/by-id` paths.
- Uses carriage-return-only BlueDress `set_speed` command framing confirmed on MD1200 hardware.
- Blocks writes while the legacy `MD1200-Fan-Controller` Docker is running.
- Starts disabled and requires explicit configuration and commissioning.
- Retains settings during plugin updates and uninstall/reinstall cycles.

## Default Auto curve

| Hottest assigned disk | Fan target |
| --- | ---: |
| All assigned disks spun down | 20% |
| Below 35°C | 20% |
| 35–44.9°C | 25% |
| 45–49.9°C | 30% |
| 50°C or hotter | 50% |
| Active disk without valid temperature | 50% fail-safe |

The controller polls every 30 seconds, uses 1°C downshift hysteresis, and reasserts the target every 15 minutes.

## Safety model

The normal setup asks for one verified persistent serial adapter. The guarded identification test first repeats the read-only MD12xx console check, then measures every candidate SES enclosure at 20% and 50%. It accepts the pairing only when exactly one enclosure shows a clear RPM response, always attempts to restore 20%, and automatically maps that enclosure's Linux block devices to the current Unraid disk names. Mapping uses standard enclosure-slot links when available and otherwise requires the disks to share the verified SES device's exact SAS expander. If neither relationship is available, the Settings page retains an explicit Manual mapping fallback.

The plugin does not treat a matching model name, USB vendor, prompt string, or drive count as proof of a serial-to-SES pairing. Ambiguous RPM results and empty automatic disk assignments are not commissioned.

Active connection discovery is off by default. When enabled, it considers a console verified only when the structured MD12xx `_who` response and the primary/active EMM role are both present. `BlueDress` is recorded when seen but is not required, because prompt wording may differ by firmware. Discovery never sends `set_speed`, is blocked while this plugin controls fans, and is also blocked by WAZ Dashboard or the legacy Docker controller.

Do not run this plugin alongside another process that writes to the same enclosure serial adapter.
The controller, discovery worker, and commissioning test also refuse to open a serial device that the operating system reports as already in use.

## Development install

Build `dist/md12xx.fancontrol.plg`, copy it to the Unraid boot flash, then install it through **Plugins → Install Plugin** or the terminal. Configure it under **Settings → Utilities → MD12xx Fan Control**.

## Status integration

Other local plugins, including WAZ Dashboard, can read:

```text
/plugins/md12xx.fancontrol/include/api.php
```

The default GET response is intentionally read-only JSON containing controller and shelf state.

An optional compact WAZ Dashboard module is planned after the discovery result has been validated on real MD1200 hardware and, separately, on an MD1220.

## License and hardware disclaimer

MIT licensed. Dell does not document the BlueDress fan command used by this project. Use at your own risk, keep current backups, and validate every shelf before enabling automatic control.
