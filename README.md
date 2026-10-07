# docuconf for PHP

Typed configuration contracts for PHP apps, on top of [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv).

- **Declare** every environment variable and file your app reads, in phpdotenv's style, with a type, rules and a
  description.
- **Validate** them at boot: every problem at once, one line each, with stable codes, and never a secret value.
- **Export** the declaration as a CUE contract (`contract.cue`) that the platform checks before it deploys.

**Example:** [`examples/orders`](examples/orders) is a Laravel service with a five-minute walkthrough;
[`examples/orders-symfony`](examples/orders-symfony) is the same service on Symfony.

This is the PHP SDK of [docuconf](https://github.com/docuconf/docuconf-go). The specification is
[`spec/SPEC.md`](https://github.com/docuconf/docuconf-go/blob/main/spec/SPEC.md) in docuconf-go.

```console
$ composer require docuconf/docuconf
```

PHP 8.2 or later, with the `openssl` and `mbstring` extensions. YAML config files need `symfony/yaml`, TOML ones
`devium/toml`.

## Plain PHP

phpdotenv already validates: `$dotenv->required('PORT')->isInteger()`. docuconf keeps those words and adds what a
contract needs: a description, `secret()`, the remaining constraints, file inputs and export.

```php
use Docuconf\Env;

$env = Env::createImmutable(__DIR__, 'orders');   // a contract named "orders"; reads .env like Dotenv::createImmutable

$env->required('DATABASE_URL')->isUrl('postgres')->secret()->describe('Orders database connection string');
$env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
$env->ifPresent('LOG_LEVEL')->allowedValues(['debug', 'info', 'warn', 'error'])->default('info')->describe('Minimum log level');
$env->ifPresent('ALLOWED_ORIGINS')->isList()->minItems(1)->default(['http://localhost:3000'])->describe('CORS origins');
$env->ifPresent('REQUEST_TIMEOUT')->isDuration()->between('1s', '5m')->default('30s')->describe('Timeout for each request');
$env->ifPresent('RATE_LIMITS')->isJson(RateLimits::class)->describe('Per-client rate limits');   // schema from the class

$env->tls('serving-tls', '/etc/orders/tls')->required()
    ->dnsNames('orders.internal')->keyAlgorithms('ECDSA', 'RSA')->minRemaining('720h')
    ->describe('Certificate the service serves HTTPS with');
$env->configFile('routes', '/etc/orders/routes/routes.yaml', 'yaml')->schema(Routes::class)->required()
    ->describe('Routing table');

$config = $env->load();                     // throws Docuconf\ConfigurationError with every problem
$config->int('PORT');                       // 8080
$config->duration('REQUEST_TIMEOUT');       // Docuconf\Duration: ->toSeconds(), ->toDateInterval()
$config->json('RATE_LIMITS');               // a RateLimits instance
$config->file('routes')->value;             // a Routes instance, bound from the YAML
$config->file('serving-tls')->file('tls.crt');
json_encode($config);                       // secrets shown as "***"
```

`required()` variables cannot have a default; `ifPresent()` ones may, or are `null` when unset. Real environment
variables always win over `.env`; without `createImmutable()` (`Env::declare('orders')`) only the process
environment is read.

| Method | Type | Constraints |
|---|---|---|
| `isString()` (the default) | `string` | `minLength()`, `maxLength()`, `notEmpty()`, `pattern()` |
| `isInteger()` | `int` | `min()`, `max()`, `between()` |
| `isFloat()` | `float` | `min()`, `max()`, `between()` |
| `isBoolean()` | `bool` (`true`/`false`, also phpdotenv's `yes`/`on`/`1`...) | |
| `isDuration($encoding = 'go')` | `Docuconf\Duration` | `min()`, `max()`, `between()` with `'1s'` or a Duration |
| `isUrl(...$schemes)` | `string` | `schemes()`, `maxLength()` |
| `allowedValues([...])` or `allowedValues(MyEnum::class)` | `string` | |
| `isList($items = 'string', $encoding = 'csv', $separator = ',')` | `list<string>` or `list<int>` | `minItems()`, `maxItems()`, `itemsBetween($min, $max)` (int items), `itemMinLength()`, `itemMaxLength()` (string items) |
| `isJson(MyClass::class)` or `isJson($jsonSchema)` | an instance, or decoded JSON | the JSON Schema, `maxLength()` |

Every variable also takes `describe()` (required, 5+ characters), `secret()`, `default()`, `group()`,
`examples()`, `configKey()` and `deprecated()`. Mistakes in the declaration itself (a bad name, a short
description, a default that breaks its own rules, a non-RE2 pattern) throw `Docuconf\DeclarationError` when the
declaration is first used.

**Lengths** count characters, meaning Unicode code points (`mb_strlen($s, 'UTF-8')`), never bytes: `日本` is 2
characters and `ZÜ01` fits an `itemMaxLength(4)`. `maxLength()` bounds a url as it is, and a json value as the app
receives it, before it is parsed and whitespace included (a json default is measured as compact JSON).
`itemMinLength()` and `itemMaxLength()` bound each item of a string list after it is split, so a separator never
counts. A value out of bounds is `out_of_range`, and a secret is reported by its length, never its value. The
`Env::url()` and `Env::json()` helpers take `maxLength:`, and `Env::list()` takes `itemMinLength:` and
`itemMaxLength:`.

**Encodings.** Lists are `csv` (`a,b`), `json` (`["a","b"]`) or `indexed` (`NAME__0`, `NAME__1`, numbered from
0 with no gap); durations are `go` (`1m30s`), `iso8601` (`PT90S`), `seconds` (`90`) or `timespan` (`00:01:30`).
The contract records the encoding, so the platform renders values the way the app parses them.

**File inputs:** `configFile()` (json, yaml or toml, bound to your class), `tls()` (a `kubernetes.io/tls`
directory), `caBundle()`, `keystore()` (PKCS#12, or JKS), `text()` and `binary()`, each with `required()`,
`pathEnv()`, `maxSize()`. At boot docuconf checks the file exists and is readable within `maxSize`, parses and
binds config files, and for TLS that the key matches the certificate, the certificate is valid now with
`minRemaining` left, covers `dnsNames` (a wildcard covers one label), uses an allowed key algorithm and, with
`requireCA()`, chains to `ca.crt`. `DOCUCONF_FILE_ROOT` prefixes every absolute path, for local runs and tests.

**Config types.** A class used with `isJson()` or `schema()` is read by reflection: constructor parameters (or
public properties) typed `int`, `float`, `string`, `bool`, nullable types, backed enums, `Docuconf\Duration`,
nested classes and arrays with an item type from `#[Docuconf\Schema\ListOf(Route::class)]` or a `list<Route>`
docblock. `#[Docuconf\Schema\Field(minimum: 1, pattern: '^/')]` adds JSON Schema keywords. Unknown properties are
rejected.

## Laravel

The service provider is auto-discovered. Declare variables where you already read them: in `config/*.php`, use
`Docuconf\Laravel\Env` where you used `env()`.

```php
// config/orders.php
use Docuconf\Laravel\Env;

return [
    'port' => Env::int('PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),
    'database_url' => Env::url('DATABASE_URL', 'Orders database', required: true, schemes: ['postgres'], secret: true),
    'request_timeout' => Env::duration('REQUEST_TIMEOUT', 'Timeout for each request', default: '30s', max: '5m'),
];
```

Each call returns the typed value (`config('orders.port')` is an `int`) and records the declaration. Then:

- **At boot** the app validates every recorded variable. `php artisan serve`, queue workers and Octane print one
  line per problem and exit 1; a web request fails with `Docuconf\ConfigurationError`, logged the same way. Other
  artisan commands (migrations, `package:discover`) skip the check; the list is `docuconf.console_commands`.
- `php artisan docuconf:export --output=contract.cue` writes the contract (`--check` for CI);
  `php artisan docuconf:check` validates without starting anything, for a container entrypoint.
- `app(Docuconf\Values::class)` holds every typed value; `->redacted()` is safe to log or serve.
- Helpers: `Env::string`, `int`, `float`, `bool`, `duration`, `url`, `enum`, `list`, `json`, and
  `Env::declare(fn (Docuconf\Declaration $env) => ...)` for file inputs and anything else.

Set the contract name in `config/docuconf.php` (`php artisan vendor:publish --tag=docuconf-config`); it defaults
to the slug of `app.name`. With `php artisan config:cache`, the provider runs the config files once more at boot
to record the declarations, so validation still sees the real environment.

## Symfony

Register `Docuconf\Symfony\DocuconfBundle`, declare the variables in `config/packages/docuconf.yaml` as they appear
in a contract, and read them with the `docuconf` env processor instead of `int:`, `bool:` and friends:

```yaml
docuconf:
    name: orders
    vars:
        PORT: {type: int, description: HTTP listen port, min: 1, max: 65535, default: 8080}
        DATABASE_URL: {type: url, description: Orders database, required: true, secret: true, schemes: [postgres]}

parameters:
    orders.port: '%env(docuconf:PORT)%'
```

The kernel validates at boot (web requests, and the console commands in `docuconf.console_commands`), and
`bin/console docuconf:export` and `docuconf:check` work as in Laravel. `docuconf.declaration` may instead point at
a PHP file returning a `Docuconf\Declaration`, for config classes and file inputs.

## Export

```console
$ vendor/bin/docuconf export env.php -o contract.cue      # env.php returns a Declaration
$ vendor/bin/docuconf export env.php -o contract.cue --check
$ vendor/bin/docuconf check env.php
```

The output is plain CUE data, formatted as `cue fmt` would, with variables and files sorted by name, so it is
deterministic. [`tests/golden/sample_gateway.cue`](tests/golden/sample_gateway.cue) is the export of a fixture
using every variable and file type.

**`language: "php"`** is not yet in the meta-schema's `generator.language` list on docuconf-go `main`; it is
added by docuconf/docuconf-go#7. Until that merges, `cue vet -c` against `main` rejects every PHP contract on that
one field, and this repo's CI is red on it.

## Boot errors

```text
docuconf: 2 configuration problems:
  - PORT [out_of_range]: must be at least 1 (got "0")
  - DATABASE_URL [missing_required]: required, but not set
```

The codes are SPEC §11.2's: `missing_required`, `invalid_type`, `out_of_range`, `pattern_mismatch`, `not_in_enum`,
`invalid_scheme`, `too_few_items`, `too_many_items`, `file_missing`, `file_unreadable`, `file_too_large`,
`file_malformed`, `schema_mismatch`, `certificate_invalid`, `certificate_expiring`, `certificate_name_mismatch`,
`key_mismatch`, `keystore_unreadable`. The same text is written to `/dev/termination-log` when it exists (or to
`DOCUCONF_TERMINATION_LOG`). An empty value counts as unset, except for strings. A secret that still holds an
unresolved injector reference (`vault:`, `op://`, `ref+`) is `invalid_type`. Names that look like feature flags
(`FF_`, `FEATURE_`, `ENABLE_`) produce a warning.

## Patterns: RE2 on PCRE

Contracts use RE2, the dialect CUE matches with. docuconf runs patterns on PCRE so that they match as RE2 does:
anywhere in the value (anchor with `^` and `$`), `$` only at the very end, UTF-8 characters, and ASCII-only `\d`,
`\w`, `\s` and `\b`. Patterns using what RE2 lacks are rejected when declared: backreferences (`\1`, `\k<n>`),
lookahead and lookbehind, atomic groups, possessive quantifiers, recursion, conditionals, PCRE verbs, `\G`, `\K`,
`\Z`, `\R`, `\X`, `\h`, `\e`, flags other than `i`, `m`, `s` and `U`, and repeat counts above 1000.

## Contract-first mode

Validate an environment against a contract you did not declare in PHP, for example one written in CUE and
exported with `cue export`:

```php
$values = Docuconf\Contract::fromJson(file_get_contents('contract.json'))->load();
```

It parses every list and duration encoding and runs the same checks as the declaration API; the conformance suite
runs through it.

## Conformance

`tests/ConformanceTest.php` runs docuconf-go's shared suite (`conformance/cases.json`, SPEC §12) through
contract-first mode:

```console
$ DOCUCONF_CONFORMANCE=../docuconf-go/conformance/cases.json DOCUCONF_REQUIRE_CONFORMANCE=1 vendor/bin/phpunit tests/ConformanceTest.php
```

Without `DOCUCONF_CONFORMANCE` it looks for `../docuconf-go/conformance/cases.json`, and skips when that is
missing unless `DOCUCONF_REQUIRE_CONFORMANCE=1`. **No capability tags are skipped:** PHP holds every 64-bit integer
(`int64`), and json values are validated with opis/json-schema (`json-schema`).

## Development

```console
$ composer install
$ vendor/bin/phpunit          # DOCUCONF_SPEC_CUE=<docuconf-go>/spec/cue to cue-vet exports
$ vendor/bin/phpstan analyse  # level 8
$ vendor/bin/phpcs            # PSR-12
```

## Not supported yet

- `reload: watch`: files are read at boot, so `watch` is rejected when declared rather than promised.
- Config-file overlays and profiles (SPEC §4.4, §4.7).
- JKS keystores are checked by their integrity digest (password and corruption), not opened; PKCS#12 keystores are
  opened with OpenSSL, which rejects legacy ciphers such as RC2 unless its legacy provider is loaded.
- Markdown docs generation.

## Licence

MIT. See [LICENSE](LICENSE).
