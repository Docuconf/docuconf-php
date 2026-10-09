<?php

declare(strict_types=1);

// The shared export fixture (docuconf-go conformance/export/fixture.yaml),
// declared with the core API. Its export must match
// conformance/export/golden.cue, as `docuconf conformance export` compares
// them (tests/ConformanceExportTest.php). Descriptions and details come from
// the PHPDoc comment before each declaration.

use Docuconf\Env;
use Docuconf\Tests\Fixtures\FixtureRateLimits;
use Docuconf\Tests\Fixtures\FixtureSettings;

$env = Env::declare('docuconf-fixture', '1.0.0');

/**
 * Service name, used in logs and metrics
 *
 * Lower case, as a DNS label allows.
 */
$env->ifPresent('APP_NAME')->default('orders')->minLength(2)->maxLength(40)->pattern('^[a-z][a-z0-9-]*$')
    ->group('general')->examples('orders', 'billing')->configKey('App:Name');

/** Primary Postgres connection string */
$env->required('DATABASE_URL')->isUrl('postgres', 'postgresql')->secret()->maxLength(2048)->group('database');

/** HTTP listen port */
$env->ifPresent('PORT')->isInteger()->default(8080)->between(1, 65535);

/** Fraction of requests traced */
$env->ifPresent('TRACE_RATIO')->isFloat()->default(0.25)->between(0, 1);

/** Serve the debug endpoints */
$env->ifPresent('DEBUG')->isBoolean()->default(false);

/** Upstream request timeout */
$env->ifPresent('REQUEST_TIMEOUT')->isDuration()->default('1m30s')->between('1s', '5m');

/** Minimum log level */
$env->ifPresent('LOG_LEVEL')->allowedValues(['debug', 'info', 'warn', 'error'])->default('info');

/** CORS origins allowed to call the API */
$env->ifPresent('ALLOWED_ORIGINS')->isList('string', 'csv', ';')->minItems(1)->maxItems(5)->itemMinLength(1)->itemMaxLength(255);

/** Shards this instance owns */
$env->ifPresent('SHARDS')->isList('int')->itemsBetween(0, 1023);

/** Keys that verify webhook signatures */
$env->ifPresent('WEBHOOK_KEYS')->isKeySet()->keyMinLength(32)->keyMaxLength(256);

/** Per-client rate limits */
$env->ifPresent('RATE_LIMITS')->isJson(FixtureRateLimits::class)->default(['perMinute' => 60])->maxLength(1024);

/** Port the service used to listen on */
$env->ifPresent('OLD_PORT')->isInteger()->deprecated('Use PORT instead', 'PORT');

/** Password of the partner keystore */
$env->ifPresent('PARTNER_PASSWORD')->secret();

/** Application settings */
$env->configFile('settings', '/etc/app/settings/settings.json')->required()->pathEnv('SETTINGS_FILE')->reload('watch')
    ->maxSize(65536)->group('general')->schema(FixtureSettings::class);

/** Routing rules */
$env->configFile('rules', '/etc/app/rules/rules.yaml', 'yaml')->schema(FixtureSettings::class);

/** Feature defaults */
$env->configFile('flags', '/etc/app/flags/flags.toml', 'toml')->schema(FixtureSettings::class);

/** Certificate the service serves HTTPS with */
$env->tls('serving-tls', '/etc/app/tls')->reload('watch')->dnsNames('app.example.test', 'api.example.test')
    ->keyAlgorithms('ECDSA', 'Ed25519')->minRemaining('720h')->requireCA();

/** CAs the service trusts */
$env->caBundle('trust', '/etc/app/trust/bundle.pem')->minCertificates(2);

/** Client certificate for the partner API */
$env->keystore('partner', '/etc/app/partner/keystore.p12')->passwordVar('PARTNER_PASSWORD');

/** Licence key */
$env->text('licence', '/etc/app/licence/licence.key')->minLength(8)->maxLength(64)->pattern('^[A-Z0-9-]+\n?$');

/** GeoIP database */
$env->binary('geoip', '/data/geoip/geoip.mmdb')->maxSize(134217728)->deprecated('Use geo-db instead', 'geo-db');

/** City-level location database */
$env->binary('geo-db', '/data/geo-db/geo.mmdb');

return $env;
