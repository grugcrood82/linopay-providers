#!/usr/bin/env bash
# Bootstrap installer for @linotech/cli.
#
# Distributed at:
#   https://raw.githubusercontent.com/grugcrood82/linopay-providers/main/cli/install/install.sh
#
# Installs the npm package globally if a node/npm toolchain is on
# $PATH; gives a specific, non-stacktrace error if it isn't. macOS
# Gatekeeper notarisation requirements don't apply to a script that
# delegates to an interpreter (Node), which is the reason we ship an
# installer rather than a notarised .pkg -- per the SOW.

set -euo pipefail
IFS=$'\n\t'

# We intentionally avoid a separate Windows script being run via WSL
# (the SOW provides a dedicated PowerShell one-liner for that).
case "$(uname -s)" in
    Linux|Darwin) : ;;
    *) printf 'linopay: install.sh is for Linux and macOS only. On Windows, run the PowerShell one-liner from cli/install/install.ps1 instead.\n' >&2; exit 1 ;;
esac

if ! command -v node >/dev/null 2>&1; then
    cat >&2 <<'EOF'
linopay: a Node.js runtime was not found on $PATH.

Install Node 20 or newer before running this script:

  macOS (Homebrew):  brew install node
  macOS (nvm):       https://github.com/nvm-sh/nvm#install--update-script
  Debian / Ubuntu:   https://github.com/nodesource/distributions
  Fedora / RHEL:     dnf install nodejs
  Alpine:            apk add nodejs npm

Then re-run this installer.
EOF
    exit 1
fi

NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]')"
if [ "${NODE_MAJOR:-0}" -lt 20 ]; then
    cat >&2 <<EOF
linopay: installed Node is $(node --version). LinoPay CLI requires Node 20+.
EOF
    exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
    cat >&2 <<'EOF'
linopay: `npm` was not found next to `node`. This is unusual — most
distributions install both together. If you installed Node via a
custom toolchain, ensure npm 10+ is on $PATH and re-run.
EOF
    exit 1
fi

printf 'Installing @linotech/cli globally ...\n'
npm install -g @linotech/cli

if ! command -v linopay >/dev/null 2>&1; then
    cat >&2 <<'EOF'
linopay: npm reported success but `linopay` is not on $PATH.

This usually means npm's global bin directory is not on $PATH.
Add this to your shell profile (~/.zshrc, ~/.bashrc):

  export PATH="$(npm config get prefix)/bin:$PATH"

Then `source` the profile (or open a new shell) and run `linopay --help`.
EOF
    exit 1
fi

printf 'Done. Run `linopay --help` to get started.\n'
