<?php

declare(strict_types=1);

namespace Docuconf\Tests\Symfony;

use Docuconf\ConfigurationError;
use Docuconf\Symfony\BootValidator;
use Docuconf\Symfony\Docuconf;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
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

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/docuconf-symfony-' . bin2hex(random_bytes(6));
        $this->argv = $_SERVER['argv'] ?? [];
        BootValidator::$exitUsing = function (int $code): void {
            $this->exitCode = $code;
        };
    }

    protected function tearDown(): void
    {
        foreach (['ORDERS_PORT', 'ORDERS_DATABASE_URL', 'ORDERS_TIMEOUT'] as $name) {
            putenv($name);
        }
        $_SERVER['argv'] = $this->argv;
        BootValidator::$exitUsing = null;
        exec('rm -rf ' . escapeshellarg($this->dir));
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

    public function testConsoleBootValidationForServingCommands(): void
    {
        putenv('ORDERS_PORT=0');
        $_SERVER['argv'] = ['bin/console', 'messenger:consume'];
        $this->kernel();
        self::assertSame(1, $this->exitCode);
    }

    public function testOtherCommandsDoNotValidate(): void
    {
        putenv('ORDERS_PORT=0');
        $_SERVER['argv'] = ['bin/console', 'cache:clear'];
        $this->kernel();
        self::assertNull($this->exitCode);
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

    public function testDeclarationFile(): void
    {
        $file = dirname(__DIR__) . '/Fixtures/sample_gateway.php';
        $kernel = $this->kernel(['declaration' => $file, 'name' => 'ignored']);
        $docuconf = $kernel->getContainer()->get(Docuconf::class);
        self::assertInstanceOf(Docuconf::class, $docuconf);
        self::assertSame(file_get_contents(dirname(__DIR__) . '/golden/sample_gateway.cue'), $docuconf->export());
    }
}
