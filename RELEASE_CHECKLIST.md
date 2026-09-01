# Beta release checklist

## Automated

- [x] Plugin manifest builds and parses.
- [x] PHP, shell, and JavaScript syntax checks are in CI.
- [x] Generic enclosure-link and SAS-expander mapping fixtures pass in CI.
- [x] Synthetic 24-bay MD1220 mapping excludes an unrelated expander.
- [x] Runtime local-only/security policy scan is part of CI.
- [x] Community Apps profile, wrapper, icon, license, and support links are present.

## Real Unraid validation

- [x] Two MD1200 shelves independently passed 20% → 50% → 20% commissioning and automatic disk mapping.
- [ ] Clean uninstall proves configuration, commissioning results, runtime state, and the saved `.plg` are removed.
- [ ] Clean reinstall starts disabled with no configured shelves.
- [ ] Fresh discovery and commissioning pass on both MD1200 shelves.
- [ ] Manual mode is spot-checked at a safe requested speed and returned to Auto/20%.
- [ ] Controller survives an Unraid reboot and array stop/start without racing another fan writer.
- [ ] 24–48 hour Auto-mode soak test completes without stale telemetry or unexplained writes.

## External Beta

- [ ] At least one MD1200 report from a different HBA/firmware/Unraid combination.
- [ ] At least one MD1220 hardware report.
- [ ] Public Unraid forum support thread exists and replaces the temporary GitHub-only support link where appropriate.
- [ ] Community Apps Validate and Scan both pass.
- [ ] Listing screenshots are captured from the final Settings page.
