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

1. Make sure CI is green on `main`.
2. Set `Version::VERSION` in `src/Version.php` (it is written into every exported contract's
   `metadata.generator.version`), commit, and push.
3. Tag and push: `git tag v0.1.0 && git push origin v0.1.0`.
4. `release.yml` checks the tag matches `Version::VERSION`, runs the tests, and creates the GitHub release.
   Packagist picks up the tag through the webhook.

Versions follow semver. While the contract format is `v1alpha1`, minor versions may change the declaration API.

The licence is MIT (`LICENSE`, and `"license": "MIT"` in `composer.json`).

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
