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
