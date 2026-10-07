# Example: orders (Laravel)

A Laravel service whose configuration is declared with docuconf. It shows three things:

- **Declare** each variable where you already read it, in `config/orders.php`, with a typed `Env::int(...)` in
  place of `env(...)`.
- **Validate** at boot: `php artisan serve` (and every request) refuses to start with a wrong value, and lists
  every problem at once.
- **Export** the same declaration as `contract.cue`, which the platform checks before it deploys.

`GET /healthz` returns `ok`. `GET /config` returns the typed values, with the secret shown as `***`.

The app is as small as Laravel allows: [`config/orders.php`](config/orders.php),
[`routes/web.php`](routes/web.php), [`bootstrap/app.php`](bootstrap/app.php) and the usual `artisan` and
`public/index.php`.

## 1. Install

```console
$ cd examples/orders
$ composer install
```

This example installs the SDK from this repository (a Composer `path` repository). In your own app:

```console
$ composer require docuconf/docuconf
```

Laravel discovers the service provider; there is nothing to register.

## 2. Declare

[`config/orders.php`](config/orders.php):

```php
use Docuconf\Laravel\Env;

return [
    'port' => Env::int('PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),
    'log_level' => Env::enum('LOG_LEVEL', 'Minimum log level', ['debug', 'info', 'warn', 'error'], default: 'info'),
    'database_url' => Env::url('DATABASE_URL', 'Orders database connection string', required: true, schemes: ['postgres'], secret: true),
    'allowed_origins' => Env::list('ALLOWED_ORIGINS', 'Origins allowed to call the API (CORS)', default: ['http://localhost:3000'], minItems: 1),
    'request_timeout' => Env::duration('REQUEST_TIMEOUT', 'Timeout for each request', default: '30s', min: '1s', max: '5m'),
    'worker_count' => Env::int('WORKER_COUNT', 'Number of background workers', default: 4, min: 1, max: 64),
];
```

Each call returns the typed value, exactly where `env()` did: `config('orders.port')` is an `int`,
`config('orders.request_timeout')` a `Docuconf\Duration`, `config('orders.allowed_origins')` an array.

| Variable | Type | Rules |
|---|---|---|
| `PORT` | int | 1–65535, default `8080` |
| `LOG_LEVEL` | enum | `debug`, `info`, `warn`, `error`; default `info` |
| `DATABASE_URL` | url | secret, required, scheme `postgres` |
| `ALLOWED_ORIGINS` | list of strings, comma-separated | at least 1 item; default `http://localhost:3000` |
| `REQUEST_TIMEOUT` | duration, Go syntax (`45s`, `1m30s`) | `1s`–`5m`, default `30s` |
| `WORKER_COUNT` | int | 1–64, default `4` |

## 3. Run

```console
$ cp .env.example .env
$ php artisan serve
   INFO  Server running on [http://127.0.0.1:8080].
$ curl localhost:8080/healthz
ok
$ curl localhost:8080/config
{"PORT":8080,"LOG_LEVEL":"info","DATABASE_URL":"***","ALLOWED_ORIGINS":["http:\/\/localhost:3000"],"REQUEST_TIMEOUT":"30s","WORKER_COUNT":4}
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

Secrets are never printed. With `LOG_LEVEL=verbose`, `DATABASE_URL=mysql://orders:hunter2@localhost/orders` and
`REQUEST_TIMEOUT=30`:

```console
$ php artisan serve
docuconf: 3 configuration problems:
  - LOG_LEVEL [not_in_enum]: must be one of: debug, info, warn, error (got "verbose")
  - DATABASE_URL [invalid_scheme]: scheme must be one of: postgres
  - REQUEST_TIMEOUT [invalid_type]: is not a Go duration, such as 1m30s (got "30")
```

The codes (`out_of_range`, `missing_required`, ...) are the same in every docuconf SDK. In Kubernetes the same
text goes to `/dev/termination-log`, so `kubectl describe pod` shows it. `php artisan docuconf:check` prints the
same report without starting anything, which suits a container entrypoint before `php-fpm`.

[`smoke.sh`](smoke.sh) runs the service with a valid environment and with `PORT=0` and no `DATABASE_URL`, and
checks both (`./smoke.sh`); CI runs it on every push.

## 5. Export the contract

```console
$ php artisan docuconf:export --output=contract.cue
docuconf: wrote contract.cue
```

[`contract.cue`](contract.cue) is generated; never edit it by hand. CI re-exports it with `--check` and fails when
the committed file is out of date.

## 6. Deploy

Ship `contract.cue` with the image. The platform checks the values it intends to set against it before anything
reaches the cluster: `docuconf vet` reports every missing or bad value, a secret given as a literal, or a policy
violation, and `docuconf render` turns valid values into the pod's `env`, with `DATABASE_URL` from a Secret. A
Helm-based platform can use the [docuconf Helm chart](https://github.com/docuconf/docuconf-go/tree/main/helm)
instead. In the container, run `php artisan serve --host=0.0.0.0 --port="$PORT"` (or `php artisan docuconf:check`
before your FPM or Octane server), so a value that slipped past the platform still stops the pod with the report
above.
