#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit): after touching app code, run the test suite so
# regressions surface immediately. Quiet on success; exit 2 (with output) on failure.
# Skips edits outside the app's code paths (e.g. docs) and no-ops if the container
# isn't running, so it never blocks documentation work.
input=$(cat)
path=$(printf '%s' "$input" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("tool_input",{}).get("file_path",""))' 2>/dev/null)

case "$path" in
  */lib/*|*/public/*|*/migrations/*|*/tests/*|*/seed.php|*/migrate.php) ;;
  *) exit 0 ;;
esac

if ! docker compose ps --status running 2>/dev/null | grep -q app; then
  exit 0
fi

if ! out=$(docker compose exec -T app php tests/test.php 2>&1); then
  echo "Test suite failed after editing ${path}:" >&2
  echo "$out" >&2
  exit 2
fi
exit 0
