<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\ConfigurationError;
use Docuconf\Console;
use Docuconf\Declaration;
use Docuconf\Duration;
use Docuconf\Env;
use Docuconf\Secret;
use Docuconf\Tests\Fixtures\OrdersConfig;
use PHPUnit\Framework\TestCase;

/** loadOrExit(), the termination log, typo hints and binding to a class. */
final class BootTest extends TestCase
{
    /** @var resource */
    private $stderr;
    private ?int $exitCode = null;

    protected function setUp(): void
    {
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
        Console::$stderr = null;
        Console::$exitUsing = null;
    }

    private function stderr(): string
    {
        rewind($this->stderr);
        return (string) stream_get_contents($this->stderr);
    }

    private static function orders(): Declaration
    {
        $env = Env::declare('orders');
        $env->required('DATABASE_URL')->isUrl('postgres')->secret()->describe('Postgres connection string');
        $env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
        $env->ifPresent('REQUEST_TIMEOUT')->isDuration()->default('30s')->describe('Request timeout');
        $env->ifPresent('ALLOWED_ORIGINS')->isList()->default(['http://localhost:3000'])->describe('CORS origins');
        $env->ifPresent('REGION')->describe('Cloud region');
        return $env;
    }

    public function testLoadOrExitPrintsEveryProblemAndExits(): void
    {
        try {
            self::orders()->loadOrExit(['PORT' => '0', 'DATABASE_URL' => 'mysql://u:hunter2@db/x']);
        } catch (ConfigurationError) {
            // Reached only because the test's exit handler returns.
        }
        self::assertSame(1, $this->exitCode);
        self::assertSame(
            "docuconf: 2 configuration problems:\n"
            . "  - DATABASE_URL [invalid_scheme]: scheme must be one of: postgres\n"
            . "  - PORT [out_of_range]: must be at least 1 (got \"0\")\n",
            self::sortedProblems($this->stderr()),
        );
    }

    private static function sortedProblems(string $out): string
    {
        $lines = explode("\n", rtrim($out));
        $head = array_shift($lines);
        sort($lines);
        return $head . "\n" . implode("\n", $lines) . "\n";
    }

    public function testLoadOrExitReturnsValuesAndPrintsWarnings(): void
    {
        $values = self::orders()->loadOrExit(['DATABASE_URL' => 'postgres://db/x', 'PROT' => '9', 'DATABSE_URL' => 'x']);
        self::assertNull($this->exitCode);
        self::assertSame(8080, $values->int('PORT'));
        $err = $this->stderr();
        self::assertStringContainsString("docuconf: warning: PROT is set but not declared; did you mean PORT?\n", $err);
        self::assertStringContainsString("docuconf: warning: DATABSE_URL is set but not declared; did you mean DATABASE_URL?\n", $err);
    }

    public function testRealExitHasNoStackTrace(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . '$env = Docuconf\Env::declare("orders");'
            . '$env->required("DATABASE_URL")->isUrl("postgres")->secret()->describe("Postgres connection string");'
            . '$env->loadOrExit(["DATABASE_URL" => ""]); echo "unreachable";';
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(1, proc_close($proc));
        self::assertSame('', $out);
        self::assertSame("docuconf: 1 configuration problem:\n  - DATABASE_URL [missing_required]: required, but not set\n", $err);
    }

    public function testExplicitEnvMapSkipsTheDefaultTerminationLog(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'docuconf-term');
        try {
            try {
                self::orders()->load([]);
            } catch (ConfigurationError) {
            }
            self::assertSame('', (string) file_get_contents($log), 'nothing written without DOCUCONF_TERMINATION_LOG');
            try {
                self::orders()->load(['DOCUCONF_TERMINATION_LOG' => $log]);
            } catch (ConfigurationError) {
            }
            self::assertStringContainsString('DATABASE_URL [missing_required]', (string) file_get_contents($log));
        } finally {
            unlink($log);
        }
    }

    public function testTypoHintsNeverShowTheValueAndSkipUnrelatedNames(): void
    {
        $warnings = self::orders()->check([
            'DATABASE_URL' => 'postgres://db/x', 'DATABSE_URL' => 'postgres://u:hunter2@db/x',
            'HOME' => '/root', 'PATH' => '/bin', 'ALLOWED_ORIGINS__0' => 'x', 'DOCUCONF_FILE_ROOT' => '/x',
        ])->warnings;
        self::assertSame(['DATABSE_URL is set but not declared; did you mean DATABASE_URL?'], $warnings);
    }

    public function testBindToAReadonlyClass(): void
    {
        $values = self::orders()->load(['DATABASE_URL' => 'postgres://db/x', 'PORT' => '9000']);
        $config = $values->bind(OrdersConfig::class);
        self::assertSame(9000, $config->port);
        self::assertInstanceOf(Secret::class, $config->databaseUrl);
        self::assertSame('postgres://db/x', $config->databaseUrl->reveal());
        self::assertEquals(Duration::ofSeconds(30), $config->requestTimeout);
        self::assertSame(30, $config->timeoutInterval->s);
        self::assertSame(['http://localhost:3000'], $config->origins);
        self::assertNull($config->region);
    }

    public function testBindNamesAMissingVariable(): void
    {
        $env = Env::declare('orders');
        $env->ifPresent('PORT')->isInteger()->describe('HTTP listen port');
        $this->expectExceptionMessage("cannot bind to Docuconf\\Tests\\Fixtures\\OrdersConfig:\n  - \$port: PORT is not set and has no default; make the parameter nullable or give it a default\n  - \$databaseUrl: DATABASE_URL is not a declared variable");
        $env->load([])->bind(OrdersConfig::class);
    }
}
