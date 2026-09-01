# Security and privacy

Report security-sensitive issues privately to the repository owner before opening a public issue.

## Local-only contract

The installed runtime contains no outbound network client, cloud service, automatic update checker, analytics, or telemetry uploader. Unraid's plugin manager downloads the public `.plg` during install/update; after installation, the plugin operates locally. Exported diagnostics remain on the server until the user explicitly moves or uploads them.

The runtime reads only the hardware and Unraid state required for fan control:

- `/dev/serial/by-id/*` for explicitly selected shelf consoles.
- `/dev/sg*` and `/sys` for SES telemetry and topology.
- `/var/local/emhttp/disks.ini` for current Unraid disk names, temperatures, and spin state.
- Local process roles and configured Docker container names solely to block competing fan writers.

Persistent writes are restricted to `/boot/config/plugins/md12xx.fancontrol`. Volatile state and locks are restricted to `/var/run/md12xx.fancontrol`. Installed files live under `/usr/local/emhttp/plugins/md12xx.fancontrol`. The plugin does not read or write array/share contents, access `/mnt/user`, or use the Docker socket.

## Control boundaries

Passive discovery sends nothing. Optional active discovery sends only the read-only `_who` query, only while fan control is disabled, and never sends `set_speed`. Speed commands require an explicitly selected persistent serial path and a shelf that passed RPM-proven commissioning. Serial locks, competing-controller checks, and independent SES telemetry prevent two writers from being treated as safe.

State-changing WebGUI requests use Unraid's authenticated local WebGUI session and CSRF token. Configuration input is range-checked and hardware paths are restricted to persistent serial identifiers, `/dev/sgN`, and stable SCSI addresses.

## Diagnostics

The local diagnostic exporter removes hostnames, serial adapter identifiers, disk names, block-device names, response transcripts, and user-configured shelf names. Review an archive before sharing it. No redaction system should be treated as a substitute for user review.

## Automated checks

CI builds and parses the plugin XML, checks PHP/shell/JavaScript syntax, rejects known server-specific identifiers, verifies generic MD1200 and synthetic 24-bay MD1220 topology fixtures, confirms discovery contains no speed command, and rejects outbound-network primitives or broad filesystem paths in runtime source.
