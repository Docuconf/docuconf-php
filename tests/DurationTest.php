<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function parses(): iterable
    {
        yield ['go', '1m30s', '1m30s'];
        yield ['go', '90m', '1h30m'];
        yield ['go', '1.5h', '1h30m'];
        yield ['go', '1500ms', '1s500ms'];
        yield ['go', '1h0m0s', '1h'];
        yield ['go', '0', '0s'];
        yield ['go', '2us', '2us'];
        yield ['go', '1µs', '1us'];
        yield ['go', '-1m30s', '-1m30s'];
        yield ['go', '+5s', '5s'];
        yield ['go', '-0', '0s'];
        yield ['go', '.5s', '500ms'];
        yield ['iso8601', 'PT90S', '1m30s'];
        yield ['iso8601', 'PT1.5S', '1s500ms'];
        yield ['iso8601', 'P1DT2H', '26h'];
        yield ['iso8601', 'PT0S', '0s'];
        yield ['iso8601', 'PT1,5S', '1s500ms'];
        yield ['seconds', '0.25', '250ms'];
        yield ['seconds', '180000', '50h'];
        yield ['timespan', '00:01:30', '1m30s'];
        yield ['timespan', '1.02:03:04.5', '26h3m4s500ms'];
        yield ['timespan', '2.02:00:00', '50h'];
    }

    #[DataProvider('parses')]
    public function testParse(string $encoding, string $in, string $canonical): void
    {
        self::assertSame($canonical, Duration::parse($in, $encoding)->toString());
    }

    /** @return iterable<array{string, string}> */
    public static function rejects(): iterable
    {
        yield ['go', 'PT90S'];
        yield ['go', '90'];
        yield ['go', '5S'];
        yield ['go', '1d'];
        yield ['go', '1m 30s'];
        yield ['go', '5s\n'];
        yield ['go', '-'];
        yield ['go', ''];
        yield ['go', '.s'];
        yield ['go', '9999999999999999999h'];
        yield ['iso8601', '1m30s'];
        yield ['iso8601', 'P1M'];
        yield ['iso8601', 'PT'];
        yield ['iso8601', 'P1W'];
        yield ['iso8601', 'pt90s'];
        yield ['iso8601', '-PT5S'];
        yield ['seconds', '90s'];
        yield ['seconds', '-1'];
        yield ['seconds', '+5'];
        yield ['seconds', '5e1'];
        yield ['seconds', '.5'];
        yield ['seconds', "5\n"];
        yield ['timespan', '1m30s'];
        yield ['timespan', '00:60:00'];
        yield ['timespan', '01:30'];
        yield ['timespan', '24:00:00'];
        yield ['timespan', '-00:00:05'];
        yield ['timespan', '00:1:30'];
    }

    #[DataProvider('rejects')]
    public function testReject(string $encoding, string $in): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Duration::parse($in, $encoding);
    }

    public function testEncode(): void
    {
        $d = Duration::fromGo('26h3m4s500ms');
        self::assertSame('PT93784.5S', $d->encode('iso8601'));
        self::assertSame('93784.5', $d->encode('seconds'));
        self::assertSame('1.02:03:04.5', $d->encode('timespan'));
        self::assertSame('26h3m4s500ms', $d->encode('go'));
    }

    public function testVarExportRoundTrip(): void
    {
        $d = Duration::fromGo('1m30s');
        $copy = eval('return ' . var_export($d, true) . ';');
        self::assertEquals($d, $copy);
    }

    public function testConversions(): void
    {
        $d = Duration::fromGo('1m30s250ms');
        self::assertSame(90.25, $d->toSeconds());
        self::assertSame(90250, $d->toMilliseconds());
        self::assertSame(90, $d->toDateInterval()->s);
        self::assertSame('"1m30s250ms"', json_encode($d));
    }
}
