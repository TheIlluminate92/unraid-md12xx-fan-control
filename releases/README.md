# Beta release

## 0.4.11-test1 hardware test

The versioned `md12xx.fancontrol-0.4.11-test1.plg` installer is available on `fix/stale-ses-mapping-19` for supervised testing of PR #20. Verify it against the `SHA256SUMS` on that same branch. The default installer on main remains the existing Beta release.

Install the versioned URL through Unraid **Plugins → Install Plugin**:

```text
https://raw.githubusercontent.com/TheIlluminate92/unraid-md12xx-fan-control/fix/stale-ses-mapping-19/releases/md12xx.fancontrol-0.4.11-test1.plg
```

Before installing, back up `/boot/config/plugins/md12xx.fancontrol` and disable fan control in Settings. The installer keeps saved settings. Address-only configurations have no persistent enclosure identity and will block new commands until each shelf completes one supervised verified re-pairing. Identify & test uses the existing 20% → 50% → 20% sequence. Confirm mapped disks and positive RPM for both shelves before enabling Auto. Do not uninstall to update; uninstall removes saved configuration.

For the first hardware test, confirm the API reports version `0.4.11-test1`, both saved shelves have nonempty `sesIdentity`, current SES devices are enclosures, RPM is positive, and normal RPM verification succeeds. Restart the controller through the usual Settings controls to exercise startup verification. A later supervised reboot/address-change test should confirm the same identities and correct disk mappings remain paired. Startup verification adds only read-only identity queries and no extra speed ramp. Redundant SES paths remain unsupported/ambiguous in this beta.

Rollback testing requires the saved configuration backup; installing an old package does not migrate new settings backward automatically. The test installer retains the stable main update URL so subsequent published Beta versions can supersede it.

`md12xx.fancontrol.plg` is the current standalone Beta installer. Verify it against `SHA256SUMS` before testing.

`md12xx.fancontrol-0.4.10.plg` is the immutable copy of this release for audit or rollback testing. Installing an older package does not automatically downgrade a saved configuration; take a backup before testing rollback behavior.

The controller installs disabled. Expand the setup directions, configure each shelf, and use Identify & test. The test prefers independent RPM proof; when SES RPM stays static, it can pair an EMM to an SES enclosure by an exact SAS identity match. Manual physical pairing remains available. Check the associated disks, final 20% restoration, and any reduced-verification warning before enabling control; disable every competing fan writer.

MD1200 is validated on two real shelves, including a forced controller-death and supervised-recovery test. The tested serial path uses a Dell-compatible six-pin service/password-reset cable and an FTDI USB-to-serial adapter. MD1220 and additional HBA, firmware, cable, topology, and serial-adapter reports are requested through the repository's hardware-report issue form. MD1400, MD1420, and other shelves remain unsupported.

