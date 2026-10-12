# Select RPM-test candidates without treating stale host addresses as identity.
# First input: exact serial ELI-to-SES matches. Second input: discovered SES candidates.
BEGIN { FS = OFS = "\t" }
FILENAME == ARGV[1] {
    if (NF >= 2) { exact[$1 SUBSEP $2] = 1; exactCount++ }
    next
}
NF >= 2 {
    addresses[++count] = $1
    devices[count] = $2
}
END {
    # Multiple paths/matches cannot prove one physical pairing.
    if (exactCount > 1) exit 2
    for (i = 1; i <= count; i++) {
        if (exactCount == 1) {
            if (exact[addresses[i] SUBSEP devices[i]]) print addresses[i], devices[i]
        } else if (assignment == "automatic" || (address == "" && device == "")) {
            # A guarded unique RPM response must prove the automatic pairing.
            print addresses[i], devices[i]
        } else if ((address != "" && addresses[i] == address) || (address == "" && devices[i] == device)) {
            print addresses[i], devices[i]
        }
    }
}
