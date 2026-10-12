#!/usr/bin/env bash
set -Eeuo pipefail
# Exercise the production coordinator on an isolated, cooperating runtime,
# without touching the test runner's Nginx, cron or system services.
SCRIPT_DIR=$1
INSTALL_DIR=$2
MODE=$3
GIT_REF=${4:-}
RESTORE_DATABASE=${4:-}
REPO_URL=$5
UPGRADE_MANAGED_HOST=0
ok() { printf '[ OK ] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*" >&2; }
fail() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }
source "$SCRIPT_DIR/scripts/upgrade.sh"
upgrade_mode
