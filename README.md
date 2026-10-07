# docuconf for PHP

Typed configuration contracts for PHP apps, on top of [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv).

- **Declare** every environment variable and file your app reads, in phpdotenv's style, with a type, rules and a
  description.
- **Validate** them at boot: every problem at once, one line each, with stable codes, and never a secret value.
- **Export** the declaration as a CUE contract (`contract.cue`) that the platform checks before it deploys.

This is the PHP SDK of [docuconf](https://github.com/docuconf/docuconf-go). The specification is
[`spec/SPEC.md`](https://github.com/docuconf/docuconf-go/blob/main/spec/SPEC.md) in docuconf-go. Examples:
[`examples/orders`](examples/orders) (Laravel) and [`examples/orders-symfony`](examples/orders-symfony) (Symfony).

## Install

The package is not on Packagist yet. Install it from GitHub:

```console
$ composer config repositories.docuconf vcs https://github.com/docuconf/docuconf-php
$ composer require docuconf/docuconf:dev-main
```

or from a local clone:

```console
$ composer config repositories.docuconf path ../docuconf-php
$ composer require docuconf/docuconf:@dev
```

`composer require docuconf/docuconf` works once the first release is on Packagist.

PHP 8.2 or later, with `mbstring`. TLS, CA bundle and PKCS#12 inputs need `openssl`; YAML config files need
`symfony/yaml`, TOML ones `devium/toml`. Laravel and Symfony are covered [below](#laravel).

## 1. Declare

One file says everything the service reads from its environment, and returns the declaration:

```php
<?php
// config/env.php
use Docuconf\Env;

$env = Env::declare('orders')->withDotenv(dirname(__DIR__));   // also reads .env, if there is one

$env->required('DATABASE_URL')->isUrl('postgres', 'postgresql')->secret()->describe('Orders database connection string');
$env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
$env->ifPresent('LOG_LEVEL')->allowedValues(['debug', 'info', 'warn', 'error'])->default('info')->describe('Minimum log level');
$env->ifPresent('ALLOWED_ORIGINS')->isList()->minItems(1)->default(['http://localhost:3000'])->describe('Origins allowed to call the API (CORS)');
$env->ifPresent('REQUEST_TIMEOUT')->isDuration()->between('1s', '5m')->default('30s')->describe('Timeout for each request');
$env->ifPresent('WORKER_COUNT')->isInteger()->between(1, 64)->default(4)->describe('Number of background workers');

return $env;
```

phpdotenv already validates (`$dotenv->required('PORT')->isInteger()`); docuconf keeps those words and adds what a
contract needs: a description, `secret()`, the remaining constraints, file inputs and export. `required()`
variables cannot have a default; `ifPresent()` ones may, or are `null` when unset. Real environment variables
always win over `.env`, and docuconf reads `.env` without changing `$_ENV`, `$_SERVER` or `getenv()`.

## 2. Run

The app's entry point loads the declaration with `loadOrExit()`:

```php
<?php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

$config = (require __DIR__ . '/../config/env.php')->loadOrExit();

$port = $config->int('PORT');                         // 8080
$timeout = $config->duration('REQUEST_TIMEOUT');      // a Docuconf\Duration: ->toSeconds(), ->toDateInterval()
$database = $config->secret('DATABASE_URL');          // prints as "***"; ->reveal() for the real value

echo json_encode($config), "\n";                      // secrets shown as "***"
```

<!-- readme-test: run -->
```console
$ DATABASE_URL=postgres://orders:s3cr3t@db/orders php public/index.php
{"DATABASE_URL":"***","PORT":8080,"LOG_LEVEL":"info","ALLOWED_ORIGINS":["http:\/\/localhost:3000"],"REQUEST_TIMEOUT":"30s","WORKER_COUNT":4}
```

## 3. See an error

When anything is wrong, `loadOrExit()` prints every problem, one line each, writes the same text to the
Kubernetes termination log, and exits 1, with no stack trace:

<!-- readme-test: run -->
```console
$ PORT=0 WORKER_COUNT=many php public/index.php
docuconf: 3 configuration problems:
  - DATABASE_URL [missing_required]: required, but not set
  - PORT [out_of_range]: must be at least 1 (got "0")
  - WORKER_COUNT [invalid_type]: is not an integer (got "many")
```

A secret's value is never printed, not even when it is wrong. A variable that is set but not declared, and is
one or two letters away from a declared name, gets a warning (also without its value):

<!-- readme-test: run -->
```console
$ DATABASE_URL=postgres://db/orders DATABSE_URL=postgres://db/orders php public/index.php
docuconf: warning: DATABSE_URL is set but not declared; did you mean DATABASE_URL?
{"DATABASE_URL":"***","PORT":8080,"LOG_LEVEL":"info","ALLOWED_ORIGINS":["http:\/\/localhost:3000"],"REQUEST_TIMEOUT":"30s","WORKER_COUNT":4}
```

`$env->load()` does the same checks but throws `Docuconf\ConfigurationError` instead, for code that handles the
problems itself.

## 4. Test your config

`check()` and `load()` take an explicit environment map. With one, nothing outside the map is read, the process
environment is never changed, and the termination log is only written when the map sets
`DOCUCONF_TERMINATION_LOG`. A PHPUnit test:

```php
<?php
// tests/ConfigTest.php
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const DATABASE_URL = 'postgres://orders:s3cr3t@db/orders';

    public function testDefaults(): void
    {
        $env = require __DIR__ . '/../config/env.php';
        $config = $env->load(['DATABASE_URL' => self::DATABASE_URL]);
        self::assertSame(8080, $config->int('PORT'));
        self::assertSame(30.0, $config->duration('REQUEST_TIMEOUT')?->toSeconds());
    }

    public function testRejectsPortZero(): void
    {
        $env = require __DIR__ . '/../config/env.php';
        $result = $env->check(['PORT' => '0', 'DATABASE_URL' => self::DATABASE_URL]);
        self::assertSame(['PORT:out_of_range'], array_map(fn ($v) => "{$v->input}:{$v->code}", $result->violations));
    }
}
```

The Laravel and Symfony versions are in [their sections](#testing-in-laravel).

## 5. Export the contract

<!-- readme-test: run -->
```console
$ vendor/bin/docuconf export config/env.php -o contract.cue
docuconf: wrote contract.cue
$ vendor/bin/docuconf export config/env.php -o contract.cue --check
docuconf: contract.cue is up to date
```

Commit `contract.cue`, and run the `--check` line in CI: it exits 1 when the file is out of date. The export
needs no environment, and is plain CUE data formatted as `cue fmt` would, with variables and files sorted by
name, so it is deterministic. `vendor/bin/docuconf check config/env.php` validates the environment without
starting the app.

## 6. Deploy

Ship `contract.cue` with the image. The platform checks the values it intends to set against it before anything
reaches the cluster (`docuconf vet` in docuconf-go reports every missing or bad value, or a secret given as a
literal) and renders valid values into the pod, with `DATABASE_URL` from a Secret. In the container, the app's
own `loadOrExit()` (or `vendor/bin/docuconf check config/env.php` in the entrypoint) still stops a pod whose
values slipped past the platform, and `kubectl describe pod` shows the report from the termination log.

## Laravel

The service provider is auto-discovered. Declare each variable where you already read it: in `config/*.php`,
use `Docuconf\Laravel\Env` where you used `env()`.

```php
<?php
// config/orders.php
use Docuconf\Laravel\Env;

return [
    'port' => Env::int('PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),
    'database_url' => Env::url('DATABASE_URL', 'Orders database', required: true, schemes: ['postgres', 'postgresql'], secret: true),
    'request_timeout' => Env::duration('REQUEST_TIMEOUT', 'Timeout for each request', default: '30s', max: '5m'),
    'log_level' => Env::enum('ORDERS_LOG_LEVEL', 'Minimum level the orders code logs', ['debug', 'info', 'warn', 'error'], default: 'info'),
];
```

Each call returns the typed value (`config('orders.port')` is an `int`) and records the declaration. Use your own
prefix where Laravel already reads a name: `config/logging.php` reads `LOG_LEVEL`, with other values.

- **At boot** the app validates every recorded variable. Every artisan command that runs the app refuses to start
  on a bad configuration (`serve`, `queue:work`, Octane, `migrate`, `tinker`, your own commands), printing one
  line per problem and exiting 1. Commands that do not run the app (`config:cache`, `package:discover`,
  `optimize`, ...; the list is `docuconf.skip_commands`) skip the check, and print a warning for each invalid
  value they run with instead. A web request fails with `Docuconf\ConfigurationError`, which Laravel logs.
- `php artisan docuconf:export --output=contract.cue` writes the contract (`--check` for CI);
  `php artisan docuconf:check` validates without starting anything, for a container entrypoint.
- `app(Docuconf\Values::class)` holds every typed value; `->redacted()` is safe to log or serve.
- Helpers: `Env::string`, `int`, `float`, `bool`, `duration`, `url`, `enum`, `list`, `json`, and
  `Env::declare(fn (Docuconf\Declaration $env) => ...)` for file inputs and anything else.
- `'presets' => ['laravel']` in `config/docuconf.php` also declares the variables Laravel reads itself, so the
  contract covers them: `APP_KEY` (secret, required), `APP_ENV`, `APP_DEBUG`, `APP_URL`, `LOG_LEVEL`, `DB_URL`
  and `DB_PASSWORD`.

Publish `config/docuconf.php` with `php artisan vendor:publish --tag=docuconf-config`. The contract name is
`docuconf.name` (`DOCUCONF_SERVICE`), or else the slug of `app.name`; `docuconf:export` warns when that is
Laravel's default, "laravel". Secret values come back from `Env::` as plain strings, as `env()` gives them, so
`php artisan config:show` prints them like any env-backed value; `app(Docuconf\Values::class)->secret('NAME')`
gives one that never prints.

### Deploying Laravel

Run `php artisan config:cache` when the container starts, not when the image is built. With a config cache the
app reads the cached values, and an image build has no real environment. docuconf stores a hash of what each
`Env::` call returned in the cache, and the boot check (and `docuconf:check`) fails when the environment now
gives other values:

```text
docuconf: config cache is stale: bootstrap/cache/config.php was built with other values of DATABASE_URL than the environment has now, and the app reads the cached ones; run `php artisan config:cache` at container start, not at build
```

### Testing in Laravel

Test suites (`APP_ENV=testing`) skip the boot check. Check the declarations with an explicit map instead:

```php
<?php
// tests/Feature/ConfigTest.php
namespace Tests\Feature;

use Docuconf\Declaration;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function test_rejects_port_zero(): void
    {
        $result = $this->app->make(Declaration::class)->check(['PORT' => '0', 'DATABASE_URL' => 'postgres://db/orders']);
        $this->assertSame(['PORT:out_of_range'], array_map(fn ($v) => "{$v->input}:{$v->code}", $result->violations));
    }
}
```

## Symfony

Register the bundle in `config/bundles.php` (Flex does not register it on its own yet), declare the variables in
`config/packages/docuconf.yaml` as they appear in a contract, and read them with the `docuconf` env processor
instead of `int:`, `bool:` and friends, or with the `Docuconf\Values` service:

```php
<?php
// config/bundles.php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Docuconf\Symfony\DocuconfBundle::class => ['all' => true],
];
```

```yaml
# config/packages/docuconf.yaml
docuconf:
    name: orders
    vars:
        PORT: {type: int, description: HTTP listen port, min: 1, max: 65535, default: 8080}
        DATABASE_URL: {type: url, description: Orders database, required: true, secret: true, schemes: [postgres, postgresql]}
        REQUEST_TIMEOUT: {type: duration, description: Timeout for each request, max: 5m, default: 30s}

parameters:
    orders.port: '%env(docuconf:PORT)%'
    orders.request_timeout_seconds: '%env(docuconf_seconds:REQUEST_TIMEOUT)%'
```

- The keys are checked by Symfony itself: a typo such as `secert: true` is an `Unrecognized option` error, and
  `bin/console config:dump-reference docuconf` lists every key. The contract is checked when the container
  compiles, so a mistake fails `cache:clear`, not the first request.
- Values are read the way `%env(NAME)%` reads them: real environment variables, `.env` files, the secrets vault
  (`bin/console secrets:set DATABASE_URL`) and `env(NAME)` parameter defaults. In `prod`, a required or secret
  variable whose value only comes from a committed `.env` file gets a warning.
- The kernel validates at boot: web requests, and every console command except `docuconf.skip_commands`
  (`cache:*`, `secrets:*`, `debug:*`, ...). `bin/console docuconf:export` and `docuconf:check` work as in
  Laravel.
- `%env(docuconf:NAME)%` goes innermost when chained: `%env(default:fallback:docuconf:PORT)%`. Env processors can
  only return scalars and arrays, so durations come back as Go strings (`"30s"`), or as float seconds through
  `docuconf_seconds:`; inject `Docuconf\Values` for `Docuconf\Duration` objects.
- `presets: [symfony]` also declares `APP_SECRET`, `APP_ENV` and `APP_DEBUG`. `declaration:` may point at a PHP
  file returning a `Docuconf\Declaration` instead of `vars:`, for config classes and file inputs.

### Testing in Symfony

```php
<?php
// tests/ConfigTest.php
namespace App\Tests;

use Docuconf\Symfony\Docuconf;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConfigTest extends KernelTestCase
{
    public function testRejectsPortZero(): void
    {
        $result = self::getContainer()->get(Docuconf::class)->check(['PORT' => '0', 'DATABASE_URL' => 'postgres://db/orders']);
        self::assertSame(['PORT:out_of_range'], array_map(fn ($v) => "{$v->input}:{$v->code}", $result->violations));
    }
}
```

## Reference

### Variables

| Method | Type | Constraints |
|---|---|---|
| `isString()` (the default) | `string` | `minLength()`, `maxLength()`, `notEmpty()`, `pattern()` |
| `isInteger()` | `int` | `min()`, `max()`, `between()` |
| `isFloat()` | `float` | `min()`, `max()`, `between()` |
| `isBoolean()` | `bool` (`true`/`false`, also phpdotenv's `yes`/`on`/`1`...) | |
| `isDuration($encoding = 'go')` | `Docuconf\Duration` | `min()`, `max()`, `between()` with `'1s'`, the encoding's own form, or a Duration |
| `isUrl(...$schemes)` | `string` | `schemes()` |
| `allowedValues([...])` or `allowedValues(MyEnum::class)` | `string` | |
| `isList($items = 'string', $encoding = 'csv', $separator = ',')` | `list<string>` or `list<int>` | `minItems()`, `maxItems()`, `itemsBetween($min, $max)` (int items) |
| `isJson(MyClass::class)` or `isJson($jsonSchema)` | an instance, or decoded JSON | the JSON Schema |

Every variable also takes `describe()` (required, 5+ characters), `secret()`, `default()`, `group()`,
`examples()`, `configKey()` and `deprecated()`. Mistakes in the declaration itself (a bad name, a short
description, a constraint that does not fit the type, a default that breaks its own rules, a non-RE2 pattern)
throw `Docuconf\DeclarationError` when the declaration is first used.

**Encodings.** Lists are `csv` (`a,b`), `json` (`["a","b"]`) or `indexed` (`NAME__0`, `NAME__1`, numbered from
0 with no gap); durations are `go` (`1m30s`), `iso8601` (`PT90S`), `seconds` (`90`) or `timespan` (`00:01:30`).
The contract records the encoding, so the platform renders values the way the app parses them. A duration's
`default()` and bounds may be written in Go form or in the variable's own encoding
(`->isDuration('iso8601')->default('PT30S')`); the contract stores Go form.

### Typed access and secrets

`$config->int('PORT')`, `string()`, `float()`, `bool()`, `duration()`, `list()`, `json()` and `$config['PORT']`
return the typed value, or `null` for an optional variable that is unset. To get a class instead of strings,
bind to a readonly class: each constructor parameter reads the variable of the same name in
SCREAMING_SNAKE_CASE, or the one `#[Docuconf\FromEnv('NAME')]` names.

```php
<?php
use Docuconf\Duration;
use Docuconf\FromEnv;
use Docuconf\Secret;

final class OrdersConfig
{
    /** @param list<string> $origins */
    public function __construct(
        public readonly int $port,                                      // PORT
        public readonly Secret $databaseUrl,                            // DATABASE_URL, never printed
        public readonly Duration $requestTimeout,                       // REQUEST_TIMEOUT
        #[FromEnv('ALLOWED_ORIGINS')] public readonly array $origins,
    ) {
    }
}

$orders = (require __DIR__ . '/config/env.php')->loadOrExit()->bind(OrdersConfig::class);
```

Secrets do not print by accident: `var_dump()`, `print_r()`, `var_export()`, `json_encode()` and Symfony's
VarDumper (Laravel's `dump()` and `dd()`) show a loaded configuration with every secret as `***`, and
`serialize()` refuses it, so secrets never reach a cache. `Docuconf\Secret` (from `$config->secret('NAME')` or a
`Secret` parameter) does the same for a single value; `->reveal()` returns it. A secret handed to your own code
as a string is yours to protect: mark parameters that take one `#[\SensitiveParameter]`, so stack traces leave
it out.

### File inputs

`configFile()` (json, yaml or toml, bound to your class), `tls()` (a `kubernetes.io/tls` directory),
`caBundle()`, `keystore()` (PKCS#12, or JKS), `text()` and `binary()`, each with `required()`, `pathEnv()` and
`maxSize()`:

```php
<?php
// src/RateLimits.php
use Docuconf\Schema\Field;

final class RateLimits
{
    public function __construct(
        #[Field(minimum: 1)] public readonly int $perMinute,
        #[Field(minimum: 0)] public readonly int $burst = 0,
    ) {
    }
}
```

```php
<?php
// src/Routes.php
use Docuconf\Duration;
use Docuconf\Schema\Field;
use Docuconf\Schema\ListOf;

final class Route
{
    public function __construct(
        #[Field(pattern: '^/')] public readonly string $match,
        #[Field(pattern: '^https?://')] public readonly string $upstream,
        public readonly ?Duration $timeout = null,
    ) {
    }
}

final class Routes
{
    /** @param list<Route> $routes */
    public function __construct(#[ListOf(Route::class), Field(minItems: 1)] public readonly array $routes)
    {
    }
}
```

```php
<?php
// config/gateway.php
use Docuconf\Env;

$env = Env::declare('gateway');
$env->ifPresent('RATE_LIMITS')->isJson(RateLimits::class)->describe('Per-client rate limits');   // the schema comes from the class
$env->tls('serving-tls', '/etc/gateway/tls')->required()
    ->dnsNames('gateway.internal')->keyAlgorithms('ECDSA', 'RSA')->minRemaining('720h')
    ->describe('Certificate the service serves HTTPS with');
$env->configFile('routes', '/etc/gateway/routes/routes.yaml', 'yaml')->schema(Routes::class)->required()
    ->describe('Routing table');

return $env;

// $config->json('RATE_LIMITS') is a RateLimits; $config->file('routes')->value a Routes;
// $config->file('serving-tls')->file('tls.crt') the certificate's path.
```

At boot docuconf checks the file exists and is readable within `maxSize`, parses and binds config files, and for
TLS that the key matches the certificate, the certificate is valid now with `minRemaining` left, covers
`dnsNames` (a wildcard covers one label), uses an allowed key algorithm and, with `requireCA()`, chains to
`ca.crt`. `DOCUCONF_FILE_ROOT` prefixes every absolute path, for local runs and tests.

**Config types.** A class used with `isJson()` or `schema()` is read by reflection: constructor parameters (or
public properties) typed `int`, `float`, `string`, `bool`, nullable types, backed enums, `Docuconf\Duration`,
nested classes and arrays with an item type from `#[Docuconf\Schema\ListOf(Route::class)]` or a `list<Route>`
docblock. `#[Docuconf\Schema\Field(minimum: 1, pattern: '^/')]` adds JSON Schema keywords. Unknown properties are
rejected.

### Boot errors

The codes are SPEC §11.2's: `missing_required`, `invalid_type`, `out_of_range`, `pattern_mismatch`, `not_in_enum`,
`invalid_scheme`, `too_few_items`, `too_many_items`, `file_missing`, `file_unreadable`, `file_too_large`,
`file_malformed`, `schema_mismatch`, `certificate_invalid`, `certificate_expiring`, `certificate_name_mismatch`,
`key_mismatch`, `keystore_unreadable`. The report is also written to `DOCUCONF_TERMINATION_LOG`, or to
`/dev/termination-log` when it exists. An empty value counts as unset, except for strings. A secret that still
holds an unresolved injector reference (`vault:`, `op://`, `ref+`) is `invalid_type`. Names that look like
feature flags (`FF_`, `FEATURE_`, `ENABLE_`) produce a warning.

### Patterns: RE2 on PCRE

Contracts use RE2, the dialect CUE matches with. docuconf runs patterns on PCRE so that they match as RE2 does:
anywhere in the value (anchor with `^` and `$`), `$` only at the very end, UTF-8 characters, and ASCII-only `\d`,
`\w`, `\s` and `\b`. Patterns using what RE2 lacks are rejected when declared: backreferences (`\1`, `\k<n>`),
lookahead and lookbehind, atomic groups, possessive quantifiers, recursion, conditionals, PCRE verbs, `\G`, `\K`,
`\Z`, `\R`, `\X`, `\h`, `\e`, flags other than `i`, `m`, `s` and `U`, and repeat counts above 1000.

### Contract-first mode

Validate an environment against a contract you did not declare in PHP, for example one written in CUE and
exported with `cue export`:

```php
<?php
$config = Docuconf\Contract::fromJson(file_get_contents('contract.json'))->loadOrExit();
```

It parses every list and duration encoding and runs the same checks as the declaration API; the conformance suite
runs through it. The contract is read strictly, as the CUE `#Contract` is closed: an unknown key (`secert`) or a
loosely typed one (`secret: "no"`) is an error, never a dropped rule.

### Conformance

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

`tests/ReadmeTest.php` runs this README: it lints every PHP block, runs the quickstart (sections 1 to 5) in a
scratch project, including the `console` blocks marked `readme-test: run`, and loads the Laravel and Symfony
declarations.

## Not supported yet

- `reload: watch`: files are read at boot, so `watch` is rejected when declared rather than promised.
- Config-file overlays and profiles (SPEC §4.4, §4.7); a contract that uses them is rejected.
- JKS keystores are checked by their integrity digest (password and corruption), not opened; PKCS#12 keystores are
  opened with OpenSSL, which rejects legacy ciphers such as RC2 unless its legacy provider is loaded.
- A Symfony Flex recipe, so the bundle registers itself.
- Markdown docs generation.

## Licence

MIT. See [LICENSE](LICENSE).
