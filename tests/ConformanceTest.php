<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\ConfigurationError;
use Docuconf\Contract;
use Docuconf\Export\Exporter;
use Docuconf\Files\LoadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared conformance suite (docuconf-go conformance/cases.json,
 * SPEC §12) through contract-first mode.
 *
 * The suite is found through DOCUCONF_CONFORMANCE, falling back to
 * ../docuconf-go/conformance/cases.json. When it is missing the test is
 * skipped, unless DOCUCONF_REQUIRE_CONFORMANCE=1, which fails it.
 *
 * A case is skipped only when it requires a tag outside SUPPORTED_TAGS,
 * including a tag this runner has never heard of (SPEC §12). This SDK
 * supports every tag, so testNothingIsSkipped fails on any skip when
 * DOCUCONF_REQUIRE_CONFORMANCE=1, as CI sets it.
 */
final class ConformanceTest extends TestCase
{
    /** The capability tags this SDK supports: all of them (conformance/README.md). */
    public const SUPPORTED_TAGS = [
        'int64', 'json-schema', 'key-set', 'deprecated', 'strict-parsing', 'files', 'profiles', 'overlays',
    ];

    private static function casesPath(): string
    {
        $path = getenv('DOCUCONF_CONFORMANCE');
        if (is_string($path) && $path !== '') {
            return $path;
        }
        return dirname(__DIR__, 2) . '/docuconf-go/conformance/cases.json';
    }

