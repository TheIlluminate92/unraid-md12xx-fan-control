# MD12xx Fan Control for Unraid

Standalone, host-native fan control for Dell PowerVault MD1200 and MD1220 disk shelves.

> **Beta:** MD1200 control and SES telemetry have been verified on real hardware. MD1220 uses the same Dell MD12xx enclosure family and is included for testing, but its serial fan response still requires independent hardware confirmation.

## Features

- Supports one or more MD1200/MD1220 shelves.
- Discovers candidate SES devices, persistent serial adapter paths, and Unraid disks.
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

The plugin never guesses which serial adapter controls which enclosure. Discovery suggests candidates, but the operator must explicitly pair each shelf and run the commissioning test. The test verifies a measurable RPM response at 20% and 50% and always attempts to restore 20% before exiting.

Do not run this plugin alongside another process that writes to the same enclosure serial adapter.

## Development install

Build `dist/md12xx.fancontrol.plg`, copy it to the Unraid boot flash, then install it through **Plugins → Install Plugin** or the terminal. Configure it under **Settings → Utilities → MD12xx Fan Control**.

## Status integration

Other local plugins, including WAZ Dashboard, can read:

```text
/plugins/md12xx.fancontrol/include/api.php
```

The default GET response is intentionally read-only JSON containing controller and shelf state.

## License and hardware disclaimer

MIT licensed. Dell does not document the BlueDress fan command used by this project. Use at your own risk, keep current backups, and validate every shelf before enabling automatic control.

