<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\ConfigurationError;
use Docuconf\Contract;
use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Docuconf\Duration;
use Docuconf\Env;
use Docuconf\Tests\Fixtures\RateLimits;
use Docuconf\Tests\Support\CueVet;
use PHPUnit\Framework\TestCase;

final class DeclarationTest extends TestCase
{
    private static function orders(): Declaration
    {
        $env = Env::declare('orders');
        $env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
        $env->ifPresent('LOG_LEVEL')->allowedValues(['debug', 'info', 'warn', 'error'])->default('info')->describe('Minimum log level');
        $env->required('DATABASE_URL')->isUrl('postgres')->secret()->describe('Postgres connection string');
        $env->ifPresent('ALLOWED_ORIGINS')->isList()->minItems(1)->default(['http://localhost:3000'])->describe('CORS origins');
        $env->ifPresent('REQUEST_TIMEOUT')->isDuration()->between('1s', '5m')->default('30s')->describe('Request timeout');
        $env->ifPresent('WORKER_COUNT')->isInteger()->between(1, 64)->default(4)->describe('Worker processes');
        $env->ifPresent('RATE_LIMITS')->isJson(RateLimits::class)->describe('Per-client rate limits');
        return $env;
    }

    private const SECRET = 'postgres://orders:s3cr3t-pw@db:5432/orders';

    public function testTypedValuesAndDefaults(): void
    {
        $values = self::orders()->load(['DATABASE_URL' => self::SECRET, 'WORKER_COUNT' => '8', 'RATE_LIMITS' => '{"perMinute":60}']);
        self::assertSame(8080, $values->int('PORT'));
        self::assertSame(8, $values->int('WORKER_COUNT'));
        self::assertSame('info', $values->string('LOG_LEVEL'));
        self::assertSame(['http://localhost:3000'], $values->list('ALLOWED_ORIGINS'));
        self::assertEquals(Duration::ofSeconds(30), $values->duration('REQUEST_TIMEOUT'));
        self::assertSame(self::SECRET, $values['DATABASE_URL']);
        $limits = $values->json('RATE_LIMITS');
        self::assertInstanceOf(RateLimits::class, $limits);
        self::assertSame(60, $limits->perMinute);
        self::assertSame(0, $limits->burst);
        self::assertFalse($values->isSet('PORT'));
        self::assertTrue($values->isSet('WORKER_COUNT'));
    }

    public function testRedactedHidesSecrets(): void
    {
        $values = self::orders()->load(['DATABASE_URL' => self::SECRET]);
        $json = (string) json_encode($values);
        self::assertStringNotContainsString('s3cr3t', $json);
        self::assertSame('***', $values->redacted()['DATABASE_URL']);
        self::assertSame('30s', $values->redacted()['REQUEST_TIMEOUT']);
    }

    public function testBadInt(): void
    {
        $e = self::loadError(self::orders(), ['DATABASE_URL' => self::SECRET, 'PORT' => 'eighty']);
        self::assertSame([['var' => 'PORT', 'code' => 'invalid_type']], $e->codes());
        self::assertStringContainsString('PORT [invalid_type]: is not an integer (got "eighty")', $e->getMessage());
    }

    public function testMissingRequired(): void
    {
        $e = self::loadError(self::orders(), []);
        self::assertSame([['var' => 'DATABASE_URL', 'code' => 'missing_required']], $e->codes());
    }

    public function testEveryViolationIsReportedTogether(): void
    {
        $e = self::loadError(self::orders(), [
            'PORT' => '0',
            'LOG_LEVEL' => 'verbose',
            'ALLOWED_ORIGINS' => '',
            'REQUEST_TIMEOUT' => '10m',
            'WORKER_COUNT' => '4.5',
            'RATE_LIMITS' => '{"perMinute":0}',
        ]);
        self::assertSame([
            ['var' => 'PORT', 'code' => 'out_of_range'],
            ['var' => 'LOG_LEVEL', 'code' => 'not_in_enum'],
            ['var' => 'DATABASE_URL', 'code' => 'missing_required'],
            ['var' => 'REQUEST_TIMEOUT', 'code' => 'out_of_range'],
            ['var' => 'WORKER_COUNT', 'code' => 'invalid_type'],
            ['var' => 'RATE_LIMITS', 'code' => 'schema_mismatch'],
        ], $e->codes());
        self::assertSame(7, substr_count($e->getMessage(), "\n") + 1, 'one header line, then one line per problem');
    }

