# Beta release

`md12xx.fancontrol.plg` is the current standalone Beta installer. Verify it against `SHA256SUMS` before testing.

`md12xx.fancontrol-0.3.0.plg` is the immutable copy of this release for audit or rollback testing. Installing an older package does not automatically downgrade a saved configuration; take a backup before testing rollback behavior.

The controller installs disabled. Expand the setup directions, configure and commission each shelf, verify the associated disks and final 20% restoration, then disable every competing fan writer before enabling it.

MD1200 is validated on two real shelves. MD1220 and additional HBA, firmware, and serial-adapter reports are requested through the repository's hardware-report issue form.
