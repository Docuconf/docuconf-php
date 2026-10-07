<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Re2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Re2Test extends TestCase
{
    /** @return iterable<array{string}> */
    public static function accepted(): iterable
    {
        foreach (
            [
            '^[a-z]{2}-[a-z]+-[0-9]$', '[0-9]{3}', 'a*?b+?c??', '(?i)abc', '(?P<year>[0-9]{4})', '(?<y>x)',
            '\pL+', '\p{Greek}', '[[:alpha:]]+', '\Qa.b\E', 'a{2,}', '\\\\1', '\012', '\x41\x{263A}', '[]a]', '\z', '(?s:.)',
            'a{1000}', 'x{,3}',
            ] as $p
        ) {
            yield $p => [$p];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $pattern): void
    {
        self::assertNull(Re2::check($pattern));
    }

    /** @return iterable<array{string, string}> */
    public static function rejected(): iterable
    {
        yield ['(a)\1', 'backreferences'];
        yield ['(?<n>a)\k<n>', 'backreferences'];
        yield ['a(?=b)', 'lookahead'];
        yield ['a(?!b)', 'lookahead'];
        yield ['(?<=a)b', 'lookbehind'];
        yield ['(?<!a)b', 'lookbehind'];
        yield ['(?>a+)', 'atomic'];
        yield ['a++', 'possessive'];
        yield ['a**', 'nested repetition'];
        yield ['(?R)', 'recursion'];
        yield ['(?(1)a|b)', 'conditionals'];
        yield ['(*UTF)a', 'verbs'];
        yield ['(?x) a', 'flags'];
        yield ['\Ga', '\G'];
        yield ['a\Z', '\Z'];
        yield ['a{1001}', 'above 1000'];
        yield ['[a', 'closing ]'];
        yield ['*a', 'missing argument'];
        yield ['\e', 'not RE2'];
    }

    #[DataProvider('rejected')]
    public function testRejected(string $pattern, string $why): void
    {
        self::assertStringContainsString($why, (string) Re2::check($pattern));
    }

    public function testPartialMatchSemantics(): void
    {
        self::assertTrue(Re2::matches('[0-9]{3}', 'x999y'));
        self::assertFalse(Re2::matches('^[a-z-]+$', 'My-Page'));
        // RE2's $ matches only at the very end; PCRE's would also match before a final newline.
        self::assertFalse(Re2::matches('^abc$', "abc\n"));
        self::assertTrue(Re2::matches('(?m)^abc$', "abc\n"));
        // \d, \w and \b are ASCII-only in RE2.
        self::assertFalse(Re2::matches('^\d$', '٣'));
        self::assertFalse(Re2::matches('^\w$', 'é'));
        self::assertTrue(Re2::matches('^.$', 'é'), '. matches a whole UTF-8 character');
        self::assertTrue(Re2::matches('\v', "\x0B"));
        self::assertFalse(Re2::matches('\v', "\n"));
        self::assertFalse(Re2::matches('a', "\xff"), 'invalid UTF-8 does not match');
    }
}