    public function testSecretValuesAreNeverPrinted(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'term');
        $e = self::loadError(self::orders(), ['DATABASE_URL' => 'mysql://orders:s3cr3t-pw@db/orders', 'DOCUCONF_TERMINATION_LOG' => $log]);
        self::assertSame([['var' => 'DATABASE_URL', 'code' => 'invalid_scheme']], $e->codes());
        self::assertStringNotContainsString('s3cr3t', $e->getMessage());
        self::assertStringNotContainsString('mysql', $e->getMessage());
        $written = (string) file_get_contents($log);
        self::assertStringContainsString('DATABASE_URL [invalid_scheme]', $written);
        self::assertStringNotContainsString('s3cr3t', $written);
        unlink($log);
    }

    public function testUnresolvedInjectorReference(): void
    {
        $e = self::loadError(self::orders(), ['DATABASE_URL' => 'vault:secret/data/orders#url']);
        self::assertSame([['var' => 'DATABASE_URL', 'code' => 'invalid_type']], $e->codes());
        self::assertStringContainsString('"vault:"', $e->getMessage());
        self::assertStringNotContainsString('secret/data/orders', $e->getMessage());
    }

    public function testEmptyIsUnsetExceptForStrings(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('PORT')->isInteger()->default(80)->describe('Listen port');
        $env->ifPresent('NAME')->describe('Display name');
        $values = $env->load(['PORT' => '', 'NAME' => '']);
        self::assertSame(80, $values->int('PORT'));
        self::assertSame('', $values->string('NAME'));
    }

    public function testIndexedListGap(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('HOSTS')->isList('string', 'indexed')->describe('Upstream hosts');
        self::assertSame(['a', 'b'], $env->load(['HOSTS__0' => 'a', 'HOSTS__1' => 'b', 'HOSTS__X' => 'no'])->list('HOSTS'));
        $e = self::loadError($env, ['HOSTS__0' => 'a', 'HOSTS__2' => 'c']);
        self::assertSame([['var' => 'HOSTS', 'code' => 'invalid_type']], $e->codes());
        self::assertStringContainsString('HOSTS__0, HOSTS__2', $e->getMessage());
    }

    public function testIntListItemBounds(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('SHARDS')->isList('int')->itemsBetween(0, 7)->describe('Shard numbers');
        self::assertSame([0, 7], $env->load(['SHARDS' => '0,7'])->list('SHARDS'));
        self::assertSame([['var' => 'SHARDS', 'code' => 'out_of_range']], self::loadError($env, ['SHARDS' => '8'])->codes());
        self::assertMatchesRegularExpression('/itemMin: +0\n/', $env->export());
    }

    /** SPEC §4.3: maxLength on url and json, item lengths on string lists, in code points. */
    private static function lengths(): Declaration
    {
        $env = Env::declare('lengths');
        $env->ifPresent('CALLBACK')->isUrl('https')->maxLength(24)->describe('Where to report each run');
        $env->ifPresent('LIMITS')->isJson()->maxLength(16)->describe('Run limits as a JSON object');
        $env->ifPresent('BRANCHES')->isList()->itemMinLength(2)->itemMaxLength(4)->describe('Branch codes');
        $env->ifPresent('BRANCHES_JSON')->isList('string', 'json')->itemMaxLength(4)->describe('Branch codes as JSON');
        $env->ifPresent('BRANCHES_IDX')->isList('string', 'indexed')->itemMaxLength(4)->describe('Branch codes, one per variable');
        $env->ifPresent('DB_URL')->isUrl()->secret()->maxLength(30)->describe('Database connection string');
        $env->ifPresent('NAME')->maxLength(2)->describe('Display name');
        return $env;
    }

    public function testLengthsCountCodePointsNotBytes(): void
    {
        $values = self::lengths()->load([
            'CALLBACK' => 'https://例え.jp/日本語の道/一二三四',
            'LIMITS' => '{"n":"日本語の道路xy"}',
            'BRANCHES' => 'ZÜ01,日本,😀😀',
            'BRANCHES_JSON' => '["😀😀😀😀"]',
            'NAME' => '日本',
        ]);
        self::assertSame(['ZÜ01', '日本', '😀😀'], $values->list('BRANCHES'));
        self::assertSame(['n' => '日本語の道路xy'], $values->json('LIMITS'));
        self::assertSame([['var' => 'NAME', 'code' => 'out_of_range']], self::loadError(self::lengths(), ['NAME' => '日本語'])->codes());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function tooLong(): iterable
    {
        yield 'url above maxLength' => [['CALLBACK' => 'https://a.example/runs/42'], 'CALLBACK'];
        yield 'url above maxLength in characters' => [['CALLBACK' => 'https://例え.jp/日本語の道/一二三四五'], 'CALLBACK'];
        yield 'json above maxLength' => [['LIMITS' => '{"max":123456789}'], 'LIMITS'];
        yield 'json whitespace counts' => [['LIMITS' => '{ "max": 123456 }'], 'LIMITS'];
        yield 'item above itemMaxLength' => [['BRANCHES' => 'BE,ZÜRICH'], 'BRANCHES'];
        yield 'item below itemMinLength' => [['BRANCHES' => 'BE,B'], 'BRANCHES'];
        yield 'json list item' => [['BRANCHES_JSON' => '["BE","GENEVA"]'], 'BRANCHES_JSON'];
        yield 'indexed list item' => [['BRANCHES_IDX__0' => 'BE', 'BRANCHES_IDX__1' => 'GENEVA'], 'BRANCHES_IDX'];
    }

    /** @param array<string, string> $env */
    #[\PHPUnit\Framework\Attributes\DataProvider('tooLong')]
    public function testLengthLimitsAreOutOfRange(array $env, string $var): void
    {
        self::assertSame([['var' => $var, 'code' => 'out_of_range']], self::loadError(self::lengths(), $env)->codes());
    }

    public function testTooLongSecretReportsItsLengthOnly(): void
    {
        $e = self::loadError(self::lengths(), ['DB_URL' => 'postgres://app:s3cr3t@db:5432/app']);
        self::assertSame([['var' => 'DB_URL', 'code' => 'out_of_range']], $e->codes());
        self::assertStringContainsString('33 characters', $e->getMessage());
        self::assertStringNotContainsString('s3cr3t', $e->getMessage());
    }

    public function testLengthLimitsAreExported(): void
    {
        $cue = self::lengths()->export();
        self::assertMatchesRegularExpression('/CALLBACK: \{[^}]*maxLength: +24\n/s', $cue);
        self::assertMatchesRegularExpression('/LIMITS: \{[^}]*maxLength: +16\n/s', $cue);
        self::assertMatchesRegularExpression('/itemMinLength: +2\n\s*itemMaxLength: +4\n/', $cue);
        CueVet::vet($cue);
    }

    public function testLengthLimitsInContractFirstMode(): void
    {
        $contract = Contract::fromJson([
            'apiVersion' => 'docuconf.dev/v1alpha1',
            'kind' => 'ConfigContract',
            'metadata' => ['name' => 'lengths'],
            'vars' => [
                'LIMITS' => ['type' => 'json', 'description' => 'Run limits', 'maxLength' => 16],
                'CODES' => ['type' => 'list', 'description' => 'Branch codes', 'items' => 'string', 'itemMinLength' => 2, 'itemMaxLength' => 4],
            ],
        ]);
        self::assertSame(['ZÜ01'], $contract->load(['CODES' => 'ZÜ01'])->list('CODES'));
        try {
            $contract->load(['LIMITS' => '{ "max": 123456 }', 'CODES' => 'B']);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $e) {
            self::assertSame([['var' => 'LIMITS', 'code' => 'out_of_range'], ['var' => 'CODES', 'code' => 'out_of_range']], $e->codes());
        }
        $this->expectException(DeclarationError::class);
        $this->expectExceptionMessage('only apply to a list of string items');
        Contract::fromJson([
            'apiVersion' => 'docuconf.dev/v1alpha1',
            'kind' => 'ConfigContract',
            'metadata' => ['name' => 'lengths'],
            'vars' => ['N' => ['type' => 'list', 'description' => 'Some ints', 'items' => 'int', 'itemMaxLength' => 4]],
        ]);
    }

    /** @return iterable<string, array{callable(Declaration): mixed, string}> */
    public static function badDeclarations(): iterable
    {
        yield 'lowercase name' => [fn (Declaration $d) => $d->ifPresent('port')->describe('Listen port'), 'name must match'];
        yield 'short description' => [fn (Declaration $d) => $d->ifPresent('PORT')->describe('Port'), 'at least 5 characters'];
        yield 'required with default' => [fn (Declaration $d) => $d->required('PORT')->default('x')->describe('Listen port'), 'cannot have a default'];
        yield 'secret with default' => [fn (Declaration $d) => $d->ifPresent('TOKEN')->secret()->default('x')->describe('API token'), 'a secret cannot have a default'];
        yield 'default out of range' => [fn (Declaration $d) => $d->ifPresent('PORT')->isInteger()->between(1, 10)->default(80)->describe('Listen port'), 'default does not satisfy'];
        yield 'default not in enum' => [fn (Declaration $d) => $d->ifPresent('LEVEL')->allowedValues(['a'])->default('b')->describe('Log level'), 'default does not satisfy'];
        yield 'lookahead' => [fn (Declaration $d) => $d->ifPresent('CODE')->pattern('^(?=a)')->describe('Some code'), 'lookahead is not RE2'];
        yield 'backreference' => [fn (Declaration $d) => $d->ifPresent('CODE')->pattern('(a)\1')->describe('Some code'), 'backreferences are not RE2'];
        yield 'itemMin on strings' => [fn (Declaration $d) => $d->ifPresent('L')->isList()->itemsBetween(0, 1)->describe('Some list'), 'only apply to a list of int'];
        yield 'itemMinLength on ints' => [fn (Declaration $d) => $d->ifPresent('L')->isList('int')->itemMaxLength(3)->describe('Some list'), 'only apply to a list of string'];
        yield 'itemMinLength above itemMaxLength' => [fn (Declaration $d) => $d->ifPresent('L')->isList()->itemMinLength(5)->itemMaxLength(4)->describe('Some list'), 'itemMinLength is greater than itemMaxLength'];
        yield 'maxLength on an int' => [fn (Declaration $d) => $d->ifPresent('N')->isInteger()->maxLength(3)->describe('Some count'), 'maxLength does not apply to a int variable'];
        yield 'item default too long' => [fn (Declaration $d) => $d->ifPresent('L')->isList()->itemMaxLength(2)->default(['ok', 'ZÜ01'])->describe('Some list'), 'default does not satisfy'];
        yield 'url default too long' => [fn (Declaration $d) => $d->ifPresent('U')->isUrl()->maxLength(10)->default('https://example.com')->describe('Some endpoint'), 'default does not satisfy'];
        // {"n":"日本語の道路"} is 14 characters as compact JSON.
        yield 'json default too long' => [fn (Declaration $d) => $d->ifPresent('J')->isJson()->maxLength(13)->default(['n' => '日本語の道路'])->describe('Some limits'), 'default does not satisfy'];
        yield 'bad duration default' => [fn (Declaration $d) => $d->ifPresent('T')->isDuration()->default('soon')->describe('A timeout'), 'not a Go duration'];
        yield 'watch' => [fn (Declaration $d) => $d->text('license', '/etc/app/license/key')->reload('watch')->describe('Licence key'), 'reload "watch" is not supported'];
        yield 'reserved mount' => [fn (Declaration $d) => $d->text('license', '/etc/license.key')->describe('Licence key'), 'hiding what the image has'];
        yield 'passwordVar not secret' => [function (Declaration $d) {
            $d->ifPresent('PW')->describe('Keystore password');
            $d->keystore('ks', '/etc/app/ks/store.p12')->passwordVar('PW')->describe('Client keystore');
        }, 'must be a declared secret variable'];
        yield 'duplicate' => [function (Declaration $d) {
            $d->ifPresent('PORT')->describe('Listen port');
            $d->ifPresent('PORT')->describe('Listen port');
        }, 'declared twice'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDeclarations')]
    public function testDeclarationErrors(callable $declare, string $expected): void
    {
        $d = Env::declare('svc');
        $declare($d);
        try {
            $d->spec();
            self::fail('expected a DeclarationError');
        } catch (DeclarationError $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testFeatureFlagWarning(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('FF_NEW_CHECKOUT')->isBoolean()->default(false)->describe('New checkout flow');
        self::assertStringContainsString('feature flag', $env->warnings()[0]);
    }

    public function testDeprecatedWarning(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('OLD_PORT')->isInteger()->deprecated('use PORT', 'PORT')->describe('Old listen port');
        self::assertSame(['OLD_PORT is deprecated: use PORT; use PORT'], $env->check(['OLD_PORT' => '1'])->warnings);
    }

    public function testDotenvIsAnOptInAndRealEnvWins(): void
    {
        $dir = sys_get_temp_dir() . '/docuconf-dotenv-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/.env", "DOCUCONF_T_A=from-file\nDOCUCONF_T_B=from-file\n");
        putenv('DOCUCONF_T_B=from-env');
        try {
            $env = Env::createImmutable($dir, 'svc');
            $env->ifPresent('DOCUCONF_T_A')->describe('Set by the .env file');
            $env->ifPresent('DOCUCONF_T_B')->describe('Set by the environment');
            $values = $env->load();
            self::assertSame('from-file', $values->string('DOCUCONF_T_A'));
            self::assertSame('from-env', $values->string('DOCUCONF_T_B'));
        } finally {
            putenv('DOCUCONF_T_B');
            unset($_ENV['DOCUCONF_T_A'], $_SERVER['DOCUCONF_T_A']);
            unlink("$dir/.env");
            rmdir($dir);
        }
    }

    /** @param array<string, string> $env */
    private static function loadError(Declaration $d, array $env): ConfigurationError
    {
        try {
            $d->load($env);
        } catch (ConfigurationError $e) {
            return $e;
        }
        self::fail('expected a ConfigurationError');
    }
}
