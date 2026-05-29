#!/usr/bin/env bash
# PreToolUse(Edit|Write|MultiEdit): enforce the project rule that schema.sql is the
# frozen v0 baseline. Schema changes must be migrations. Exit 2 blocks the edit.
input=$(cat)
path=$(printf '%s' "$input" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("tool_input",{}).get("file_path",""))' 2>/dev/null)

case "$path" in
  */schema.sql|schema.sql)
    echo "schema.sql is the frozen v0 baseline. Add a migration in migrations/ (try /new-migration) instead of editing it." >&2
    exit 2
    ;;
esac
exit 0
