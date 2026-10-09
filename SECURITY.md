# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately, through GitHub's private vulnerability reporting: open the repository's
**Security** tab and choose **Report a vulnerability**
([direct link](https://github.com/docuconf/docuconf-php/security/advisories/new)). Do not open a public issue, pull
request or discussion for a suspected vulnerability.

Include what you can of:

- the affected version of `docuconf/docuconf`, and the PHP, Laravel or Symfony version;
- what an attacker can do, and what they need first;
- steps or a minimal declaration, contract or app that reproduces it.

We work on the fix in a private security advisory, credit you in it unless you prefer otherwise, and publish the
advisory when a fixed release is out.

## Response targets

| | |
|---|---|
| Acknowledge the report | within 3 business days |
| First assessment (confirmed or not, severity) | as soon as we can reproduce it, and we keep you updated in the advisory |
| Fix | released as a patch to the supported version, then the advisory is published |

## Supported versions

`docuconf/docuconf` is released from this repository as tags `vX.Y.Z` (see [RELEASING.md](RELEASING.md)). Security
fixes go to the latest minor release, as a new patch release.

**During the beta, only the latest release is supported.** Upgrade to it to get a fix.

## Scope

In scope: the `docuconf/docuconf` package, which is the core SDK (`src/`), its Laravel integration (`src/Laravel`),
its Symfony bundle (`src/Symfony`) and its `docuconf` command (`bin/docuconf`). For example: a secret value that
reaches an error message, a log, the termination log, a debug dump or an exported contract; a value that passes
validation but should not; or a file input that passes its checks but should not.

Out of scope: the example applications under [`examples`](examples), vulnerabilities in dependencies that docuconf
does not make reachable (report those upstream), and issues in a platform or cluster that only arise from its own
misconfiguration. The contract format, the meta-schema, the `docuconf` CLI, the Helm chart and the other language
SDKs live in their own repositories and follow their own policies; the CLI, the meta-schema and the Go SDK are in
[docuconf-go](https://github.com/docuconf/docuconf-go/security).

Each release's dist archive is attached to its GitHub release with a SHA-256 checksum;
[RELEASING.md](RELEASING.md#installing-from-github) shows how to check it.
