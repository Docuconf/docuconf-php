<?php

declare(strict_types=1);

namespace Docuconf\Tests\Symfony;

use Docuconf\ConfigurationError;
use Docuconf\Console;
use Docuconf\Duration;
use Docuconf\Symfony\BootValidator;
use Docuconf\Symfony\Docuconf;
use Docuconf\Values;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;

final class SymfonyTest extends TestCase
{
    private const CONFIG = [
        'name' => 'orders',
        'vars' => [
            'ORDERS_PORT' => ['type' => 'int', 'description' => 'HTTP listen port', 'min' => 1, 'max' => 65535, 'default' => 8080],
            'ORDERS_DATABASE_URL' => ['type' => 'url', 'description' => 'Orders database', 'required' => true, 'secret' => true, 'schemes' => ['postgres']],
            'ORDERS_TIMEOUT' => ['type' => 'duration', 'description' => 'Request timeout', 'min' => '1s', 'max' => '5m', 'default' => '30s'],
            'ORDERS_ORIGINS' => ['type' => 'list', 'description' => 'CORS origins', 'items' => 'string', 'minItems' => 1, 'default' => ['http://localhost:3000']],
        ],
    ];

    private string $dir;
    private ?int $exitCode = null;
    /** @var list<string> */
    private array $argv = [];
    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/docuconf-symfony-' . bin2hex(random_bytes(6));
        $this->argv = $_SERVER['argv'] ?? [];
        // The boot check runs for every command not in skip_commands; PHPUnit's
        // arguments are not a command, so pose as one that skips.
        $_SERVER['argv'] = ['bin/console', 'cache:warmup'];
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $this->stderr = $stderr;
        Console::$stderr = $stderr;
        Console::$exitUsing = function (int $code): void {
            $this->exitCode = $code;
        };
    }

    protected function tearDown(): void
    {
        foreach (['ORDERS_PORT', 'ORDERS_DATABASE_URL', 'ORDERS_TIMEOUT', 'APP_ENV'] as $name) {
            putenv($name);
        }
        unset($_SERVER['SYMFONY_DOTENV_VARS'], $_ENV['ORDERS_DATABASE_URL']);
        $_SERVER['argv'] = $this->argv;
        Console::$stderr = null;
        Console::$exitUsing = null;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function stderr(): string
    {
        rewind($this->stderr);
        return (string) stream_get_contents($this->stderr);
    }

    /** @param array<string, mixed> $extra */
    private function kernel(array $extra = []): TestKernel
    {
        $kernel = new TestKernel($extra + self::CONFIG, $this->dir);
        $kernel->boot();
        return $kernel;
    }

    public function testEnvProcessorReturnsTypedValues(): void
    {
        putenv('ORDERS_PORT=9090');
        putenv('ORDERS_TIMEOUT=1m30s');
        putenv('ORDERS_DATABASE_URL=postgres://db/orders');
        $config = $this->kernel()->getContainer()->get('orders.config');
        self::assertInstanceOf(\ArrayObject::class, $config);
        self::assertSame(9090, $config['port']);
        self::assertSame(90.0, $config['timeout']);
        self::assertSame(['http://localhost:3000'], $config['origins']);
    }

    public function testEnvProcessorFailsWithEveryProblem(): void
    {
        putenv('ORDERS_PORT=0');
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('ORDERS_DATABASE_URL [missing_required]');
        $this->kernel()->getContainer()->get('orders.config');
    }

    public function testEveryCommandThatRunsTheAppValidatesAtBoot(): void
    {
        putenv('ORDERS_PORT=0');
        foreach (['messenger:consume', 'app:import-orders', 'doctrine:migrations:migrate'] as $command) {
            $this->exitCode = null;
            $_SERVER['argv'] = ['bin/console', '-e', 'prod', $command];
            $this->kernel();
            self::assertSame(1, $this->exitCode, $command);
        }
        self::assertStringContainsString("docuconf: 2 configuration problems:\n", $this->stderr());
    }

    public function testMaintenanceCommandsSkipTheBootCheck(): void
    {
        putenv('ORDERS_PORT=0');
        foreach (['cache:clear', 'secrets:set', 'assets:install', 'list', 'docuconf:export'] as $command) {
            $_SERVER['argv'] = ['bin/console', $command];
            $this->kernel();
            self::assertNull($this->exitCode, $command);
        }
    }

    public function testTypoInAKeyIsAnErrorNotASilentlyDroppedRule(): void
    {
        $vars = self::CONFIG['vars'];
        $vars['ORDERS_TOKEN'] = ['type' => 'string', 'description' => 'API token', 'secert' => true];
        try {
            $this->kernel(['vars' => $vars]);
            self::fail('the kernel booted');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('Unrecognized option "secert" under "docuconf.vars.ORDERS_TOKEN"', $e->getMessage());
        }
    }

    public function testLooseBooleansAreErrors(): void
    {
        $vars = self::CONFIG['vars'];
        $vars['ORDERS_TOKEN'] = ['type' => 'string', 'description' => 'API token', 'secret' => 'no'];
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('docuconf.vars.ORDERS_TOKEN.secret');
        $this->kernel(['vars' => $vars]);
    }

    public function testContractMistakesFailAtContainerCompile(): void
    {
        $vars = self::CONFIG['vars'];
        $vars['ORDERS_PORT']['default'] = 70000;
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('ORDERS_PORT: default does not satisfy');
        $this->kernel(['vars' => $vars]);
    }

    public function testVaultSecretsCount(): void
    {
        $vault = new SodiumVault($this->dir . '/config/secrets/test');
        $vault->generateKeys();
        $vault->seal('ORDERS_DATABASE_URL', 'postgres://u:vaultpw@db/o');
        $kernel = $this->kernel();
        $docuconf = $kernel->getContainer()->get(Docuconf::class);
        self::assertInstanceOf(Docuconf::class, $docuconf);
        self::assertTrue($docuconf->check()->ok(), implode("\n", array_map('strval', $docuconf->check()->violations)));
        $values = $kernel->getContainer()->get(Values::class);
        self::assertInstanceOf(Values::class, $values);
        self::assertSame('postgres://u:vaultpw@db/o', $values->string('ORDERS_DATABASE_URL'));
    }

    public function testValuesServiceGivesDurations(): void
    {
        putenv('ORDERS_DATABASE_URL=postgres://db/orders');
        $values = $this->kernel()->getContainer()->get(Values::class);
        self::assertInstanceOf(Values::class, $values);
        self::assertEquals(Duration::ofSeconds(30), $values->duration('ORDERS_TIMEOUT'));
    }

    public function testDefaultChainsWithDocuconf(): void
    {
        putenv('ORDERS_DATABASE_URL=postgres://db/orders');
        $kernel = $this->kernel();
        self::assertSame(['http://localhost:3000'], $kernel->getContainer()->getParameter('orders.origins_or_default'));
    }

    public function testPresetDeclaresTheFrameworkVariables(): void
    {
        $docuconf = $this->kernel(['presets' => ['symfony']])->getContainer()->get(Docuconf::class);
        self::assertInstanceOf(Docuconf::class, $docuconf);
        $cue = $docuconf->export();
        self::assertStringContainsString('APP_SECRET: {', $cue);
        self::assertStringContainsString('APP_ENV: {', $cue);
    }

    public function testDotenvPlaceholderWarningInProd(): void
    {
        putenv('APP_ENV=prod');
        $_ENV['ORDERS_DATABASE_URL'] = 'postgresql://app:!ChangeMe!@127.0.0.1:5432/app';
        $_SERVER['SYMFONY_DOTENV_VARS'] = 'APP_ENV,ORDERS_DATABASE_URL';
        $vars = self::CONFIG['vars'];
        $vars['ORDERS_DATABASE_URL']['schemes'] = ['postgres', 'postgresql'];
        $docuconf = new Docuconf('orders', null, $vars, [], null, [], null, 'prod');
        self::assertSame(
            ['ORDERS_DATABASE_URL (secret) comes from a .env file, not the real environment; set it in the environment or with secrets:set'],
            $docuconf->check()->warnings,
        );
    }

    public function testExportAndCheckCommands(): void
    {
        $kernel = $this->kernel();
        $app = new Application($kernel);
        $export = new CommandTester($app->find('docuconf:export'));
        self::assertSame(0, $export->execute([]));
        self::assertStringContainsString("package orders\n", $export->getDisplay());
        self::assertStringContainsString('ORDERS_DATABASE_URL: {', $export->getDisplay());
        $docuconf = $kernel->getContainer()->get(Docuconf::class);
        self::assertInstanceOf(Docuconf::class, $docuconf);
        self::assertSame($docuconf->export(), $export->getDisplay());

        $check = new CommandTester($app->find('docuconf:check'));
        self::assertSame(1, $check->execute([]));
        self::assertStringContainsString('ORDERS_DATABASE_URL [missing_required]', $check->getDisplay());
    }

    public function testCheckAnExplicitEnvMap(): void
    {
        $docuconf = $this->kernel()->getContainer()->get(Docuconf::class);
        self::assertInstanceOf(Docuconf::class, $docuconf);
        $result = $docuconf->check(['ORDERS_PORT' => '0', 'ORDERS_DATABASE_URL' => 'postgres://db/orders']);
        self::assertSame(['ORDERS_PORT:out_of_range'], array_map(fn ($v) => "{$v->input}:{$v->code}", $result->violations));
    }

    public function testDeclarationFile(): void
    {
        $file = dirname(__DIR__) . '/Fixtures/sample_gateway.php';
        $kernel = $this->kernel(['declaration' => $file, 'name' => 'ignored']);
        $docuconf = $kernel->getContainer()->get(Docuconf::class);
        self::assertInstanceOf(Docuconf::class, $docuconf);
        self::assertSame(file_get_contents(dirname(__DIR__) . '/golden/sample_gateway.cue'), $docuconf->export());
    }
}
