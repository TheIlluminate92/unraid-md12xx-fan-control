#!/bin/sh
# Invoked only through setsid --fork --wait: $$ is the private command process-group ID.
# No background task is supported by the bundled local IPMI command contract.
set -u
[ -x /bin/kill ] && [ -x /usr/bin/timeout ] || exit 126
# If the PHP reader dies, EPIPE must not stop the EXIT group cleanup.
trap '' PIPE
cleanup() {
    result=$?
    trap - EXIT TERM INT HUP
    # FD3 is private to this wrapper; the IPMI tool never inherits it.
    printf 'cooling-ipmi-exit:%s\n' "$result" >&3 || :
    exec 3>&-
    # This intentionally kills the wrapper too. PHP accepts the report only if
    # the forked monitor reports signal9; a failed kill exits125 and fails closed.
    /bin/kill -KILL -- "-$$" 2>/dev/null
    exit 125
}
trap cleanup EXIT
trap 'exit 143' TERM HUP
trap 'exit 130' INT
# Foreground mode keeps timeout and its complete inherited child tree inside
# this wrapper-owned group. Cleanup also runs when the direct parent exits early.
/usr/bin/timeout --foreground --kill-after=0.25s "$@" 3>&-
exit "$?"
