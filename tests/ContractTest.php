<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Contract;
use Docuconf\DeclarationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contract-first mode reads a contract strictly: `#Contract` is closed, so nothing is silently dropped. */
final class ContractTest extends TestCase
{
    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function contract(array $vars, array $extra = []): array
    {
        return array_replace(['apiVersion' => 'docuconf.dev/v1alpha1', 'kind' => 'ConfigContract', 'metadata' => ['name' => 'orders'], 'vars' => $vars], $extra);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badContracts(): iterable
    {
        yield 'typo in secret' => [self::contract(['S' => ['type' => 'string', 'description' => 'some secret', 'secert' => true]]),
            'S: unknown key "secert" in the variable; did you mean "secret"?'];
        yield 'typo in min' => [self::contract(['PORT' => ['type' => 'int', 'description' => 'HTTP listen port', 'minn' => 1]]),
            'PORT: unknown key "minn" in the variable; did you mean "min"?'];
        yield 'secret as a string' => [self::contract(['S' => ['type' => 'string', 'description' => 'some secret', 'secret' => 'no']]),
            'S: secret must be true or false, got "no"'];
        yield 'required as a string' => [self::contract(['S' => ['type' => 'string', 'description' => 'some value', 'required' => 'false']]),
            'S: required must be true or false, got "false"'];
        yield 'min as a string' => [self::contract(['N' => ['type' => 'int', 'description' => 'a number', 'min' => '1']]),
            'N: min must be an integer, got string'];
        yield 'schemes not a list' => [self::contract(['U' => ['type' => 'url', 'description' => 'a URL here', 'schemes' => 'https']]),
            'U: schemes must be a list of strings'];
        yield 'a variable that is not an object' => [self::contract(['PORT' => 8080]),
            'PORT: must be an object, got int'];
        yield 'unknown top-level key' => [self::contract([], ['varz' => []]),
            'unknown key "varz" in contract; did you mean "vars"?'];
        yield 'unknown metadata key' => [self::contract([], ['metadata' => ['name' => 'orders', 'labels' => []]]),
            'unknown key "labels" in metadata'];
        yield 'overlay typo' => [self::contract([], ['overlays' => ['platform' => ['format' => 'json', 'path' => '/app/config/p.json', 'keySeparator' => ':', 'relaod' => 'watch']]]),
            'overlay platform: unknown key "relaod" in the overlay; did you mean "reload"?'];
        yield 'overlay watch' => [self::contract([], ['overlays' => ['platform' => ['format' => 'json', 'path' => '/app/config/p.json', 'keySeparator' => ':', 'reload' => 'watch']]]),
            'overlay platform: reload "watch" is not supported for an overlay'];
        yield 'undeclared selector' => [self::contract([], ['profiles' => ['selector' => 'APP_ENV', 'default' => 'prod']]),
            'profiles.selector "APP_ENV" must be a declared variable'];
        yield 'secret profile default' => [self::contract(['APP_ENV' => ['type' => 'string', 'description' => 'the profile'], 'S' => ['type' => 'string', 'description' => 'some secret', 'secret' => true]], ['profiles' => ['selector' => 'APP_ENV', 'default' => 'prod', 'defaults' => ['prod' => ['S' => 'x']]]]),
            'profiles.defaults.prod.S: S is secret'];
        yield 'profile default out of range' => [self::contract(['APP_ENV' => ['type' => 'string', 'description' => 'the profile'], 'N' => ['type' => 'int', 'description' => 'a number', 'max' => 5]], ['profiles' => ['selector' => 'APP_ENV', 'default' => 'prod', 'defaults' => ['prod' => ['N' => 9]]]]),
            "profiles.defaults.prod.N: does not satisfy N's constraints"];
        yield 'keySet not secret' => [self::contract(['K' => ['type' => 'keySet', 'description' => 'some keys', 'secret' => false]]),
            'K: a keySet is always secret'];
        yield 'keySet maxKeys below minKeys' => [self::contract(['K' => ['type' => 'keySet', 'description' => 'some keys', 'minKeys' => 3]]),
            'K: maxKeys must be at least minKeys (3)'];
        yield 'keySet field on a list' => [self::contract(['L' => ['type' => 'list', 'description' => 'some items', 'maxKeys' => 3]]),
            'L: maxKeys does not apply to a list variable'];
        yield 'blank deprecation message' => [self::contract(['OLD' => ['type' => 'string', 'description' => 'old thing', 'deprecated' => ['message' => '  ']]]),
            'OLD: deprecated needs a message'];
        yield 'long deprecation message' => [self::contract(['OLD' => ['type' => 'string', 'description' => 'old thing', 'deprecated' => ['message' => str_repeat('é', 501)]]]),
            'OLD: deprecated.message is 501 characters; at most 500 are allowed'];
        yield 'required and deprecated' => [self::contract(['OLD' => ['type' => 'string', 'description' => 'old thing', 'required' => true, 'deprecated' => ['message' => 'Use NEW']]]),
            'OLD: a required input cannot be deprecated'];
        yield 'deprecated typo' => [self::contract(['OLD' => ['type' => 'string', 'description' => 'old thing', 'deprecated' => ['mesage' => 'x']]]),
            'OLD: unknown key "mesage" in deprecated; did you mean "message"?'];
        yield 'file key typo' => [self::contract([], ['files' => ['license' => ['type' => 'text', 'description' => 'Licence key', 'path' => '/etc/app/license/key', 'secert' => true]]]),
            'file license: unknown key "secert" in the file input; did you mean "secret"?'];
    }

    /** @param array<string, mixed> $contract */
    #[DataProvider('badContracts')]
    public function testUnknownKeysAndLooseTypesAreErrors(array $contract, string $expected): void
    {
        try {
            Contract::fromJson($contract);
            self::fail('expected a DeclarationError');
        } catch (DeclarationError $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testAValidContractLoads(): void
    {
        $json = (string) json_encode(self::contract([
            'PORT' => ['name' => 'PORT', 'type' => 'int', 'description' => 'HTTP listen port', 'required' => false, 'secret' => false, 'min' => 1, 'default' => 8080],
            'TIMEOUT' => ['type' => 'duration', 'description' => 'Request timeout', 'encoding' => 'iso8601', 'default' => '30s'],
        ], ['metadata' => ['name' => 'orders', 'generator' => ['language' => 'go', 'sdk' => 'x', 'version' => '1']]]));
        $values = Contract::fromJson($json)->load(['TIMEOUT' => 'PT1M']);
        self::assertSame(8080, $values->int('PORT'));
        self::assertSame(60.0, $values->duration('TIMEOUT')?->toSeconds());
    }
}
