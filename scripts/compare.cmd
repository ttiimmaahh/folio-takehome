@echo off
REM Windows convenience wrapper. Runs compare.ps1 with the PowerShell execution
REM policy bypassed for this one call, so you don't have to fight it or change a
REM system setting. Works from cmd.exe or a double-click.
REM
REM   scripts\compare.cmd up         both: enhanced :8000 + original main :8001
REM   scripts\compare.cmd enhanced   just this branch on :8000
REM   scripts\compare.cmd baseline   just the original main branch on :8001
REM   scripts\compare.cmd down       stop everything and clean up
REM
REM (%~dp0 is this file's folder, so it finds compare.ps1 next to it; %* passes args.)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0compare.ps1" %*
