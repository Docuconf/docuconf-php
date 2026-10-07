<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/docuconf finds the Composer autoloader however the package was
 * installed. CI's `package` job also installs it from a path repository,
 * where Composer symlinks it, and runs `vendor/bin/docuconf`.
 */
final class CliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/docuconf-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0777, true);
        // A copy, as a path install sees it: neither ../vendor nor ../../../autoload.php exists next to it.
        copy(dirname(__DIR__) . '/bin/docuconf', $this->dir . '/bin/docuconf');
        file_put_contents($this->dir . '/env.php', '<?php $env = Docuconf\Env::declare("orders");'
            . ' $env->ifPresent("PORT")->isInteger()->describe("HTTP listen port"); return $env;');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{int, string, string} */
    private function php(string $code): array
    {
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    public function testUsesTheAutoloaderComposerPoints(): void
    {
        // What Composer's vendor/bin proxy does (Composer 2.2+).
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        [$code, $out, $err] = $this->php("\$GLOBALS['_composer_autoload_path'] = $autoload; \$argv = ['docuconf', 'export', 'env.php']; include 'bin/docuconf';");
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("PORT: {", $out);
    }

    public function testFailsLoudlyWithoutAnAutoloader(): void
    {
        [$code, $out, $err] = $this->php("\$argv = ['docuconf', '--version']; include 'bin/docuconf';");
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('docuconf: cannot find the Composer autoloader (vendor/autoload.php); run composer install', $err);
        self::assertStringNotContainsString('Fatal', $err);
    }
}
