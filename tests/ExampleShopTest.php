<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shop example (examples/shop): roles (SPEC §4.9), requiredIf (SPEC
 * §4.2.1) and an included fragment, otel@1 (SPEC §4.8), loaded contract-first
 * from the example's contract.json. Each case checks the violations, and runs
 * App\Shop::main() in a PHP process for its output and exit code.
 */
final class ExampleShopTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../examples/shop';
    private const BASE = ['DATABASE_URL' => 'postgres://shop@db:5432/shop'];
    private const KEY_PATH = '/etc/shop/webhooks/signing.key';

    private string $root = '';

    public static function setUpBeforeClass(): void
    {
        require_once self::EXAMPLE . '/app/Shop.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/docuconf-shop-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . self::KEY_PATH)) {
            unlink($this->root . self::KEY_PATH);
            for ($dir = dirname($this->root . self::KEY_PATH); $dir !== $this->root; $dir = dirname($dir)) {
                rmdir($dir);
            }
        }
        rmdir($this->root);
    }

    /**
     * The README's matrix. `ok` cases give the line the service prints; the
     * others give each violation as `INPUT [code]` and a part of its message.
     * `{root}` stands for the case's file root.
     *
     * @return array<string, array{array<string, string>, bool, string|list<array{string, string}>}>
     */
    public static function cases(): array
    {
        $web = ['DOCUCONF_ROLE' => 'web', 'ALLOWED_ORIGINS' => 'https://shop.example'];
        $json = ['DOCUCONF_ROLE' => 'worker', 'QUEUES' => 'default', 'LOG_FORMAT' => 'json'];
        $webhooks = ['DOCUCONF_ROLE' => 'worker', 'QUEUES' => 'default', 'WEBHOOKS_ENABLED' => 'true'];
        return [
            '1 web' => [$web, false, 'shop web: port 8080, origins https://shop.example'],
            '2 worker needs no ALLOWED_ORIGINS' => [['DOCUCONF_ROLE' => 'worker', 'QUEUES' => 'default,mail'], false, 'shop worker: queues default,mail, webhooks off'],
            '3 web without ALLOWED_ORIGINS' => [['DOCUCONF_ROLE' => 'web'], false, [['ALLOWED_ORIGINS [missing_required]', 'required, but not set']]],
            '4 no role' => [['QUEUES' => 'default'], false, [['DOCUCONF_ROLE [missing_required]', 'required, but not set']]],
            '5 unknown role' => [['DOCUCONF_ROLE' => 'cron'], false, [['DOCUCONF_ROLE [not_in_enum]', 'must be one of: web, worker (got "cron")']]],
            '6 json logs without a schema' => [$json, false, [['LOG_SCHEMA_URL [missing_required]', 'is required when LOG_FORMAT is "json"']]],
            '7 json logs with a schema' => [$json + ['LOG_SCHEMA_URL' => 'https://logs.example/v1.json'], false, 'shop worker: queues default, webhooks off'],
            '8 webhooks without the key file' => [$webhooks, false, [['webhook-signing-key [file_missing]', '{root}' . self::KEY_PATH . ' does not exist, and is required when WEBHOOKS_ENABLED is true']]],
            '9 webhooks with the key file' => [$webhooks, true, 'shop worker: queues default, webhooks on'],
            '10 the key file belongs to the worker' => [$web + ['WEBHOOKS_ENABLED' => 'true'], false, 'shop web: port 8080, origins https://shop.example'],
            '11 fragment variables are checked' => [$web + ['OTEL_EXPORTER_OTLP_PROTOCOL' => 'carrier-pigeon'], false, [['OTEL_EXPORTER_OTLP_PROTOCOL [not_in_enum]', 'must be one of: grpc, http/protobuf, http/json (got "carrier-pigeon")']]],
        ];
    }

    /**
     * @param array<string, string> $env
     * @param string|list<array{string, string}> $want
     */
    #[DataProvider('cases')]
    public function testMatrix(array $env, bool $keyFile, string|array $want): void
    {
        if ($keyFile) {
            mkdir(dirname($this->root . self::KEY_PATH), 0o777, true);
            file_put_contents($this->root . self::KEY_PATH, str_repeat('k', 40));
        }
        $env = self::BASE + $env + ['DOCUCONF_FILE_ROOT' => $this->root];
        $result = \App\Shop::contract()->check($env);
        [$code, $stdout, $stderr] = self::runShop($env);

        if (is_string($want)) {
            self::assertSame([], array_map(fn (Violation $v) => (string) $v, $result->violations));
            self::assertSame($want, \App\Shop::summary($result->values));
            self::assertSame([0, $want . "\n"], [$code, $stdout]);
            return;
        }
        $want = array_map(fn (array $w) => [$w[0], str_replace('{root}', $this->root, $w[1])], $want);
        self::assertSame(
            array_map(fn (array $w) => $w[0], $want),
            array_map(fn (Violation $v) => "$v->input [$v->code]", $result->violations),
        );
        foreach ($want as $i => [, $message]) {
            self::assertStringContainsString($message, $result->violations[$i]->message);
        }
        $report = sprintf("docuconf: %d configuration problem%s:\n", count($want), count($want) === 1 ? '' : 's');
        foreach ($result->violations as $v) {
            $report .= "  - $v\n";
        }
        self::assertSame([1, '', $report], [$code, $stdout, $stderr]);
    }

    public function testSecretsAreNeverPrinted(): void
    {
        $env = ['DATABASE_URL' => 'mysql://shop:hunter2@db/shop', 'DOCUCONF_ROLE' => 'web', 'ALLOWED_ORIGINS' => 'https://shop.example', 'DOCUCONF_FILE_ROOT' => $this->root];
        [$code, $stdout, $stderr] = self::runShop($env);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('DATABASE_URL [invalid_scheme]', $stderr);
        self::assertStringNotContainsString('hunter2', $stderr);
    }

    public function testTheContractIsTheCanonicalOne(): void
    {
        $contract = \App\Shop::contract();
        self::assertSame(['otel@1'], $contract->includes());
        self::assertSame(['web', 'worker'], $contract->roles());
    }

    /**
     * Runs App\Shop::main() in a new PHP process with exactly $env.
     *
     * @param array<string, string> $env
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private static function runShop(array $env): array
    {
        $code = sprintf('require %s; require %s; exit(App\Shop::main());', var_export(__DIR__ . '/../vendor/autoload.php', true), var_export(self::EXAMPLE . '/app/Shop.php', true));
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $stdout, $stderr];
    }
}
