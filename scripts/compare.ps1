<#
  Start the app(s) with Docker. Windows (PowerShell).

    scripts\compare.ps1 up         # both: enhanced on :8000, baseline (main) on :8001
    scripts\compare.ps1 enhanced   # just this branch on :8000
    scripts\compare.ps1 baseline   # just the original main branch on :8001
    scripts\compare.ps1 down       # stop everything and clean up

  The baseline runs the ORIGINAL app from a throwaway git worktree of main, so you
  can compare it against the enhanced branch side-by-side.
#>
param([string]$Command = "up")

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..")

$Worktree = ".worktrees/baseline"
$Baseline = @("compose", "-f", "docker-compose.baseline.yml", "-p", "folio-baseline")

function Start-Enhanced {
    docker compose up -d --build
    Write-Host "Enhanced (this branch): http://localhost:8000"
}

function Start-Baseline {
    git worktree prune
    if (-not (Test-Path (Join-Path $Worktree ".git"))) {
        git worktree add --force $Worktree origin/main
    }
    docker @Baseline up -d --build
    Write-Host "Baseline (main):        http://localhost:8001"
}

switch ($Command) {
    "up" {
        Start-Baseline
        Start-Enhanced
        Write-Host ""
        Write-Host "Compare:  http://localhost:8000  (enhanced)   vs   http://localhost:8001  (baseline)"
    }
    "enhanced" { Start-Enhanced }
    "baseline" { Start-Baseline }
    "down" {
        docker compose down
        docker @Baseline down
        git worktree remove --force $Worktree
        git worktree prune
        if (Test-Path ".worktrees") { Remove-Item ".worktrees" -ErrorAction SilentlyContinue }
        Write-Host "Stopped both apps and removed the baseline worktree."
    }
    default { Write-Host "Usage: compare.ps1 [up|enhanced|baseline|down]" }
}
