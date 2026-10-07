<?php

declare(strict_types=1);

// The fixture declaration: every variable type and every file type. Its
// export is the golden file tests/golden/sample_gateway.cue.

use Docuconf\Env;
use Docuconf\Tests\Fixtures\LogLevel;
use Docuconf\Tests\Fixtures\RateLimits;
use Docuconf\Tests\Fixtures\Routes;

$env = Env::declare('sample-gateway');

$env->required('ALLOWED_ORIGINS')->isList()->minItems(1)->maxItems(10)->describe('CORS origins allowed to call the API');
$env->required('DATABASE_URL')->isUrl('postgres', 'postgresql')->secret()->describe('Primary Postgres connection string');
$env->ifPresent('DEBUG')->isBoolean()->default(false)->describe('Verbose request logging');
$env->ifPresent('GOMEMLIMIT')->isInteger()->min(1)->describe('Soft memory limit, in bytes');
$env->required('KEYSTORE_PASSWORD')->secret()->notEmpty()->describe('Password for the partner keystore');
$env->ifPresent('LOG_LEVEL')->allowedValues(LogLevel::class)->default('info')->group('logging')->describe('Minimum log level emitted');
$env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
$env->required('PUBLIC_URL')->isUrl('https')->describe('Externally visible base URL');
$env->ifPresent('RATE_LIMITS')->isJson(RateLimits::class)->describe('Default per-client rate limits');
$env->required('REGION')->minLength(4)->maxLength(32)->pattern('^[a-z]{2}-[a-z]+-[0-9]$')->examples('eu-west-1')->describe('Cloud region the service runs in');
$env->ifPresent('REQUEST_TIMEOUT')->isDuration()->between('1s', '5m')->default('30s')->describe('Upstream request timeout');
$env->ifPresent('SAMPLE_RATE')->isFloat()->between(0, 1)->default(0.25)->describe('Fraction of requests traced');
$env->ifPresent('WORKER_PORTS')->isList('int', 'json')->itemsBetween(1, 65535)->default([])->describe('Ports the workers bind');

$env->binary('geoip', '/data/geoip/GeoLite2-City.mmdb')->maxSize(128 * 1024 * 1024)->describe('GeoIP database for country-based routing');
$env->text('license', '/etc/gateway/license/license.key')->required()->pattern('^[A-Z0-9]{5}(-[A-Z0-9]{5}){3}\n?$')->describe('Gateway licence key');
$env->keystore('partner-keystore', '/etc/gateway/partner/keystore.p12')->passwordVar('KEYSTORE_PASSWORD')->describe('Client certificate for mTLS to the partner API');
$env->configFile('routes', '/etc/gateway/routes/routes.yaml', 'yaml')->schema(Routes::class)->required()->pathEnv('ROUTES_FILE')->maxSize(65536)
    ->describe('Routing table: path prefixes and their upstreams');
$env->tls('serving-tls', '/etc/gateway/tls')->required()->dnsNames('gateway.internal', 'api.example.com')->keyAlgorithms('ECDSA', 'RSA')
    ->minRemaining('720h')->requireCA()->describe('Certificate the gateway serves HTTPS with');
$env->caBundle('upstream-ca', '/etc/gateway/ca/bundle.pem')->pathEnv('SSL_CERT_FILE')->describe('Private CAs the gateway trusts for upstream TLS');

return $env;
