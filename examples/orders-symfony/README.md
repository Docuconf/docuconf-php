# Example: orders (Symfony)

The [orders service](../orders) again, on Symfony: the same seven variables, `GET /healthz`, `GET /config`
(secrets shown as `***`) and `POST /webhooks/payments` (see [Rotate a key](#rotate-a-key)), declared in [`config/packages/docuconf.yaml`](config/packages/docuconf.yaml) and read
with `%env(docuconf:NAME)%`, Symfony's own env-processor syntax, or with the `Docuconf\Values` service
(`GET /config` uses it).

```yaml
docuconf:
    name: orders-symfony
    vars:
        PORT: {type: int, description: HTTP listen port, min: 1, max: 65535, default: 8080}
        DATABASE_URL: {type: url, description: Orders database connection string, required: true, secret: true, schemes: [postgres, postgresql], maxLength: 2048}
        # ...

parameters:
    orders.port: '%env(docuconf:PORT)%'                                   # an int, validated
    orders.request_timeout_seconds: '%env(docuconf_seconds:REQUEST_TIMEOUT)%'
```

## Run it

```console
$ composer install          # the SDK comes from this repository, through a Composer path repository
$ export DATABASE_URL=postgres://orders:orders@localhost:5432/orders
$ bin/console docuconf:check && php -S 127.0.0.1:8080 -t public
docuconf: configuration ok
$ curl localhost:8080/config
{"PORT":8080,"LOG_LEVEL":"info","DATABASE_URL":"***","ALLOWED_ORIGINS":["http:\/\/localhost:3000"],"REQUEST_TIMEOUT":"30s","WORKER_COUNT":4,"WEBHOOK_KEYS":null}
```

The kernel validates on every boot, so a web request with a bad configuration fails with the report in the
server log. `bin/console docuconf:check` prints it up front, and exits 1:

```console
$ PORT=0 DATABASE_URL= bin/console docuconf:check
docuconf: 2 configuration problems:
  - PORT [out_of_range]: must be at least 1 (got "0")
  - DATABASE_URL [missing_required]: required, but not set
```

Every console command that runs the app (`messenger:consume`, your own commands) validates the same way at boot;
`cache:clear`, `secrets:*`, `debug:*` and the other commands in `docuconf.skip_commands` do not. Values are read
the way `%env(NAME)%` reads them, so a secret stored with `bin/console secrets:set DATABASE_URL` counts.

A typo in `docuconf.yaml` is Symfony's own config error (`Unrecognized option "secert" under
"docuconf.vars.DATABASE_URL"`), and `bin/console config:dump-reference docuconf` lists every key. In `prod` the
container is cached: after changing `docuconf.yaml`, run `bin/console cache:clear` (or delete `var/cache`).
[`smoke.sh`](smoke.sh) checks both runs.

## Rotate a key

`WEBHOOK_KEYS` is a key set, a secret list of one or two keys of 32 to 256 characters each:
`POST /webhooks/payments` accepts a body whose `X-Signature` header is the hex HMAC-SHA256 of the body under any
key in the list ([`src/Webhooks.php`](src/Webhooks.php)). It is declared like the others, its rotation steps in
`details`:

```yaml
        WEBHOOK_KEYS:
            type: list
            description: Keys that verify the signature on incoming payment webhooks
            secret: true
            items: string
            minItems: 1
            maxItems: 2
            itemMinLength: 32
            itemMaxLength: 256
```

It is one comma-separated value, so one Kubernetes Secret key (`secretKeyRef: {name: orders-webhooks, key: keys}`)
holds it. A variable is read once, at start, so a new key reaches the service only when the pods restart; with two
keys valid at once, no webhook is turned away while that happens:

1. Add the new key as the second item (`old,new` in the Secret), and roll out.
2. Switch the sender to the new key.
3. Remove the old key (`new`), and roll out.

A trailing comma or a truncated key is refused at boot, without printing the key:

```console
$ WEBHOOK_KEYS=old-webhook-key-0123456789abcdef0123, bin/console docuconf:check
docuconf: 1 configuration problem:
  - WEBHOOK_KEYS [out_of_range]: has an item shorter than itemMinLength 32 (0 characters)
```

[`smoke.sh`](smoke.sh) posts webhooks signed with both keys, and
[`tests/ExampleWebhooksTest.php`](../../tests/ExampleWebhooksTest.php) walks through a rotation.
[SPEC section 6.1](https://github.com/docuconf/docuconf-go/blob/main/spec/SPEC.md#61-rotation) covers rotation in
general.

## Export the contract

```console
$ bin/console docuconf:export --output=contract.cue
```

[`contract.cue`](contract.cue) is generated; CI checks it is up to date. Deploying works as for the
[Laravel example](../orders#6-deploy).

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
