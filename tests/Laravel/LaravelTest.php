<?php

declare(strict_types=1);

namespace Docuconf\Tests\Laravel;

use Docuconf\ConfigurationError;
use Docuconf\Console;
use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Docuconf\Duration;
use Docuconf\Laravel\DocuconfServiceProvider;
use Docuconf\Laravel\Env;
use Docuconf\Laravel\StaleConfigCache;
use Docuconf\Values;
use Orchestra\Testbench\TestCase;

final class LaravelTest extends TestCase
{
    private const VARS = ['ORDERS_PORT', 'ORDERS_DATABASE_URL', 'ORDERS_TIMEOUT', 'ORDERS_ORIGINS'];

    private static ?int $exitCode = null;
    /** @var list<string> */
    private array $argv = [];
    /** @var resource */
    private $stderr;
    /** The app environment; "testing" skips the boot check, as in an app's own tests. */
    private string $appEnv = 'production';
    /** @var array<string, string>|null fingerprints a config cache was built with */
    private ?array $cachedFingerprints = null;
    private ?string $configCache = null;
    private ?string $envPath = null;

    protected function setUp(): void
    {
        $this->argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'serve'];
        self::$exitCode = null;
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $this->stderr = $stderr;
        Console::$stderr = $stderr;
        Console::$exitUsing = function (int $code): void {
            self::$exitCode = $code;
        };
        putenv('ORDERS_DATABASE_URL=postgres://db/orders');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ([...self::VARS, 'APP_CONFIG_CACHE'] as $name) {
            putenv($name);
        }
        unset($_SERVER['APP_CONFIG_CACHE'], $_ENV['APP_CONFIG_CACHE']);
        if ($this->configCache !== null) {
            @unlink($this->configCache);
        }
        $_SERVER['argv'] = $this->argv;
        Console::$stderr = null;
        Console::$exitUsing = null;
        Env::flush();
    }

    private function stderr(): string
    {
        rewind($this->stderr);
        return (string) stream_get_contents($this->stderr);
    }

    /**
     * What a config/orders.php file would hold.
     *
     * @return array<string, mixed>
     */
    private static function ordersConfig(): array
    {
        return [
            'port' => Env::int('ORDERS_PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),
            'database_url' => Env::url('ORDERS_DATABASE_URL', 'Postgres connection string', required: true, schemes: ['postgres'], secret: true),
            'timeout' => Env::duration('ORDERS_TIMEOUT', 'Request timeout', default: '30s', min: '1s', max: '5m'),
            'origins' => Env::list('ORDERS_ORIGINS', 'CORS origins', default: ['http://localhost:3000'], minItems: 1),
        ];
    }

    protected function getPackageProviders($app): array
    {
        return [DocuconfServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Config files run before providers boot, as here.
        Env::flush();
        $app['env'] = $this->appEnv;
        if ($this->envPath !== null) {
            $app->useEnvironmentPath($this->envPath);
        }
        $app['config']->set('orders', self::ordersConfig());
        $app['config']->set('docuconf.name', 'orders');
        if ($this->cachedFingerprints !== null) {
            // As if loaded from bootstrap/cache/config.php.
            $app['config']->set(DocuconfServiceProvider::FINGERPRINTS, $this->cachedFingerprints);
        }
    }

    private function application(): \Illuminate\Foundation\Application
    {
        return $this->app ?? throw new \LogicException('no application');
    }

    /** @param array<string, mixed> $parameters */
    private function command(string $command, array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        return $pending instanceof \Illuminate\Testing\PendingCommand ? $pending : throw new \LogicException('mocked console output is off');
    }

    private function refresh(): void
    {
        $this->refreshApplication();
    }

    public function testEnvReturnsTypedValuesForConfigFiles(): void
    {
        putenv('ORDERS_PORT=9090');
        putenv('ORDERS_TIMEOUT=45s');
        putenv('ORDERS_ORIGINS=https://a.example.com,https://b.example.com');
        putenv('ORDERS_DATABASE_URL');
        $this->appEnv = 'testing';
        $this->refresh();
        self::assertSame(9090, config('orders.port'));
        self::assertEquals(Duration::ofSeconds(45), config('orders.timeout'));
        self::assertSame(['https://a.example.com', 'https://b.example.com'], config('orders.origins'));
        self::assertNull(config('orders.database_url'));
    }

    // A key set (SPEC §6.1): a secret list with item length limits.
    public function testASecretKeySet(): void
    {
        $old = 'old-webhook-key-0123456789abcdef0123';
        $new = 'new-webhook-key-0123456789abcdef0123';
        try {
            putenv("WEBHOOK_KEYS=$old,$new");
            $keys = Env::list('WEBHOOK_KEYS', 'Keys that verify webhook signatures', minItems: 1, maxItems: 2, itemMinLength: 32, itemMaxLength: 256, secret: true);
            self::assertSame([$old, $new], $keys);
            $declaration = Env::declaration('orders');
            $var = $declaration->spec()->vars['WEBHOOK_KEYS'];
            self::assertSame([true, 1, 2, 32, 256], [$var->secret, $var->minItems, $var->maxItems, $var->itemMinLength, $var->itemMaxLength]);
            self::assertSame('***', $declaration->load()->redacted()['WEBHOOK_KEYS']);

            putenv("WEBHOOK_KEYS=$old,");
            $result = Env::declaration('orders')->check();
            self::assertSame(['WEBHOOK_KEYS out_of_range'], array_map(fn ($v) => "$v->input $v->code", $result->violations));
            self::assertStringNotContainsString('webhook-key', implode("\n", array_map('strval', $result->violations)));
        } finally {
            putenv('WEBHOOK_KEYS');
        }
    }

    public function testBadValueFallsBackToDefaultInConfig(): void
    {
        putenv('ORDERS_PORT=0');
        $this->appEnv = 'testing';
        $this->refresh();
        self::assertSame(8080, config('orders.port'));
    }

    public function testValuesAreBoundWhenValid(): void
    {
        putenv('ORDERS_DATABASE_URL=postgres://u:s3cr3t@db/orders');
        $this->refresh();
        $values = $this->application()->make(Values::class);
        self::assertSame(8080, $values->int('ORDERS_PORT'));
        self::assertSame('***', $values->redacted()['ORDERS_DATABASE_URL']);
    }

    public function testValuesThrowWhenInvalid(): void
    {
        putenv('ORDERS_PORT=0');
        putenv('ORDERS_DATABASE_URL');
        $this->appEnv = 'testing';
        $this->refresh();
        try {
            $values = $this->application()->make(Values::class);
            self::fail('expected a ConfigurationError, got ' . get_debug_type($values));
        } catch (ConfigurationError $e) {
            self::assertSame([
                ['var' => 'ORDERS_PORT', 'code' => 'out_of_range'],
                ['var' => 'ORDERS_DATABASE_URL', 'code' => 'missing_required'],
            ], $e->codes());
        }
    }

    public function testServeValidatesAtBootAndExits(): void
    {
        putenv('ORDERS_PORT=0');
        $this->refresh();
        self::assertSame(1, self::$exitCode);
        self::assertSame("docuconf: 1 configuration problem:\n  - ORDERS_PORT [out_of_range]: must be at least 1 (got \"0\")\n", $this->stderr());
    }

    public function testServeBootsWhenValid(): void
    {
        $this->refresh();
        self::assertNull(self::$exitCode);
        self::assertSame('', $this->stderr());
    }

    public function testEveryCommandThatRunsTheAppValidates(): void
    {
        putenv('ORDERS_PORT=0');
        foreach (['migrate', 'tinker', 'orders:work', 'queue:work', 'schedule:run'] as $command) {
            self::$exitCode = null;
            $_SERVER['argv'] = ['artisan', $command, '--force'];
            $this->refresh();
            self::assertSame(1, self::$exitCode, $command);
        }
    }

    public function testMaintenanceCommandsSkipButWarnAboutInvalidValues(): void
    {
        putenv('ORDERS_PORT=0');
        putenv('ORDERS_DATABASE_URL');
        foreach (['config:cache', 'package:discover', 'optimize', 'list'] as $command) {
            $_SERVER['argv'] = ['artisan', $command];
            $this->refresh();
            self::assertNull(self::$exitCode, $command);
        }
        self::assertStringContainsString("docuconf: warning: ORDERS_PORT is invalid (out_of_range); using its default (8080)\n", $this->stderr());
        self::assertStringNotContainsString('ORDERS_DATABASE_URL', $this->stderr(), 'a missing value is not a substituted one');
    }

    public function testUnitTestsDoNotValidateAtBoot(): void
    {
        putenv('ORDERS_PORT=0');
        $this->appEnv = 'testing';
        $this->refresh();
        self::assertNull(self::$exitCode);
    }

    public function testStaleConfigCacheFailsTheBoot(): void
    {
        // config:cache ran with ORDERS_PORT unset (8080); the container now sets 9000.
        $this->cachedFingerprints = Env::fingerprints();
        $this->configCache = (string) tempnam(sys_get_temp_dir(), 'docuconf-config');
        putenv("APP_CONFIG_CACHE={$this->configCache}");
        $_SERVER['APP_CONFIG_CACHE'] = $this->configCache;
        putenv('ORDERS_PORT=9000');
        $this->refresh();
        self::assertSame(1, self::$exitCode);
        self::assertStringContainsString('docuconf: config cache is stale: ', $this->stderr());
        self::assertStringContainsString(' was built with other values of ORDERS_PORT than the environment has now', $this->stderr());
        self::assertStringContainsString('run `php artisan config:cache` at container start, not at build', $this->stderr());
        $this->command('docuconf:check')->assertExitCode(1);
    }

    public function testCurrentConfigCacheBoots(): void
    {
        putenv('ORDERS_PORT=9000');
        $this->refresh();
        $this->cachedFingerprints = Env::fingerprints();
        $this->configCache = (string) tempnam(sys_get_temp_dir(), 'docuconf-config');
        putenv("APP_CONFIG_CACHE={$this->configCache}");
        $_SERVER['APP_CONFIG_CACHE'] = $this->configCache;
        $this->refresh();
        self::assertNull(self::$exitCode, $this->stderr());
    }

    public function testConfigCacheIsComparedWithDotenvToo(): void
    {
        // config:cache read ORDERS_PORT=9000 from .env; with config cached,
        // Laravel no longer loads .env, but the value is still the current one.
        putenv('ORDERS_PORT=9000');
        $this->refresh();
        $this->cachedFingerprints = Env::fingerprints();
        putenv('ORDERS_PORT');
        $dir = sys_get_temp_dir() . '/docuconf-dotenv-' . bin2hex(random_bytes(4));
        $this->envPath = $dir;
        mkdir($dir);
        file_put_contents("$dir/.env", "ORDERS_PORT=9000\n");
        $this->configCache = (string) tempnam(sys_get_temp_dir(), 'docuconf-config');
        $_SERVER['APP_CONFIG_CACHE'] = $this->configCache;
        try {
            $this->refresh();
            self::assertNull(self::$exitCode, $this->stderr());
        } finally {
            unlink("$dir/.env");
            rmdir($dir);
        }
    }

    public function testStaleCacheOverHttpThrows(): void
    {
        $this->cachedFingerprints = ['ORDERS_PORT' => Env::fingerprint(1234)];
        $this->configCache = (string) tempnam(sys_get_temp_dir(), 'docuconf-config');
        $_SERVER['APP_CONFIG_CACHE'] = $this->configCache;
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
        try {
            $this->refresh();
            self::fail('the app booted');
        } catch (StaleConfigCache $e) {
            self::assertStringContainsString('other values of ORDERS_PORT', $e->getMessage());
        } finally {
            unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
        }
        self::assertSame('', $this->stderr(), 'reported once, by the exception, not also to the error log');
    }

    public function testBadConfigOverHttpThrowsOnce(): void
    {
        putenv('ORDERS_PORT=0');
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
        try {
            $this->refresh();
            self::fail('the app booted');
        } catch (ConfigurationError $e) {
            self::assertSame([['var' => 'ORDERS_PORT', 'code' => 'out_of_range']], $e->codes());
        } finally {
            unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
        }
        self::assertSame('', $this->stderr());
    }

    public function testDeclarationErrorsPointAtTheConfigFile(): void
    {
        try {
            Env::int('WORKER_COUNT', 'Workers', default: 500, max: 64);
            self::fail('expected a DeclarationError');
        } catch (DeclarationError $e) {
            self::assertStringContainsString('tests/Laravel/LaravelTest.php:' . (__LINE__ - 3) . ': WORKER_COUNT: ', $e->getMessage());
        }
    }

    public function testPresetDeclaresLaravelsOwnVariables(): void
    {
        config()->set('docuconf.presets', ['laravel']);
        $this->application()->forgetInstance(Declaration::class);
        $cue = $this->application()->make(Declaration::class)->export();
        self::assertStringContainsString('APP_KEY: {', $cue);
        self::assertStringContainsString('LOG_LEVEL: {', $cue);
    }

    public function testDefaultNameWarning(): void
    {
        self::assertNull(DocuconfServiceProvider::nameWarning(config()));
        config()->set('docuconf.name', null);
        config()->set('app.name', 'Laravel');
        self::assertStringContainsString('named "laravel" after Laravel\'s default app.name', (string) DocuconfServiceProvider::nameWarning(config()));
        config()->set('app.name', 'Orders');
        self::assertNull(DocuconfServiceProvider::nameWarning(config()));
    }

    public function testCheckCommand(): void
    {
        putenv('ORDERS_PORT=0');
        putenv('ORDERS_DATABASE_URL');
        $this->appEnv = 'testing';
        $this->refresh();
        $this->command('docuconf:check')->assertExitCode(1);
        putenv('ORDERS_PORT=80');
        putenv('ORDERS_DATABASE_URL=postgres://db/orders');
        $this->refresh();
        $this->command('docuconf:check')->expectsOutput('docuconf: configuration ok')->assertExitCode(0);
    }

    public function testExportCommand(): void
    {
        $out = sys_get_temp_dir() . '/docuconf-laravel-' . bin2hex(random_bytes(4)) . '.cue';
        $this->command('docuconf:export', ['--output' => $out])->assertExitCode(0);
        $cue = (string) file_get_contents($out);
        self::assertSame($this->application()->make(Declaration::class)->export(), $cue);
        self::assertStringContainsString("package orders\n", $cue);
        self::assertStringContainsString('ORDERS_DATABASE_URL: {', $cue);
        $this->command('docuconf:export', ['--output' => $out, '--check' => true])->assertExitCode(0);
        file_put_contents($out, 'stale');
        $this->command('docuconf:export', ['--output' => $out, '--check' => true])->assertExitCode(1);
        unlink($out);
    }

    public function testDeclareEscapeHatchForFiles(): void
    {
        Env::declare(fn (Declaration $d) => $d->text('license', '/etc/orders/license/key')->describe('Licence key'));
        $this->application()->forgetInstance(Declaration::class);
        self::assertStringContainsString('license: {', $this->application()->make(Declaration::class)->export());
    }
}