    /** @return list<array<string, mixed>>|null the cases, or null when the suite is missing */
    private static function suite(): ?array
    {
        $path = self::casesPath();
        if (!is_file($path)) {
            return null;
        }
        // Big ints stay exact: PHP ints are 64-bit, and the suite holds no wider ones.
        $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($doc['version'] ?? null) !== 1) {
            throw new \RuntimeException("$path: unsupported suite version " . json_encode($doc['version'] ?? null));
        }
        return $doc['cases'];
    }

    /**
     * @param array<string, mixed> $case
     * @return list<string> the tags it requires that this SDK lacks
     */
    private static function unsupported(array $case): array
    {
        return array_values(array_diff($case['requires'] ?? [], self::SUPPORTED_TAGS));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $cases = self::suite();
        if ($cases === null) {
            return ['suite missing' => [['missing' => self::casesPath()]]];
        }
        $out = [];
        foreach ($cases as $case) {
            $out[$case['id']] = [$case];
        }
        return $out;
    }

    private static function missingSuite(): void
    {
        if (getenv('DOCUCONF_REQUIRE_CONFORMANCE') === '1') {
            self::fail('conformance suite not found at ' . self::casesPath() . ' (DOCUCONF_REQUIRE_CONFORMANCE=1)');
        }
        self::markTestSkipped('conformance suite not found at ' . self::casesPath() . '; set DOCUCONF_CONFORMANCE');
    }

    /**
     * Every case runs: none requires a tag this SDK lacks. Skipping is
     * allowed by SPEC §12, but this SDK supports every tag, so in CI a skip
     * means a new tag, which needs support here.
     */
    public function testNothingIsSkipped(): void
    {
        $cases = self::suite();
        if ($cases === null) {
            self::missingSuite();
            return;
        }
        $skipped = [];
        foreach ($cases as $case) {
            foreach (self::unsupported($case) as $tag) {
                $skipped[$tag] = ($skipped[$tag] ?? 0) + 1;
            }
        }
        $n = count(array_filter($cases, fn (array $c) => self::unsupported($c) !== []));
        fwrite(STDERR, sprintf("\nconformance: %d cases, %d skipped%s\n", count($cases), $n, $skipped === [] ? '' : ' ' . json_encode($skipped)));
        if ($n > 0 && getenv('DOCUCONF_REQUIRE_CONFORMANCE') === '1') {
            self::fail("docuconf-php must run every case, but would skip $n: " . json_encode($skipped));
        }
        self::assertGreaterThan(0, count($cases), 'the suite holds no cases');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        if (isset($case['missing'])) {
            self::missingSuite();
        }
        $missing = self::unsupported($case);
        if ($missing !== []) {
            // An unknown tag is unsupported too: skip the case, never run it.
            self::markTestSkipped('requires ' . implode(', ', $missing));
        }
        $id = $case['id'] . ' (' . $case['source'] . ')';
        $contract = Contract::fromJson(json_encode($case['contract'], JSON_THROW_ON_ERROR));
        /** @var array<string, string> $env */
        $env = $case['env'];
        $root = self::tempDir();
        $log = tempnam(sys_get_temp_dir(), 'docuconf-term');
        try {
            // Files go under a fresh DOCUCONF_FILE_ROOT, set for every case,
            // so that no case reads the machine's own files.
            foreach ($case['files'] ?? [] as $path => $file) {
                $full = $root . $path;
                if (!is_dir(dirname($full))) {
                    mkdir(dirname($full), 0o755, true);
                }
                $data = match (true) {
                    isset($file['text']) => $file['text'],
                    isset($file['base64']) => base64_decode($file['base64'], true),
                    default => self::fail("$id: file $path has neither text nor base64"),
                };
                file_put_contents($full, $data);
            }
            $env['DOCUCONF_FILE_ROOT'] = $root;
            $env['DOCUCONF_TERMINATION_LOG'] = (string) $log;
            if (isset($case['errors'])) {
                self::expectErrors($id, $case, $contract, $env, (string) $log);
                return;
            }
            try {
                $values = $contract->load($env);
            } catch (ConfigurationError $e) {
                self::fail("$id: expected success, got\n" . $e->getMessage());
            }
            $vars = $case['contract']['vars'];
            $got = [];
            foreach (array_keys($vars) as $name) {
                $got[$name] = Exporter::plain($values->get((string) $name));
            }
            foreach (array_keys($case['contract']['files'] ?? []) as $name) {
                $got[$name] = self::fileValue($values->file((string) $name));
            }
            foreach ($case['expect'] as $name => $want) {
                self::assertArrayHasKey($name, $got, "$id: $name is missing from the result");
                $type = $vars[$name]['type'] ?? null;
                if ($type === 'float' && $want !== null) {
                    self::assertIsFloat($got[$name], "$id: $name");
                    self::assertEqualsWithDelta((float) $want, $got[$name], 1e-12, "$id: $name");
                } elseif ($type === 'json' || $type === null) {
                    // json values and config files compare as data, numbers by value.
                    self::assertSame(self::canonical($want), self::canonical($got[$name]), "$id: $name");
                } else {
                    self::assertSame($want, $got[$name], "$id: $name");
                }
            }
            $unexpected = array_diff(array_keys($got), array_keys($case['expect']));
            self::assertSame([], array_values($unexpected), "$id: in the result but not expected");
        } finally {
            @unlink((string) $log);
            self::remove($root);
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, string> $env
     */
    private static function expectErrors(string $id, array $case, Contract $contract, array $env, string $log): void
    {
        try {
            $contract->load($env);
            self::fail("$id: loading succeeded, expected errors " . json_encode($case['errors']));
        } catch (ConfigurationError $e) {
            $got = array_map(fn ($c) => $c['var'] . '/' . $c['code'], $e->codes());
            $want = array_map(fn ($c) => $c['var'] . '/' . $c['code'], $case['errors']);
            sort($got);
            sort($want);
            self::assertSame($want, array_values(array_unique($got)), "$id: wrong errors\n" . $e->getMessage());
            $written = (string) file_get_contents($log);
            foreach (self::secretValues($case) as $raw) {
                self::assertStringNotContainsString($raw, $e->getMessage(), "$id: a secret value leaked into the error");
                self::assertStringNotContainsString($raw, $written, "$id: a secret value leaked into the termination log");
            }
        }
    }

    /**
     * The raw env values of the case's secret variables, including the
     * items of indexed lists and key sets.
     *
     * @param array<string, mixed> $case
     * @return list<string>
     */
    private static function secretValues(array $case): array
    {
        $vars = $case['contract']['vars'];
        $out = [];
        foreach ($case['env'] as $name => $raw) {
            $base = explode('__', (string) $name, 2)[0];
            if ($raw !== '' && (($vars[$name]['secret'] ?? false) || ($vars[$base]['secret'] ?? false))) {
                $out[] = (string) $raw;
            }
        }
        return $out;
    }

    /** A file input as conformance JSON: a config file's data, a text file's text, else true; null when absent. */
    private static function fileValue(?LoadedFile $file): mixed
    {
        if ($file === null) {
            return null;
        }
        return $file->value ?? true;
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
            // Numbers compare by value: 3 equals 3.0.
            if (is_float($v) && floor($v) === $v && abs($v) < 2 ** 53) {
                return (int) $v;
            }
            return $v;
        };
        return json_encode($sort(json_decode((string) json_encode($v), true)), JSON_THROW_ON_ERROR);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/docuconf-conformance-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        return $dir;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("$path/$entry");
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
