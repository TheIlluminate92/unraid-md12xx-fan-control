#!/bin/bash
set -euo pipefail

# Read-only EMM status capture. This diagnostic does not change fan speed.
STATE_DIR=/var/run/md12xx.fancontrol
RESULT_ROOT=/boot/config/plugins/md12xx.fancontrol/diagnostics
CONFIG_FILE=/boot/config/plugins/md12xx.fancontrol/config.json
PROMPT_RE='(BlueDress|RedDress)\.[0-9.]+[[:space:]]*>$'

query() {
  local command="$1" output="$2" char response='' deadline=$((SECONDS + 12))
  # Drain a bounded amount of any leftover console text before a new request.
  for ((i=0; i<4096; i++)); do
    IFS= read -r -N 1 -t 0.1 -u 8 char || break
  done
  printf '%s\r' "$command" >&8
  while (( SECONDS < deadline )); do
    if IFS= read -r -N 1 -t 1 -u 8 char; then
      response+="$char"
      if [[ $response =~ $PROMPT_RE ]]; then
        printf '%s' "$response" > "$output"
        return 0
      fi
    fi
  done
  printf '%s' "$response" > "$output"
  echo "No complete EMM prompt after $command; stopped this adapter." >&2
  return 1
}

capture_port() (
  local port="$1" index="$2" dir="$3/adapter-$2" hash
  [[ "$port" == /dev/serial/by-id/* && -e "$port" ]] || { echo "Invalid or missing by-id adapter: $port" >&2; exit 1; }
  mkdir -p "$dir"
  printf '%s\n' "$port" > "$dir/adapter-path.txt"
  hash="$(printf '%s' "$port" | sha1sum | cut -c1-12)"
  exec 9>"$STATE_DIR/serial-${hash}.lock"
  flock -w 15 9 || { echo "Adapter locked: $port" >&2; exit 1; }
  if fuser "$(readlink -f "$port")" >/dev/null 2>&1; then
    echo "Adapter open in another process: $port" >&2; exit 1
  fi
  stty -F "$port" 38400 raw -echo -crtscts -hupcl cs8 -cstopb -parenb min 1 time 0
  exec 8<>"$port"
  query _who "$dir/who.txt"
  grep -Eqi 'I.?m[[:space:]]+primary[[:space:]]+and[[:space:]]+active' "$dir/who.txt" || {
    echo "No primary/active EMM response: $port" >&2; exit 1;
  }
  query 'ps_status l' "$dir/ps-left.txt"
  query 'ps_status r' "$dir/ps-right.txt"
  query fanlog "$dir/fanlog.txt"
  exec 8>&-
)

main() {
  (( $# > 0 )) || { echo "Usage: $0 /dev/serial/by-id/ADAPTER [ADAPTER...]" >&2; return 1; }
  (( EUID == 0 )) || { echo 'Run as root on Unraid.' >&2; return 1; }
  for cmd in jq flock fuser stty sha1sum tar; do command -v "$cmd" >/dev/null || return 1; done
  jq -e '.enabled == false' "$CONFIG_FILE" >/dev/null || {
    echo 'Disable the MD12xx controller first.' >&2; return 1;
  }
  mkdir -p "$STATE_DIR" "$RESULT_ROOT"
  exec 7>"$STATE_DIR/emm-interrogation.lock"
  flock -n 7 || { echo 'Another EMM diagnostic is running.' >&2; return 1; }
  [[ ! -e "$STATE_DIR/commissioning.active" ]] || { echo 'Commissioning is active.' >&2; return 1; }

  local dir="$RESULT_ROOT/fan-status-$(date +%Y%m%d-%H%M%S)" archive index=0 port
  mkdir -p "$dir"
  for port in "$@"; do
    index=$((index + 1))
    capture_port "$port" "$index" "$dir" || return 1
  done
  archive="$dir.tar.gz"
  tar -czf "$archive" -C "$RESULT_ROOT" "$(basename "$dir")"
  echo "Review for identifiers before public upload: $archive"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
