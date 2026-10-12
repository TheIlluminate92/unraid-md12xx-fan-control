# Beta release

## Current hardware test: 0.4.11-test3

Install through Unraid **Plugins → Install Plugin**:

```text
https://raw.githubusercontent.com/TheIlluminate92/unraid-md12xx-fan-control/fix/stale-ses-mapping-19/releases/md12xx.fancontrol-0.4.11-test3.plg
```

Back up `/boot/config/plugins/md12xx.fancontrol` and disable control before installing. Keep configuration; do not uninstall to update. Confirm version `0.4.11-test3`. Shelves successfully commissioned on test2 retain their identities and calibration. Address-only shelves still require one supervised Identify & test per shelf, followed by review of disks/RPM before enabling Auto.

**Quiet startup recovery (35%)** is a visible setting enabled by default in this test build. It only attempts recovery for already commissioned, cool shelves at their proven 20% baseline, once per boot. It requires a unique response, the same saved identity and disks, and a verified restoration; higher cooling demand aborts it. See the main README for the full gates and timeout. A normal restart should resolve saved identities without any ramp.

Versioned installers are immutable historical test records with hashes in this branch's `SHA256SUMS`. Test1's stale commissioning filter was corrected in test2; the affected top MD1200 shelf passed test2 and restored to 20% after one retry. The new quiet startup path in test3 still needs supervised hardware validation. Main's default Beta installer is unchanged while PR #20 remains under test. The test installer retains the stable main update URL for later releases.

Rollback testing requires the configuration backup; old packages do not migrate saved identities backward automatically.

`md12xx.fancontrol.plg` is the current standalone Beta installer. Verify it against `SHA256SUMS` before testing.

`md12xx.fancontrol-0.4.10.plg` is the immutable copy of this release for audit or rollback testing. Installing an older package does not automatically downgrade a saved configuration; take a backup before testing rollback behavior.

The controller installs disabled. Expand the setup directions, configure each shelf, and use Identify & test. The test prefers independent RPM proof; when SES RPM stays static, it can pair an EMM to an SES enclosure by an exact SAS identity match. Manual physical pairing remains available. Check the associated disks, final 20% restoration, and any reduced-verification warning before enabling control; disable every competing fan writer.

MD1200 is validated on two real shelves, including a forced controller-death and supervised-recovery test. The tested serial path uses a Dell-compatible six-pin service/password-reset cable and an FTDI USB-to-serial adapter. MD1220 and additional HBA, firmware, cable, topology, and serial-adapter reports are requested through the repository's hardware-report issue form. MD1400, MD1420, and other shelves remain unsupported.

