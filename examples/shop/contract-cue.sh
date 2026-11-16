#!/usr/bin/env bash
# Writes contract.cue, the CUE form of contract.json, to stdout: the same
# contract, unified with the meta-schema (contract.#Contract), as the other
# examples keep it. contract.json is the source; regenerate after editing it:
#
#   ./contract-cue.sh > contract.cue
#
# Needs cue (cuelang.org/go/cmd/cue).
set -euo pipefail
cd "$(dirname "$0")"
cue="${CUE:-cue}"
{
  printf '// Code generated from contract.json by contract-cue.sh. DO NOT EDIT.\npackage shop\n\nimport "docuconf.dev/contract"\n\ncontract.#Contract & {\n'
  "$cue" export contract.json --out cue | sed 's/^/\t/'
  printf '}\n'
} | "$cue" fmt -
