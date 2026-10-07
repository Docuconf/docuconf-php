<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\ConfigurationError;
use Docuconf\Contract;
use Docuconf\Export\Exporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared conformance suite (docuconf-go conformance/cases.json,
 * SPEC §12) through contract-first mode.
 *
 * The suite is found through DOCUCONF_CONFORMANCE, falling back to
 * ../docuconf-go/conformance/cases.json. When it is missing the test is
 * skipped, unless DOCUCONF_REQUIRE_CONFORMANCE=1, which fails it.
 */
final class ConformanceTest extends TestCase
{
    /** Capability tags this SDK lacks (SPEC §12). PHP has 64-bit ints and a JSON Schema validator. */
    private const UNSUPPORTED_TAGS = [];

    private static function casesPath(): string
    {
        $path = getenv('DOCUCONF_CONFORMANCE');
        if (is_string($path) && $path !== '') {
            return $path;
        }
        return dirname(__DIR__, 2) . '/docuconf-go/conformance/cases.json';
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $path = self::casesPath();
        if (!is_file($path)) {
            return ['suite missing' => [['missing' => $path]]];
        }
        $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($doc['cases'] as $case) {
            $out[$case['id']] = [$case];
        }
        return $out;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        if (isset($case['missing'])) {
            if (getenv('DOCUCONF_REQUIRE_CONFORMANCE') === '1') {
                self::fail("conformance suite not found at {$case['missing']} (DOCUCONF_REQUIRE_CONFORMANCE=1)");
            }
            self::markTestSkipped("conformance suite not found at {$case['missing']}; set DOCUCONF_CONFORMANCE");
        }
        $missing = array_intersect($case['requires'] ?? [], self::UNSUPPORTED_TAGS);
        if ($missing !== []) {
            self::markTestSkipped('requires ' . implode(', ', $missing));
        }
        $id = $case['id'];
        $contract = Contract::fromJson(json_encode($case['contract'], JSON_THROW_ON_ERROR));
        /** @var array<string, string> $env */
        $env = $case['env'];
        $log = tempnam(sys_get_temp_dir(), 'docuconf-term');
        $env['DOCUCONF_TERMINATION_LOG'] = (string) $log;
        try {
            if (isset($case['errors'])) {
                try {
                    $contract->load($env);
                    self::fail("$id: loading succeeded, expected errors " . json_encode($case['errors']));
                } catch (ConfigurationError $e) {
                    $got = array_map(fn ($c) => $c['var'] . '/' . $c['code'], $e->codes());
                    $want = array_map(fn ($c) => $c['var'] . '/' . $c['code'], $case['errors']);
                    sort($got);
                    sort($want);
                    self::assertSame($want, $got, "$id: wrong errors\n" . $e->getMessage());
                    $written = (string) file_get_contents((string) $log);
                    foreach ($case['contract']['vars'] as $name => $var) {
                        $raw = $case['env'][$name] ?? '';
                        if (($var['secret'] ?? false) && $raw !== '') {
                            self::assertStringNotContainsString($raw, $e->getMessage(), "$id: secret $name leaked");
                            self::assertStringNotContainsString($raw, $written, "$id: secret $name leaked to the termination log");
                        }
                    }
                }
                return;
            }
            try {
                $values = $contract->load($env);
            } catch (ConfigurationError $e) {
                self::fail("$id: expected success, got\n" . $e->getMessage());
            }
            foreach ($case['expect'] as $name => $want) {
                $got = Exporter::plain($values->get($name));
                $type = $case['contract']['vars'][$name]['type'];
                if ($type === 'float' && $want !== null) {
                    self::assertIsFloat($got, "$id: $name");
                    self::assertEqualsWithDelta((float) $want, $got, 1e-12, "$id: $name");
                } elseif ($type === 'json') {
                    self::assertSame(self::canonical($want), self::canonical($got), "$id: $name");
                } else {
                    self::assertSame($want, $got, "$id: $name");
                }
            }
        } finally {
            @unlink((string) $log);
        }
    }

    private static function canonical(mixed $v): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (is_array($v)) {
                if (!array_is_list($v)) {
                    ksort($v);
                }
                return array_map($sort, $v);
            }
            return $v;
        };
        return json_encode($sort(json_decode((string) json_encode($v), true)), JSON_THROW_ON_ERROR);
    }
}
