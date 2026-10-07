# Example: orders (Symfony)

The [orders service](../orders) again, on Symfony: the same six variables, `GET /healthz` and `GET /config`
(secret shown as `***`), declared in [`config/packages/docuconf.yaml`](config/packages/docuconf.yaml) and read
with `%env(docuconf:NAME)%`, Symfony's own env-processor syntax.

```yaml
docuconf:
    name: orders-symfony
    vars:
        PORT: {type: int, description: HTTP listen port, min: 1, max: 65535, default: 8080}
        DATABASE_URL: {type: url, description: Orders database connection string, required: true, secret: true, schemes: [postgres]}
        # ...

parameters:
    orders.port: '%env(docuconf:PORT)%'                                   # an int, validated
    orders.request_timeout_seconds: '%env(docuconf_seconds:REQUEST_TIMEOUT)%'
```

## Run it

```console
$ composer install
$ export DATABASE_URL=postgres://orders:orders@localhost:5432/orders
$ bin/console docuconf:check && php -S 127.0.0.1:8080 -t public
docuconf: configuration ok
$ curl localhost:8080/config
{"PORT":8080,"LOG_LEVEL":"info","DATABASE_URL":"***","ALLOWED_ORIGINS":["http:\/\/localhost:3000"],"REQUEST_TIMEOUT":"30s","WORKER_COUNT":4}
```

The kernel validates on every boot, so a web request with a bad configuration fails with the report in the
server log. `bin/console docuconf:check` prints it up front, and exits 1:

```console
$ PORT=0 DATABASE_URL= bin/console docuconf:check
docuconf: 2 configuration problems:
  - PORT [out_of_range]: must be at least 1 (got "0")
  - DATABASE_URL [missing_required]: required, but not set
```

In `prod` the container is cached: after changing `docuconf.yaml`, run `bin/console cache:clear` (or delete
`var/cache`). [`smoke.sh`](smoke.sh) checks both runs.

## Export the contract

```console
$ bin/console docuconf:export --output=contract.cue
```

[`contract.cue`](contract.cue) is generated; CI checks it is up to date. Deploying works as for the
[Laravel example](../orders#6-deploy).
