#!/usr/bin/env bash
#
# Start the app(s) with Docker. macOS / Linux.
#
#   scripts/compare.sh up         # both: enhanced on :8000, baseline (main) on :8001
#   scripts/compare.sh enhanced   # just this branch on :8000
#   scripts/compare.sh baseline   # just the original main branch on :8001
#   scripts/compare.sh down       # stop everything and clean up
#
# The baseline runs the ORIGINAL app from a throwaway git worktree of main, so you
# can compare it against the enhanced branch side-by-side.

set -euo pipefail
cd "$(dirname "$0")/.."

WORKTREE=".worktrees/baseline"
BASELINE="docker compose -f docker-compose.baseline.yml -p folio-baseline"

start_enhanced() {
    docker compose up -d --build
    echo "Enhanced (this branch): http://localhost:8000"
}

start_baseline() {
    git worktree prune
    if [ ! -e "$WORKTREE/.git" ]; then
        git worktree add --force "$WORKTREE" origin/main
    fi
    $BASELINE up -d --build
    echo "Baseline (main):        http://localhost:8001"
}

case "${1:-up}" in
    up)
        start_baseline
        start_enhanced
        echo ""
        echo "Compare:  http://localhost:8000  (enhanced)   vs   http://localhost:8001  (baseline)"
        ;;
    enhanced) start_enhanced ;;
    baseline) start_baseline ;;
    down)
        docker compose down 2>/dev/null || true
        $BASELINE down 2>/dev/null || true
        git worktree remove --force "$WORKTREE" 2>/dev/null || true
        git worktree prune 2>/dev/null || true
        rmdir .worktrees 2>/dev/null || true
        echo "Stopped both apps and removed the baseline worktree."
        ;;
    *)
        echo "Usage: $0 [up|enhanced|baseline|down]" >&2
        exit 1
        ;;
esac
