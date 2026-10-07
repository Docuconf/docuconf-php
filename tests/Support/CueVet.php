<?php

declare(strict_types=1);

namespace Docuconf\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs `cue vet -c` on an exported contract against the docuconf meta-schema.
 *
 * The meta-schema comes from DOCUCONF_SPEC_CUE (a docuconf-go `spec/cue`
 * directory), falling back to ../docuconf-go/spec/cue; cue from PATH or
 * ~/go/bin. Without either the check is skipped, unless
 * DOCUCONF_REQUIRE_VET=1.
 */
final class CueVet
{
    public static function vet(string $cue): void
    {
        $spec = getenv('DOCUCONF_SPEC_CUE') ?: dirname(__DIR__, 3) . '/docuconf-go/spec/cue';
        $cueBin = self::cueBinary();
        $missing = !is_dir("$spec/contract") ? "meta-schema not found at $spec (set DOCUCONF_SPEC_CUE)" : ($cueBin === null ? 'cue not installed' : null);
        if ($missing !== null) {
            if (getenv('DOCUCONF_REQUIRE_VET') === '1') {
                Assert::fail($missing . ' (DOCUCONF_REQUIRE_VET=1)');
            }
            Assert::markTestSkipped($missing);
        }
        $dir = sys_get_temp_dir() . '/docuconf-vet-' . bin2hex(random_bytes(6));
        mkdir("$dir/svc", 0o777, true);
        try {
            exec('cp -r ' . escapeshellarg("$spec/cue.mod") . ' ' . escapeshellarg("$spec/contract") . ' ' . escapeshellarg($dir));
            file_put_contents("$dir/svc/contract.cue", $cue);
            exec('cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg((string) $cueBin) . ' vet -c ./svc 2>&1', $out, $code);
            Assert::assertSame(0, $code, "cue vet -c failed:\n" . implode("\n", $out));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private static function cueBinary(): ?string
    {
        foreach ([trim((string) shell_exec('command -v cue 2>/dev/null')), (getenv('HOME') ?: '/root') . '/go/bin/cue'] as $c) {
            if ($c !== '' && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }
}
