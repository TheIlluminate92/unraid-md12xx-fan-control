# Security and privacy

Security-sensitive issues must not be posted publicly. GitHub private vulnerability reporting is enabled for this repository. Use [Report a vulnerability](https://github.com/TheIlluminate92/unraid-md12xx-fan-control/security/advisories/new); do not attach sensitive diagnostics to a public issue.

## Local-only contract

The installed runtime contains no outbound network client, cloud service, automatic update checker, analytics, or telemetry uploader. Unraid's plugin manager downloads the public `.plg` during install/update; after installation, the controller operates locally. The Settings page contains one ordinary, user-activated link to the public GitHub issue form, but the plugin does not post data to it. Exported diagnostics remain on the server until the user explicitly downloads and uploads them.

The runtime reads only the hardware and Unraid state required for fan control:

- `/dev/serial/by-id/*` for explicitly selected shelf consoles.
- `/dev/sg*` and `/sys` for SES telemetry and topology.
- `/var/local/emhttp/disks.ini` for current Unraid disk names, temperatures, and spin state.
- Local process roles and configured Docker container names solely to block competing fan writers.

HBA interaction is read-only: the plugin reads Linux SAS topology and requests the SES enclosure-status page through `sg_ses`. It does not configure or flash the HBA, alter RAID settings, or send fan-speed commands over SAS. Fan writes use only the explicitly selected USB serial console. Disk temperature and spin state are consumed from Unraid's existing `disks.ini` state; the plugin does not directly issue SMART queries to sleeping disks.

Persistent writes are restricted to `/boot/config/plugins/md12xx.fancontrol`. Volatile state and locks are restricted to `/var/run/md12xx.fancontrol`. Installed files live under `/usr/local/emhttp/plugins/md12xx.fancontrol`. The plugin does not read or write array/share contents, access `/mnt/user`, or use the Docker socket.

## Control boundaries

Passive discovery sends nothing. Optional active discovery sends only the read-only `_who` query, only while fan control is disabled, and never sends `set_speed`. Speed commands require an explicitly selected persistent serial path and a shelf that passed RPM-proven commissioning. Serial locks, competing-controller checks, and independent SES telemetry prevent two writers from being treated as safe. If the local ownership-checking utility is unavailable, serial access fails closed rather than assuming the adapter is free.

State-changing WebGUI requests use Unraid's authenticated local WebGUI session and CSRF token. Configuration input is range-checked and hardware paths are restricted to persistent serial identifiers, `/dev/sgN`, and stable SCSI addresses.

## Diagnostics

The local diagnostic exporter removes hostnames, serial adapter identifiers, disk names, block-device names, response transcripts, and user-configured shelf names. Review an archive before sharing it. No redaction system should be treated as a substitute for user review. Attachments submitted to a public GitHub issue are public; the plugin never performs that upload on the user's behalf.

## Automated checks

CI builds and parses the plugin XML, checks PHP/shell/JavaScript syntax, rejects known server-specific identifiers, verifies generic MD1200 and synthetic 24-bay MD1220 topology fixtures, confirms discovery contains no speed command, and rejects outbound-network primitives or broad filesystem paths in runtime source.
