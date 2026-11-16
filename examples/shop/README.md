# Example: shop (plain PHP, contract-first)

One service image that runs as two roles, `web` and `worker`, configured by a contract written by hand rather than
declared in PHP. It shows three parts of the spec end to end:

- **Roles** (SPEC §4.9): `DOCUCONF_ROLE` picks the role, and each role requires only its own inputs. The web role
  needs `ALLOWED_ORIGINS`; the worker needs `QUEUES`, and neither needs the other's.
- **`requiredIf`** (SPEC §4.2.1): `LOG_SCHEMA_URL` is required only while `LOG_FORMAT` is `json`, and the webhook
  signing key file only while `WEBHOOKS_ENABLED` is `true`.
- **An included fragment** (SPEC §4.8): the contract includes `otel@1`, so the OpenTelemetry variables
  (`OTEL_EXPORTER_OTLP_PROTOCOL`, ...) are checked at boot like the app's own.

There is no server, database or queue. [`bin/shop`](bin/shop) loads the configuration through the SDK, prints one
line for its role and exits 0, or lists every problem and exits 1. The code is
[`app/Shop.php`](app/Shop.php).

## The contract

[`contract.json`](contract.json) is the contract, checked in as written. The app loads it at startup with the
SDK's contract-first API:

```php
$values = Docuconf\Contract::fromJson(file_get_contents(__DIR__ . '/../contract.json'))->loadOrExit();
$values->list('QUEUES'); // ['default', 'mail']
```

| Input | Type | Rules |
|---|---|---|
| `DOCUCONF_ROLE` | enum | `web` or `worker`; required |
| `DATABASE_URL` | url | secret, required, scheme `postgres` or `postgresql` |
| `PORT` | int | `web` only; 1–65535, default `8080` |
| `ALLOWED_ORIGINS` | list of strings, comma-separated | `web` only; required |
| `QUEUES` | list of strings, comma-separated | `worker` only; required |
| `WEBHOOKS_ENABLED` | bool | `worker` only; default `false` |
| `LOG_FORMAT` | enum | `text` or `json`; default `text` |
| `LOG_SCHEMA_URL` | url | scheme `https`; required when `LOG_FORMAT` is `json` |
| `OTEL_*` | from the `otel@1` fragment | all optional; checked by type, such as `OTEL_EXPORTER_OTLP_PROTOCOL`, one of `grpc`, `http/protobuf`, `http/json` |
| file `webhook-signing-key` | text | `worker` only; secret, at `/etc/shop/webhooks/signing.key`, at least 32 characters; required when `WEBHOOKS_ENABLED` is `true` |

An input of another role is optional: a web workload may set `WEBHOOKS_ENABLED=true` without the worker's key file.
Without a valid role, every role-specific input is optional, so a missing or unknown `DOCUCONF_ROLE` is the only
problem reported.

[`contract.cue`](contract.cue) is the same contract in CUE, as the platform reads it. It is generated from
`contract.json`; never edit it by hand:

```console
$ ./contract-cue.sh > contract.cue
```

CI regenerates it and fails when the committed file differs, and checks it against the docuconf meta-schema with
`cue vet -c`. [`CONFIG.md`](CONFIG.md), [`CONFIG.agents.md`](CONFIG.agents.md) and [`docs.json`](docs.json) are
generated from it by the `docuconf` CLI from [docuconf-go](https://github.com/docuconf/docuconf-go) (CI fails if
they are out of date):

```console
$ docuconf docs contract.cue -o CONFIG.md
$ docuconf docs contract.cue --format agents -o CONFIG.agents.md
$ docuconf docs contract.cue --format model -o docs.json
```

## Run it

```console
$ cd examples/shop
$ composer install          # the SDK comes from this repository, through a Composer path repository
$ export DATABASE_URL=postgres://shop@db:5432/shop
```

The web role:

```console
$ DOCUCONF_ROLE=web ALLOWED_ORIGINS=https://shop.example bin/shop
shop web: port 8080, origins https://shop.example
```

