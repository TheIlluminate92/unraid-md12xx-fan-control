# Beta release checklist

## Automated

- [x] Plugin manifest builds and parses.
- [x] PHP, shell, and JavaScript syntax checks are in CI.
- [x] Generic enclosure-link and SAS-expander mapping fixtures pass in CI.
- [x] Synthetic 24-bay MD1220 mapping excludes an unrelated expander.
- [x] Runtime local-only/security policy scan is part of CI.
- [x] Community Apps profile, wrapper, icon, license, and support links are present.
- [x] GitHub private vulnerability reporting is enabled.
- [x] Every published installer is immutable or current, checksum-listed, and verified by CI.

## Real Unraid validation

- [x] Two MD1200 shelves independently passed 20% → 50% → 20% commissioning and automatic disk mapping.
- [x] Clean uninstall proves configuration, commissioning results, runtime state, and the saved `.plg` are removed.
- [x] Clean reinstall starts disabled with no configured shelves.
- [x] Fresh discovery and commissioning pass on both MD1200 shelves.
- [x] Standalone Auto control owns both shelves with normal SES telemetry and no competing writer.
- [x] Manual 50% mode is verified simultaneously on both shelves and both return to Auto/20% with independent SES telemetry.
- [x] Forced controller-process death produces a local failure alert, restarts under supervision, restores both 20% targets, verifies both shelves through independent SES telemetry, and produces a recovery alert after 60 healthy seconds.
- [ ] Controller survives an Unraid reboot and array stop/start without racing another fan writer.
- [x] Overnight Auto-mode soak test completes without stale telemetry, unexplained writes, or reported control issues.

## External Beta

- [ ] At least one MD1200 report from a different HBA/firmware/Unraid combination.
- [ ] At least one MD1220 hardware report.
- [x] Public Unraid forum support thread exists and replaces the temporary GitHub-only support link where appropriate.
- [x] Community Apps Validate and Scan both pass with one reachable plugin manifest, no hard errors, and no template warnings.
- [x] Listing screenshots are captured from the final Settings page.
