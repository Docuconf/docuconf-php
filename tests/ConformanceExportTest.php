<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Declaration;
use Docuconf\Tests\Support\CueVet;
use PHPUnit\Framework\TestCase;

/**
 * The shared export check (SPEC §11.2 item 3, §12): the fixture of
 * docuconf-go conformance/export, declared in tests/Fixtures/conformance_export.php,
 * exports to a contract that `docuconf conformance export` finds equal to
 * conformance/export/golden.cue.
 *
 * The golden file is found next to the conformance suite (DOCUCONF_CONFORMANCE,
 * falling back to ../docuconf-go), and the docuconf CLI through DOCUCONF_CLI
 * or PATH. Without either the test is skipped, unless
 * DOCUCONF_REQUIRE_CONFORMANCE=1, which fails it.
 */
final class ConformanceExportTest extends TestCase
{
    private static function fixture(): Declaration
    {
        return require __DIR__ . '/Fixtures/conformance_export.php';
    }

    private static function golden(): string
    {
        $cases = getenv('DOCUCONF_CONFORMANCE');
        $dir = is_string($cases) && $cases !== '' ? dirname($cases) : dirname(__DIR__, 2) . '/docuconf-go/conformance';
        return "$dir/export/golden.cue";
    }

    private static function cli(): ?string
    {
        $cli = getenv('DOCUCONF_CLI');
        if (is_string($cli) && $cli !== '') {
            return $cli;
        }
        foreach ([trim((string) shell_exec('command -v docuconf 2>/dev/null')), (getenv('HOME') ?: '/root') . '/go/bin/docuconf'] as $c) {
            // Another tool may be called docuconf; docuconf-go's has the conformance command.
            if ($c !== '' && is_executable($c)) {
                exec(escapeshellarg($c) . ' conformance export --help >/dev/null 2>&1', $out, $code);
                if ($code === 0) {
                    return $c;
                }
            }
        }
        return null;
    }

    private static function require(?string $missing): void
    {
        if ($missing === null) {
            return;
        }
        if (getenv('DOCUCONF_REQUIRE_CONFORMANCE') === '1') {
            self::fail("$missing (DOCUCONF_REQUIRE_CONFORMANCE=1)");
        }
        self::markTestSkipped($missing);
    }

    public function testFixtureMatchesTheSharedGolden(): void
    {
        $golden = self::golden();
        $cli = self::cli();
        self::require(!is_file($golden) ? "golden contract not found at $golden; set DOCUCONF_CONFORMANCE" : null);
        self::require($cli === null ? 'the docuconf CLI is not installed; set DOCUCONF_CLI' : null);
        $exported = tempnam(sys_get_temp_dir(), 'docuconf-export') . '.cue';
        file_put_contents($exported, self::fixture()->export());
        try {
            exec(escapeshellarg((string) $cli) . ' conformance export --golden ' . escapeshellarg($golden) . ' ' . escapeshellarg($exported) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "docuconf conformance export found differences:\n" . implode("\n", $out));
        } finally {
            @unlink($exported);
        }
    }

    public function testFixturePassesTheMetaSchema(): void
    {
        CueVet::vet(self::fixture()->export());
    }
}