The worker role, with webhooks off and then on. With webhooks on it needs the signing key file, read under
`DOCUCONF_FILE_ROOT` when that is set:

```console
$ DOCUCONF_ROLE=worker QUEUES=default,mail bin/shop
shop worker: queues default,mail, webhooks off
$ DOCUCONF_ROLE=worker QUEUES=default,mail WEBHOOKS_ENABLED=true bin/shop
docuconf: 1 configuration problem:
  - webhook-signing-key [file_missing]: /etc/shop/webhooks/signing.key does not exist, and is required when WEBHOOKS_ENABLED is true
$ mkdir -p root/etc/shop/webhooks && openssl rand -hex 32 > root/etc/shop/webhooks/signing.key
$ DOCUCONF_FILE_ROOT=root DOCUCONF_ROLE=worker QUEUES=default,mail WEBHOOKS_ENABLED=true bin/shop
shop worker: queues default,mail, webhooks on
```

JSON logs need a schema URL:

```console
$ DOCUCONF_ROLE=worker QUEUES=default LOG_FORMAT=json bin/shop
docuconf: 1 configuration problem:
  - LOG_SCHEMA_URL [missing_required]: is required when LOG_FORMAT is "json"
```

Secrets are never printed: `DATABASE_URL=mysql://shop:hunter2@db/shop` is reported as
`DATABASE_URL [invalid_scheme]: scheme must be one of: postgres, postgresql`.

## Test matrix

[`tests/ExampleShopTest.php`](../../tests/ExampleShopTest.php), in the repository's PHPUnit suite, runs each case
from `DATABASE_URL=postgres://shop@db:5432/shop` and a fresh, empty `DOCUCONF_FILE_ROOT`. It checks the
violations, and runs the service in a PHP process for its output and exit code.

| # | Environment (plus the base) | Expected |
|---|---|---|
| 1 | `DOCUCONF_ROLE=web ALLOWED_ORIGINS=https://shop.example` | ok: `shop web: port 8080, origins https://shop.example` |
| 2 | `DOCUCONF_ROLE=worker QUEUES=default,mail` | ok, the worker needs no `ALLOWED_ORIGINS`: `shop worker: queues default,mail, webhooks off` |
| 3 | `DOCUCONF_ROLE=web` | `ALLOWED_ORIGINS [missing_required]` |
| 4 | `QUEUES=default` (no role) | `DOCUCONF_ROLE [missing_required]`, and nothing else |
| 5 | `DOCUCONF_ROLE=cron` | `DOCUCONF_ROLE [not_in_enum]`, and nothing else |
| 6 | `DOCUCONF_ROLE=worker QUEUES=default LOG_FORMAT=json` | `LOG_SCHEMA_URL [missing_required]: is required when LOG_FORMAT is "json"` |
| 7 | as 6, plus `LOG_SCHEMA_URL=https://logs.example/v1.json` | ok |
| 8 | `DOCUCONF_ROLE=worker QUEUES=default WEBHOOKS_ENABLED=true`, no key file | `webhook-signing-key [file_missing]: ... does not exist, and is required when WEBHOOKS_ENABLED is true` |
| 9 | as 8, with a key of 32 or more characters at `/etc/shop/webhooks/signing.key` under the root | ok: `webhooks on` |
| 10 | `DOCUCONF_ROLE=web ALLOWED_ORIGINS=https://shop.example WEBHOOKS_ENABLED=true` | ok: the key file belongs to the worker |
| 11 | `DOCUCONF_ROLE=web ALLOWED_ORIGINS=https://shop.example OTEL_EXPORTER_OTLP_PROTOCOL=carrier-pigeon` | `OTEL_EXPORTER_OTLP_PROTOCOL [not_in_enum]`: fragment variables are checked at boot |

```console
$ vendor/bin/phpunit tests/ExampleShopTest.php        # from the repository root
```

[`smoke.sh`](smoke.sh) runs `bin/shop` for cases 1, 2 and 6 and checks its output and exit code (`./smoke.sh`); CI
runs it on every push.
