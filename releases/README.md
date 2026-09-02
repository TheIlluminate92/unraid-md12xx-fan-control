# Beta release

`md12xx.fancontrol.plg` is the current standalone Beta installer. Verify it against `SHA256SUMS` before testing.

`md12xx.fancontrol-0.4.5.plg` is the immutable copy of this release for audit or rollback testing. Installing an older package does not automatically downgrade a saved configuration; take a backup before testing rollback behavior.

The controller installs disabled. Expand the setup directions, configure each shelf, and use the app's Identify & test button. Verify the associated disks and final 20% restoration, then disable every competing fan writer before enabling it.

MD1200 is validated on two real shelves, including a forced controller-death and supervised-recovery test. The tested serial path uses a Dell-compatible six-pin service/password-reset cable and an FTDI USB-to-serial adapter. MD1220 and additional HBA, firmware, cable, topology, and serial-adapter reports are requested through the repository's hardware-report issue form. MD1400, MD1420, and other shelves remain unsupported.

