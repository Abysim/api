#!/usr/bin/env bash
# Pipes one of this skill's PHP scripts to `php` on bigcats from ~/api; nothing is deployed.
# Usage: run-remote.sh <dump-pending|clear-old|apply|followup>.php [--option=value ...]
# Exits 2 when it refuses to run, 3 when the script fails `php -l` on bigcats, else with the script's own code.
set -euo pipefail

dir=$(cd "$(dirname "$0")" && pwd)
case ${1:-} in
    dump-pending.php | clear-old.php | apply.php | followup.php) script=$dir/$1 ;;
    *) echo "run-remote: not a news-filter script: ${1:-}" >&2; exit 2 ;;
esac
shift
for arg in "$@"; do
    [[ $arg =~ ^[A-Za-z0-9+/=,._:-]+$ ]] || { echo "run-remote: unsafe argument: $arg" >&2; exit 2; }
done

ssh bigcats 'cd ~/api && f=$(mktemp --suffix=.php) && cat > "$f" && { lint=$(php -l "$f" 2>&1) || { echo "$lint" >&2; rm -f "$f"; exit 3; }; } && php "$f" '"$*"'; rc=$?; rm -f "$f"; exit $rc' < "$script"
