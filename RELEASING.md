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
