#!/usr/bin/env bash
set -Eeuo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
use_pinned=0
if [[ ${1:-} == --pinned-node && $# == 1 ]]; then
  use_pinned=1
elif [[ $# != 0 ]]; then
  printf 'Usage: bash scripts/build-editor.sh [--pinned-node]\n' >&2
  exit 1
fi

if [[ $use_pinned == 1 ]] || ! command -v node >/dev/null || ! node -e 'if (Number(process.versions.node.split(".")[0]) < 22) process.exit(1)'; then
  [[ $(uname -s) == Linux ]] || { printf 'Asset builds require Node.js 22+ on this platform.\n' >&2; exit 1; }
  version=22.23.3
  case $(uname -m) in
    x86_64) architecture=x64; checksum=1084aa36196bba4c3a5e69a1ee388a6e4ff729dad09445fbcd434b28fe3c24af ;;
    aarch64|arm64) architecture=arm64; checksum=5ced2d48d1d7198739b7f86804de0171aefb6823b684b12341d3321afc3cb0b2 ;;
    *) printf 'Install Node.js 22+ for this architecture.\n' >&2; exit 1 ;;
  esac
  temporary_root=$(readlink -f -- "${TMPDIR:-/tmp}")
  node_stage=$(mktemp -d "$temporary_root/openwiki-node-build.XXXXXXXX")
  cleanup() {
    local resolved
    resolved=$(readlink -f -- "$node_stage")
    [[ $resolved == "$temporary_root"/openwiki-node-build.* && -d $resolved && ! -L $node_stage ]] || return 1
    rm -rf -- "$resolved"
  }
  trap cleanup EXIT
  archive="node-v$version-linux-$architecture.tar.gz"
  printf 'Building with temporary Node.js %s (%s); verifying pinned SHA-256.\n' "$version" "$architecture"
  curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 \
    "https://nodejs.org/dist/v$version/$archive" -o "$node_stage/$archive"
  printf '%s  %s\n' "$checksum" "$node_stage/$archive" | sha256sum --check --status
  tar -xzf "$node_stage/$archive" -C "$node_stage"
  export PATH="$node_stage/node-v$version-linux-$architecture/bin:$PATH"
fi

npm ci --include=dev --no-audit --no-fund
npm run build
