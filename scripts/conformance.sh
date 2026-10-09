#!/usr/bin/env bash
# Runs the shared conformance suite (docuconf-go conformance/cases.json), the
# shared export check (conformance/export, compared by `docuconf conformance
# export`) and the tests that `cue vet` exported contracts against the
# meta-schema (docuconf-go spec/cue). Not the full suite. Used by this repo's
# CI and by docuconf-go's downstream gate. Every case runs: a skipped test,
# such as a case that requires a tag this SDK does not support, fails it.
#
#   DOCUCONF_GO_DIR=/path/to/docuconf-go scripts/conformance.sh
#
# Needs PHP 8.2+ (mbstring, openssl, json), Composer 2 and cue on PATH, and
# the docuconf CLI (DOCUCONF_CLI, or docuconf on PATH; otherwise it is built
# from DOCUCONF_GO_DIR with Go).
set -euo pipefail

: "${DOCUCONF_GO_DIR:?set DOCUCONF_GO_DIR to a docuconf-go checkout}"
DOCUCONF_GO_DIR=$(cd "$DOCUCONF_GO_DIR" && pwd)
export DOCUCONF_GO_DIR
export DOCUCONF_CONFORMANCE="${DOCUCONF_CONFORMANCE:-$DOCUCONF_GO_DIR/conformance/cases.json}"
export DOCUCONF_SPEC_CUE="${DOCUCONF_SPEC_CUE:-$DOCUCONF_GO_DIR/spec/cue}"
export DOCUCONF_REQUIRE_CONFORMANCE=1
export DOCUCONF_REQUIRE_VET=1

cd "$(dirname "$0")/.."
# There is no committed composer.lock (a library), so this resolves like CI.
if [ ! -x vendor/bin/phpunit ]; then
  composer update --no-interaction --no-progress --prefer-dist
fi
if [ -z "${DOCUCONF_CLI:-}" ]; then
  # Another tool may be called docuconf (another SDK's CLI); use it only if it
  # is docuconf-go's, with the conformance command.
  if command -v docuconf >/dev/null 2>&1 && docuconf conformance export --help >/dev/null 2>&1; then
    DOCUCONF_CLI=$(command -v docuconf)
  else
    bin=$(mktemp -d)
    trap 'rm -rf "$bin"' EXIT
    (cd "$DOCUCONF_GO_DIR/cmd/docuconf" && go build -o "$bin/docuconf" .)
    DOCUCONF_CLI="$bin/docuconf"
  fi
fi
export DOCUCONF_CLI
vendor/bin/phpunit --fail-on-skipped --filter '/^Docuconf\\Tests\\(ConformanceTest|ConformanceExportTest|ExportTest)::/'
