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

## GitHub Packages and Releases

GitHub Packages has no Composer registry, so the GitHub copy of each release is the GitHub Release. After the tag
check and the tests, `release.yml` builds `docuconf-php-<version>.zip` with `composer archive` (the same files
Packagist serves: `.gitattributes` leaves out tests, examples and tooling), creates the GitHub release if it does not
exist, and attaches the zip and its `docuconf-php-<version>.zip.sha256`.

It needs no setup and does not depend on Packagist: it uses only the workflow's own `GITHUB_TOKEN`
(`contents: write`), which the `Docuconf` organization allows unless it has restricted workflow permissions under
Organization settings > Actions.

### Installing from GitHub

No token is needed for a public repository. Composer can install straight from the repository's tags:

```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/docuconf/docuconf-php"}],
    "require": {"docuconf/docuconf": "^0.1"}
}
```

The zip is the exact dist archive for that version, for checking (`sha256sum -c docuconf-php-0.1.0.zip.sha256`),
mirroring or vendoring offline; Composer itself resolves the version from the tag.

## docuconf-go version

The spec, the CUE meta-schema and the shared conformance suite live in
[docuconf-go](https://github.com/Docuconf/docuconf-go). `.github/docuconf-go.ref` holds the full docuconf-go commit SHA
this SDK is tested against.

- **Push and pull request CI** check out docuconf-go at that commit, so a change in docuconf-go never breaks this
  repository's CI by surprise.
- **Bump pull requests.** `.github/workflows/docuconf-go-bump.yml` opens (or updates) a
  `build(deps): bump docuconf-go to <sha>` pull request on the `docuconf-go-bump` branch whenever docuconf-go's `main`
  moves: on a `docuconf-go-updated` dispatch from docuconf-go, and daily as a catch-up. CI on that pull request is the
  compatibility check; merge it when it is green. Run the workflow by hand (optionally with a `sha`) to pin a
  specific commit.
- **Nightly.** CI also runs every night against docuconf-go `main`, and can be started by hand with a
  `docuconf_go_ref` input to try any branch or commit.
- **`scripts/conformance.sh`** runs just the shared conformance suite and the `cue vet` tests against a docuconf-go
  checkout: `DOCUCONF_GO_DIR=../docuconf-go scripts/conformance.sh`. docuconf-go runs it on every pull request that
  touches the spec, so a breaking spec change shows up there before it merges.

Without the release GitHub App (`RELEASE_APP_ID` and `RELEASE_APP_PRIVATE_KEY`), the bump pull request is created with
`GITHUB_TOKEN`, which starts no workflows, so the bump workflow starts CI on the branch itself. That needs
**Settings → Actions → General → Allow GitHub Actions to create and approve pull requests**.
