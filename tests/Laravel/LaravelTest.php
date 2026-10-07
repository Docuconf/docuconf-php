<?php

declare(strict_types=1);

namespace Docuconf\Tests\Laravel;

use Docuconf\ConfigurationError;
use Docuconf\Declaration;
use Docuconf\Duration;
use Docuconf\Laravel\DocuconfServiceProvider;
use Docuconf\Laravel\Env;
use Docuconf\Values;
use Orchestra\Testbench\TestCase;

final class LaravelTest extends TestCase
{
    private const VARS = ['ORDERS_PORT', 'ORDERS_DATABASE_URL', 'ORDERS_TIMEOUT', 'ORDERS_ORIGINS'];

    private static ?int $exitCode = null;
    /** @var list<string> */
    private array $argv = [];

    protected function setUp(): void
    {
        $this->argv = $_SERVER['argv'] ?? [];
        self::$exitCode = null;
        DocuconfServiceProvider::$exitUsing = function (int $code): void {
            self::$exitCode = $code;
        };
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (self::VARS as $name) {
            putenv($name);
        }
        $_SERVER['argv'] = $this->argv;
        DocuconfServiceProvider::$exitUsing = null;
        Env::flush();
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
        $app['config']->set('orders', self::ordersConfig());
        $app['config']->set('docuconf.name', 'orders');
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
        $this->refresh();
        self::assertSame(9090, config('orders.port'));
        self::assertEquals(Duration::ofSeconds(45), config('orders.timeout'));
        self::assertSame(['https://a.example.com', 'https://b.example.com'], config('orders.origins'));
        self::assertNull(config('orders.database_url'));
    }

    public function testBadValueFallsBackToDefaultInConfig(): void
    {
        putenv('ORDERS_PORT=0');
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
        $_SERVER['argv'] = ['artisan', 'serve'];
        $this->refresh();
        self::assertSame(1, self::$exitCode);
    }

    public function testServeBootsWhenValid(): void
    {
        putenv('ORDERS_DATABASE_URL=postgres://db/orders');
        $_SERVER['argv'] = ['artisan', 'serve'];
        $this->refresh();
        self::assertNull(self::$exitCode);
    }

    public function testOtherCommandsDoNotValidate(): void
    {
        putenv('ORDERS_PORT=0');
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $this->refresh();
        self::assertNull(self::$exitCode);
    }

    public function testCheckCommand(): void
    {
        putenv('ORDERS_PORT=0');
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
        self::assertStringContainsString('license: {', $this->application()->make(Declaration::class)->export());
    }
}
