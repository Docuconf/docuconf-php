# Releasing

docuconf/docuconf is published on [Packagist](https://packagist.org/packages/docuconf/docuconf). Packagist reads
the package from this GitHub repository; there is nothing to upload.

## Once: submit the package

1. Sign in to Packagist with the GitHub account that administers `docuconf/docuconf-php`, and submit
   `https://github.com/docuconf/docuconf-php` at <https://packagist.org/packages/submit>. The name comes from
   `composer.json` (`docuconf/docuconf`).
2. Let Packagist install its GitHub webhook when it offers to (or connect the GitHub integration in your Packagist
   profile). From then on every pushed tag becomes a release within a minute or two.
3. Optional fallback: if the webhook cannot be installed, set the repository variable `PACKAGIST_USERNAME` and
   the secret `PACKAGIST_TOKEN` (your Packagist API token); `release.yml` then calls Packagist's update API on
   each tag.

## Every release

Releases are automated with [release-please](https://github.com/googleapis/release-please); see
[CONTRIBUTING.md](CONTRIBUTING.md#how-releases-happen) for the commit conventions it reads.

1. Merge the open release PR (`chore(main): release X.Y.Z`). It already updates `Version::VERSION` in
   `src/Version.php` and `CHANGELOG.md`. `composer.json` has no `version` field (Packagist takes versions from
   tags), so release-please leaves its content alone, though the first release PR may reformat it. The golden file
   and the example contracts do not need regenerating: their comparisons ignore `metadata.generator.version`.
2. release-please tags the merge commit `vX.Y.Z` and creates the GitHub release with the changelog entries.
3. `release.yml` checks the tag matches `Version::VERSION` and runs the tests; it keeps the release release-please
   created. Packagist picks up the tag through the webhook.

If the release PR was created with `GITHUB_TOKEN` (no release GitHub App configured), the tag does not trigger
`release.yml` by itself, so `.github/workflows/release-please.yml` starts it with `gh workflow run`. To redo the
checks by hand: `gh workflow run release.yml --ref vX.Y.Z`.

Versions follow semver. While the contract format is `v1alpha1`, minor versions may change the declaration API.

The licence is MIT (`LICENSE`, and `"license": "MIT"` in `composer.json`).
