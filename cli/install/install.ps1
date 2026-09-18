# Bootstrap installer for @linotech/cli on Windows PowerShell.
#
# Distributed at:
#   https://raw.githubusercontent.com/grugcrood82/linopay-providers/main/cli/install/install.ps1
#
# One-liner for a developer to paste into PowerShell:
#   iwr -useb https://raw.githubusercontent.com/grugcrood82/linopay-providers/main/cli/install/install.ps1 | iex
#
# Mirrors install.sh (the SOW says they should be separate so neither
# shell has to interpret the other's dialect).

$ErrorActionPreference = 'Stop'

if ($IsWindows -eq $false) {
    Write-Error 'linopay: install.ps1 is for Windows only. On macOS / Linux, use install.sh.'
    exit 1
}

# Locate node in a few standard places; fall back to throwing a
# specific error rather than a stack-trace-style "command not found".
$nodeCmd = $null
foreach ($candidate in @('node', 'node.exe')) {
    $path = (Get-Command $candidate -ErrorAction SilentlyContinue)
    if ($path) {
        $nodeCmd = $candidate
        break
    }
}
if (-not $nodeCmd) {
    @'
linopay: a Node.js runtime was not found on $PATH.

Install Node 20 or newer before running this script:

  winget:   winget install -e --id OpenJS.NodeJS.LTS
  Chocolatey: choco install nodejs-lts
  Manual:   https://nodejs.org/en/download

Then re-run this installer.
'@ | Write-Error
    exit 1
}

# Verify the major version is high enough.
$nodeVersion = & $nodeCmd -p 'process.versions.node.split(".")[0]'
if ([int]$nodeVersion -lt 20) {
    Write-Error "linopay: installed Node is $(& $nodeCmd --version). LinoPay CLI requires Node 20+."
    exit 1
}

# npm is shipped with Node, but a custom toolchain might not have
# it on PATH. Check explicitly so we surface a specific error instead
# of "spawn ENOENT".
$npmCmd = $null
foreach ($candidate in @('npm', 'npm.cmd')) {
    $path = (Get-Command $candidate -ErrorAction SilentlyContinue)
    if ($path) {
        $npmCmd = $candidate
        break
    }
}
if (-not $npmCmd) {
    Write-Error 'linopay: `npm` was not found next to `node`. Ensure npm 10+ is on $PATH and re-run.'
    exit 1
}

Write-Host 'Installing @linotech/cli globally ...'
& $npmCmd install -g @linotech/cli
if ($LASTEXITCODE -ne 0) {
    Write-Error 'linopay: npm install failed. See the output above for the underlying reason.'
    exit $LASTEXITCODE
}

# Confirm `linopay` is actually on $PATH now. Many Windows setups
# have npm's global bin dir not on PATH, especially when nvm-windows
# or custom prefixes are involved.
$linopayCmd = Get-Command 'linopay' -ErrorAction SilentlyContinue
if (-not $linopayCmd) {
    @'
linopay: npm reported success but `linopay` is not on $PATH.

Add npm's global bin directory to $PATH for the current user:

  $env:PATH = "$(npm config get prefix)\bin;$env:PATH"

Persist this in your PowerShell profile (`$PROFILE`) so new shells
inherit it. Then run `linopay --help`.
'@ | Write-Error
    exit 1
}

Write-Host 'Done. Run `linopay --help` to get started.'
