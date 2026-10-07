<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Declaration;
use Docuconf\Laravel\Env as LaravelEnv;
use Docuconf\Symfony\Docuconf;
use Docuconf\Symfony\DocuconfBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs README.md, so it cannot drift from the code:
 *
 * - every ```php block must compile (php -l);
 * - blocks whose first comment names a file (`// config/env.php`) are written
 *   to a scratch project, which then runs the quickstart: the PHPUnit test
 *   from "Test your config", and every ```console block marked
 *   `<!-- readme-test: run -->`, whose output must match exactly;
 * - the Laravel config file, the Symfony YAML and the file-input
 *   declaration are loaded and checked.
 */
final class ReadmeTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/docuconf-readme-' . bin2hex(random_bytes(4));
        $root = dirname(__DIR__);
        @mkdir(self::$dir . '/vendor/bin', 0777, true);
        file_put_contents(self::$dir . '/vendor/autoload.php', "<?php\nreturn require " . var_export("$root/vendor/autoload.php", true) . ";\n");
        // What Composer's bin proxy does for vendor/bin/docuconf.
        file_put_contents(self::$dir . '/vendor/bin/docuconf', "#!/usr/bin/env php\n<?php\n\$GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';\ninclude " . var_export("$root/bin/docuconf", true) . ";\n");
        chmod(self::$dir . '/vendor/bin/docuconf', 0755);
        foreach (self::blocks('php') as $code) {
            $file = self::fileName($code);
            // The quickstart comes first; a framework's file of the same name is only linted.
            if ($file !== null && !is_file(self::$dir . '/' . $file)) {
                @mkdir(dirname(self::$dir . '/' . $file), 0777, true);
                file_put_contents(self::$dir . '/' . $file, $code);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf ' . escapeshellarg(self::$dir));
    }

    /** @return list<string> the bodies of the README's ```$lang blocks */
    private static function blocks(string $lang, bool $markedOnly = false): array
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        preg_match_all('/(<!-- readme-test: run -->\n)?```' . $lang . '\n(.*?)```/s', $readme, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            if (!$markedOnly || $match[1] !== '') {
                $out[] = $match[2];
            }
        }
        return $out;
    }

    /** "config/env.php" for a block whose first comment line names a file. */
    private static function fileName(string $code): ?string
    {
        return preg_match('~^(?:<\?php\n)?// ([\w/.-]+\.php)\n~', $code, $m) ? $m[1] : null;
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        $env = ['PATH' => dirname(PHP_BINARY) . ':/usr/bin:/bin', 'HOME' => self::$dir];
        $proc = proc_open(['bash', '-c', $command . ' 2>&1'], [1 => ['pipe', 'w']], $pipes, self::$dir, $env);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        return [proc_close($proc), $out];
    }

    public function testEveryPhpBlockCompiles(): void
    {
        $blocks = self::blocks('php');
        self::assertGreaterThan(10, count($blocks));
        foreach ($blocks as $i => $code) {
            $file = self::$dir . "/lint-$i.php";
            file_put_contents($file, str_starts_with($code, '<?php') ? $code : "<?php\n$code");
            [$status, $out] = self::shell(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file));
            self::assertSame(0, $status, "README php block $i does not compile:\n$code\n$out");
            unlink($file);
        }
    }

    public function testQuickstartConsoleBlocks(): void
    {
        $blocks = self::blocks('console', true);
        self::assertGreaterThanOrEqual(4, count($blocks));
        @unlink(self::$dir . '/contract.cue');
        foreach ($blocks as $block) {
            $command = null;
            $expected = '';
            foreach (explode("\n", rtrim($block, "\n")) as $line) {
                if (str_starts_with($line, '$ ')) {
                    if ($command !== null) {
                        $this->assertCommand($command, $expected);
                    }
                    $command = substr($line, 2);
                    $expected = '';
                } else {
                    $expected .= $line . "\n";
                }
            }
            if ($command !== null) {
                $this->assertCommand($command, $expected);
            }
        }
    }

    private function assertCommand(string $command, string $expected): void
    {
        $shell = preg_replace('/(^|\s)php /', '$1' . escapeshellarg(PHP_BINARY) . ' ', $command);
        [$status, $out] = self::shell((string) $shell);
        self::assertSame($expected, $out, "README: \$ $command (exit $status)");
        $failing = str_contains($expected, 'configuration problem');
        self::assertSame($failing ? 1 : 0, $status, "README: \$ $command");
    }

    public function testQuickstartTest(): void
    {
        $phpunit = dirname(__DIR__) . '/vendor/bin/phpunit';
        [$status, $out] = self::shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
            . ' --no-configuration --bootstrap vendor/autoload.php tests/ConfigTest.php');
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('OK (2 tests', $out);
    }

    public function testLaravelConfigFile(): void
    {
        LaravelEnv::flush();
        try {
            /** @var array<string, mixed> $config */
            $config = require self::$dir . '/config/orders.php';
            self::assertSame(8080, $config['port']);
            self::assertSame('info', $config['log_level']);
            $cue = LaravelEnv::declaration('orders')->export();
            self::assertStringContainsString('ORDERS_LOG_LEVEL: {', $cue);
        } finally {
            LaravelEnv::flush();
        }
    }

    public function testSymfonyYaml(): void
    {
        $yaml = array_values(array_filter(self::blocks('yaml'), fn ($b) => str_contains($b, 'docuconf:')));
        self::assertCount(1, $yaml);
        /** @var array{docuconf: array<string, mixed>, parameters: array<string, string>} $doc */
        $doc = Yaml::parse($yaml[0]);
        $bundle = new DocuconfBundle();
        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);
        /** @var array{name: string, vars: array<string, mixed>} $config */
        $config = (new Processor())->processConfiguration($configuration, [$doc['docuconf']]);
        $docuconf = new Docuconf($config['name'], null, $config['vars']);
        $values = $docuconf->check(['DATABASE_URL' => 'postgresql://db/orders'])->orThrow();
        self::assertSame(8080, $values->int('PORT'));
        foreach ($doc['parameters'] as $param) {
            self::assertMatchesRegularExpression('/^%env\(docuconf(_seconds)?:[A-Z_]+\)%$/', $param);
        }
    }

    public function testFileInputDeclaration(): void
    {
        require_once self::$dir . '/src/RateLimits.php';
        require_once self::$dir . '/src/Routes.php';
        $env = require self::$dir . '/config/gateway.php';
        self::assertInstanceOf(Declaration::class, $env);
        $cue = $env->export();
        self::assertStringContainsString('"serving-tls": {', $cue);
        self::assertStringContainsString('routes: {', $cue);
    }
}
