# Example: orders (Laravel)

A Laravel service whose configuration is declared with docuconf. It shows three things:

- **Declare** each variable where you already read it, in `config/orders.php`, with a typed `Env::int(...)` in
  place of `env(...)`.
- **Validate** at boot: `php artisan serve` (and every request) refuses to start with a wrong value, and lists
  every problem at once.
- **Export** the same declaration as `contract.cue`, which the platform checks before it deploys.

`GET /healthz` returns `ok`. `GET /config` returns the typed values, with the secrets shown as `***`.
`POST /webhooks/payments` accepts a webhook signed with any key in `WEBHOOK_KEYS` (see [Rotate a key](#rotate-a-key)).

The app is as small as Laravel allows: [`config/orders.php`](config/orders.php),
[`routes/web.php`](routes/web.php), [`app/Webhooks.php`](app/Webhooks.php), [`bootstrap/app.php`](bootstrap/app.php)
and the usual `artisan` and `public/index.php`.

## 1. Install

```console
$ cd examples/orders
$ composer install
```

This example installs the SDK from this repository (a Composer `path` repository). The package is not on
Packagist yet, so in your own app install it from GitHub:

```console
$ composer config repositories.docuconf vcs https://github.com/docuconf/docuconf-php
$ composer require docuconf/docuconf:dev-main
```

`composer require docuconf/docuconf` works once the first release is on Packagist. Laravel discovers the service
provider; there is nothing to register.

## 2. Declare

[`config/orders.php`](config/orders.php):

```php
use Docuconf\Laravel\Env;

return [
    'port' => Env::int('PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),
    'log_level' => Env::enum('ORDERS_LOG_LEVEL', 'Minimum level the orders code logs', ['debug', 'info', 'warn', 'error'], default: 'info'),
    'database_url' => Env::url('DATABASE_URL', 'Orders database connection string', required: true, schemes: ['postgres', 'postgresql'], secret: true, maxLength: 2048),
    'allowed_origins' => Env::list('ALLOWED_ORIGINS', 'Origins allowed to call the API (CORS)', default: ['http://localhost:3000'], minItems: 1),

    /**
     * Timeout for each request.
     *
     * Raise it when clients upload large order batches. Keep it below the
     * load balancer's idle timeout, or the client sees a reset rather than
     * a `504`.
     */
    'request_timeout' => Env::duration('REQUEST_TIMEOUT', default: '30s', min: '1s', max: '5m'),
    'worker_count' => Env::int('WORKER_COUNT', 'Number of background workers', default: 4, min: 1, max: 64),

    /**
     * Keys that verify the signature on incoming payment webhooks
     *
     * A webhook is accepted when it is signed with any key in the set. Each
     * key is 32 to 256 characters, so an empty or truncated key fails at
     * boot. Without this variable, the service rejects every webhook.
     */
    'webhook_keys' => Env::keySet('WEBHOOK_KEYS', keyMinLength: 32, keyMaxLength: 256),
];
```

Each call returns the typed value, exactly where `env()` did: `config('orders.port')` is an `int`,
`config('orders.request_timeout')` a `Docuconf\Duration`, `config('orders.allowed_origins')` an array. The log
level is `ORDERS_LOG_LEVEL` because Laravel's own `config/logging.php` already reads `LOG_LEVEL`, with other values.
`REQUEST_TIMEOUT` is documented by its PHPDoc comment: the first paragraph is its description, and the rest is
exported as `details`, which `docuconf docs` renders into `CONFIG.md`.

| Variable | Type | Rules |
|---|---|---|
| `PORT` | int | 1–65535, default `8080` |
| `ORDERS_LOG_LEVEL` | enum | `debug`, `info`, `warn`, `error`; default `info` |
| `DATABASE_URL` | url | secret, required, scheme `postgres` or `postgresql`, at most 2048 characters |
| `ALLOWED_ORIGINS` | list of strings, comma-separated | at least 1 item; default `http://localhost:3000` |
| `REQUEST_TIMEOUT` | duration, Go syntax (`45s`, `1m30s`) | `1s`–`5m`, default `30s` |
| `WORKER_COUNT` | int | 1–64, default `4` |
| `WEBHOOK_KEYS` | key set, comma-separated | secret, optional; 1–2 keys of 32–256 characters each |

## 3. Run

```console
$ cp .env.example .env
$ php artisan serve
   INFO  Server running on [http://127.0.0.1:8080].
$ curl localhost:8080/healthz
ok
$ curl localhost:8080/config
{"PORT":8080,"ORDERS_LOG_LEVEL":"info","DATABASE_URL":"***","ALLOWED_ORIGINS":["http:\/\/localhost:3000"],"REQUEST_TIMEOUT":"30s","WORKER_COUNT":4,"WEBHOOK_KEYS":null}
```

`php artisan serve` listens on `SERVER_PORT`, which `.env.example` sets to `${PORT}`. Real environment
variables override `.env`, as always with Laravel.

## 4. Break a value

Set `PORT=0` in `.env` and comment out `DATABASE_URL`. The app refuses to start, exits 1, and names every
problem, one line each:

```console
$ php artisan serve
docuconf: 2 configuration problems:
  - PORT [out_of_range]: must be at least 1 (got "0")
  - DATABASE_URL [missing_required]: required, but not set
```

Secrets are never printed. With `ORDERS_LOG_LEVEL=verbose`, `DATABASE_URL=mysql://orders:hunter2@localhost/orders` and
`REQUEST_TIMEOUT=30`:

```console
$ php artisan serve
docuconf: 3 configuration problems:
  - ORDERS_LOG_LEVEL [not_in_enum]: must be one of: debug, info, warn, error (got "verbose")
  - DATABASE_URL [invalid_scheme]: scheme must be one of: postgres, postgresql
  - REQUEST_TIMEOUT [invalid_type]: is not a Go duration, such as 1m30s (got "30")
```

Every artisan command that runs the app checks the same way (`migrate`, `queue:work`, `tinker`, your own
commands); only the ones in `docuconf.skip_commands` that do not run it (`config:cache`, `package:discover`, ...)
skip the check, and they print a warning for each invalid value instead.

The codes (`out_of_range`, `missing_required`, ...) are the same in every docuconf SDK. In Kubernetes the same
text goes to `/dev/termination-log`, so `kubectl describe pod` shows it. `php artisan docuconf:check` prints the
same report without starting anything, which suits a container entrypoint before `php-fpm`.

[`smoke.sh`](smoke.sh) runs the service with a valid environment and with `PORT=0` and no `DATABASE_URL`, and
checks both, and the webhook key set below (`./smoke.sh`); CI runs it on every push.

## Rotate a key

`WEBHOOK_KEYS` is a `keySet` (SPEC §4.3), declared with `Env::keySet()`: `POST /webhooks/payments` accepts a body
whose `X-Signature` header is the hex HMAC-SHA256 of the body under any key in the set. The route reads it as a
`Docuconf\KeySet` from the validated values, and [`app/Webhooks.php`](app/Webhooks.php) passes the check to
`KeySet::verify()`, which tries every key. `docuconf docs` prints the rotation steps for a key set, so
[`CONFIG.md`](CONFIG.md) has them too. It is one comma-separated value, so one Kubernetes Secret key holds it:

```yaml
WEBHOOK_KEYS: # a key set: one Secret key holding "old,new" while rotating
  secretKeyRef: {name: orders-webhooks, key: keys}
```

A variable is read once, at start, so a new key reaches the service only when the pods restart; with two keys valid
at once, no webhook is turned away while that happens:

1. Add the new key as the second item (`old,new` in the Secret), and roll out.
2. Switch the sender to the new key.
3. Remove the old key (`new`), and roll out.

The contract allows 1 or 2 keys (a key set's defaults) of 32 to 256 characters each, so a trailing comma or a
truncated key stops the service at boot instead of locking out the sender, without printing the key:

```console
$ WEBHOOK_KEYS=old-webhook-key-0123456789abcdef0123, php artisan serve
docuconf: 1 configuration problem:
  - WEBHOOK_KEYS [out_of_range]: key 2 is empty
```

[`smoke.sh`](smoke.sh) posts webhooks signed with both keys, and
[`tests/ExampleWebhooksTest.php`](../../tests/ExampleWebhooksTest.php) walks through a rotation.
[SPEC section 6.1](https://github.com/docuconf/docuconf-go/blob/main/spec/SPEC.md#61-rotation) covers rotation in
general.

## 5. Export the contract

```console
$ php artisan docuconf:export --output=contract.cue
docuconf: wrote contract.cue
```

[`contract.cue`](contract.cue) is generated; never edit it by hand. CI re-exports it with `--check` and fails when
the committed file is out of date.

**Generated docs.** [`CONFIG.md`](CONFIG.md), the reference for developers, and
[`CONFIG.agents.md`](CONFIG.agents.md), the rules and facts AI agents need, are generated from `contract.cue` by the
`docuconf` CLI from [docuconf-go](https://github.com/docuconf/docuconf-go), through the docs model in
[`docs.json`](docs.json). Never edit them by hand; regenerate them after exporting the contract (CI fails if they
are out of date):

```console
$ docuconf docs contract.cue -o CONFIG.md
$ docuconf docs contract.cue --format agents -o CONFIG.agents.md
$ docuconf docs contract.cue --format model -o docs.json
```

## 6. Deploy

Ship `contract.cue` with the image. The platform checks the values it intends to set against it before anything
reaches the cluster: `docuconf vet` reports every missing or bad value, a secret given as a literal, or a policy
violation, and `docuconf render` turns valid values into the pod's `env`, with `DATABASE_URL` and `WEBHOOK_KEYS` from
Secrets ([`deploy/values.yaml`](deploy/values.yaml); CI vets it). A
Helm-based platform can use the [docuconf Helm chart](https://github.com/docuconf/docuconf-go/tree/main/helm)
instead. In the container, run `php artisan serve --host=0.0.0.0 --port="$PORT"` (or `php artisan docuconf:check`
before your FPM or Octane server), so a value that slipped past the platform still stops the pod with the report
above.

Run `php artisan config:cache` when the container starts, not when the image is built: the app reads the cached
values, and an image build has no real environment. docuconf records what each `Env::` call returned in the cache,
and refuses to boot when the cache was built from other values:

```console
$ php artisan config:cache                     # in the Dockerfile: no DATABASE_URL yet
$ php artisan docuconf:check                   # in the pod
docuconf: config cache is stale: bootstrap/cache/config.php was built with other values of DATABASE_URL than the environment has now, and the app reads the cached ones; run `php artisan config:cache` at container start, not at build
```
