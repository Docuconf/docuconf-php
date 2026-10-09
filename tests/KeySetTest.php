<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\ConfigurationError;
use Docuconf\Console;
use Docuconf\DeclarationError;
use Docuconf\Env;
use Docuconf\KeySet;
use Docuconf\Secret;
use PHPUnit\Framework\TestCase;

/** The keySet type (SPEC §4.3, §6.1) in declaration mode, and deprecated inputs at boot (SPEC §4.2). */
final class KeySetTest extends TestCase
{
    private const OLD = 'old-webhook-key-0123456789abcdef0123';
    private const NEW = 'new-webhook-key-0123456789abcdef0123';

    private static function declaration(): \Docuconf\Declaration
    {
        $env = Env::declare('svc');
        $env->ifPresent('WEBHOOK_KEYS')->isKeySet()->keyMinLength(32)->keyMaxLength(256)->describe('Keys that verify webhook signatures');
        $env->ifPresent('API_KEYS')->isKeySet('json')->maxKeys(3)->describe('Keys that callers present');
        $env->ifPresent('SIGNING_KEYS')->isKeySet('indexed')->describe('Keys that verify session cookies');
        return $env;
    }

    public function testExportsAsAKeySet(): void
    {
        $cue = self::declaration()->export();
        self::assertStringContainsString(<<<'CUE'
                    WEBHOOK_KEYS: {
                        type:         "keySet"
                        description:  "Keys that verify webhook signatures"
                        secret:       true
                        encoding:     "csv"
                        separator:    ","
                        minKeys:      1
                        maxKeys:      2
                        keyMinLength: 32
                        keyMaxLength: 256
                    }
            CUE, str_replace("\t", '    ', $cue));
    }

    public function testKeysInOrderAndHelpers(): void
    {
        $values = self::declaration()->load([
            'WEBHOOK_KEYS' => self::OLD . ',' . self::NEW,
            'API_KEYS' => '["a","b,c"]',
            'SIGNING_KEYS__0' => 'cookie-old',
            'SIGNING_KEYS__1' => 'cookie-new',
        ]);
        $keys = $values->keySet('WEBHOOK_KEYS');
        self::assertInstanceOf(KeySet::class, $keys);
        self::assertSame([self::OLD, self::NEW], $keys->reveal());
        self::assertCount(2, $keys);
        self::assertContainsOnlyInstancesOf(Secret::class, $keys->keys());
        self::assertSame(self::NEW, $keys->keys()[1]->reveal());
        self::assertTrue($keys->contains(self::OLD));
        self::assertTrue($keys->contains(self::NEW));
        self::assertFalse($keys->contains('old-webhook-key'));
        self::assertFalse($keys->contains(''));
        self::assertSame(['a', 'b,c'], $values->keySet('API_KEYS')?->reveal());
        self::assertSame(['cookie-old', 'cookie-new'], $values->keySet('SIGNING_KEYS')?->reveal());

        $body = '{"order": 1}';
        $signature = hash_hmac('sha256', $body, self::NEW);
        $tried = [];
        $ok = $keys->verify(function (string $key) use ($body, $signature, &$tried): bool {
            $tried[] = $key;
            return hash_equals(hash_hmac('sha256', $body, $key), $signature);
        });
        self::assertTrue($ok);
        self::assertSame([self::OLD, self::NEW], $tried, 'verify tries every key');
        $tried = [];
        $signature = hash_hmac('sha256', $body, self::OLD);
        self::assertTrue($keys->verify(function (string $key) use ($body, $signature, &$tried): bool {
            $tried[] = $key;
            return hash_equals(hash_hmac('sha256', $body, $key), $signature);
        }));
        self::assertCount(2, $tried, 'verify does not stop at the first match');
        self::assertFalse($keys->verify(fn (string $key) => false));
    }

    public function testNeverPrintsAKey(): void
    {
        $values = self::declaration()->load(['WEBHOOK_KEYS' => self::OLD . ',' . self::NEW]);
        $keys = $values->keySet('WEBHOOK_KEYS');
        $printed = (string) $keys . json_encode($keys) . print_r($keys, true) . var_export($keys, true)
            . json_encode($values) . print_r($values, true);
        ob_start();
        var_dump($keys, $values);
        $printed .= (string) ob_get_clean();
        self::assertStringNotContainsString('webhook-key', $printed);
        self::assertStringContainsString('***', $printed);
        self::assertSame('***', $values->redacted()['WEBHOOK_KEYS']);
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function badKeys(): iterable
    {
        yield 'a stray separator' => [['WEBHOOK_KEYS' => self::OLD . ','], 'WEBHOOK_KEYS', 'out_of_range'];
        yield 'a truncated key' => [['WEBHOOK_KEYS' => self::OLD . ',new-webhook-key'], 'WEBHOOK_KEYS', 'out_of_range'];
        yield 'a third key' => [['WEBHOOK_KEYS' => self::OLD . ',' . self::NEW . ',' . self::OLD], 'WEBHOOK_KEYS', 'too_many_items'];
        yield 'an empty json key' => [['API_KEYS' => '["key-one",""]'], 'API_KEYS', 'out_of_range'];
        yield 'no keys' => [['API_KEYS' => '[]'], 'API_KEYS', 'too_few_items'];
        yield 'not an array' => [['API_KEYS' => '{"k": "key-one"}'], 'API_KEYS', 'invalid_type'];
        yield 'a gap' => [['SIGNING_KEYS__0' => 'cookie-old', 'SIGNING_KEYS__2' => 'cookie-new'], 'SIGNING_KEYS', 'invalid_type'];
    }

    /** @param array<string, string> $env */
    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testErrorsNeverShowAKey(array $env, string $name, string $code): void
    {
        try {
            self::declaration()->load($env);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $e) {
            self::assertSame([['var' => $name, 'code' => $code]], $e->codes());
            foreach ($env as $raw) {
                self::assertStringNotContainsString($raw, $e->getMessage());
            }
            self::assertStringNotContainsString('webhook-key', $e->getMessage());
            self::assertStringNotContainsString('cookie-', $e->getMessage());
        }
    }

    /** @return iterable<string, array{\Closure(\Docuconf\Declaration): mixed, string}> */
    public static function badDeclarations(): iterable
    {
        yield 'not secret' => [fn ($d) => $d->ifPresent('K')->isKeySet()->secret(false)->describe('Some keys'), 'K: a keySet is always secret'];
        yield 'a default' => [fn ($d) => $d->ifPresent('K')->isKeySet()->default(['a'])->describe('Some keys'), 'K: a secret cannot have a default'];
        yield 'no keys allowed' => [fn ($d) => $d->ifPresent('K')->isKeySet()->minKeys(0)->describe('Some keys'), 'K: minKeys must be at least 1'];
        yield 'max below min' => [fn ($d) => $d->ifPresent('K')->isKeySet()->minKeys(3)->describe('Some keys'), 'K: maxKeys must be at least minKeys (3)'];
        yield 'zero key length' => [fn ($d) => $d->ifPresent('K')->isKeySet()->keyMaxLength(0)->describe('Some keys'), 'K: keyMaxLength must be at least 1'];
        yield 'keys on a list' => [fn ($d) => $d->ifPresent('L')->isList()->maxKeys(3)->describe('Some items'), 'L: maxKeys does not apply to a list variable'];
        yield 'required and deprecated' => [fn ($d) => $d->required('OLD')->deprecated('Use NEW', 'NEW')->describe('An old input'), 'OLD: a required input cannot be deprecated'];
        yield 'a blank deprecation message' => [fn ($d) => $d->ifPresent('OLD')->deprecated(' ')->describe('An old input'), 'OLD: deprecated needs a message'];
        yield 'a long deprecation message' => [fn ($d) => $d->ifPresent('OLD')->deprecated(str_repeat('x', 501))->describe('An old input'), 'at most 500 are allowed'];
        yield 'a required deprecated file' => [fn ($d) => $d->text('old-licence', '/etc/svc/licence/key')->required()->deprecated('Use licence')->describe('Old licence key'), 'file old-licence: a required input cannot be deprecated'];
    }

    /** @param \Closure(\Docuconf\Declaration): mixed $declare */
    #[\PHPUnit\Framework\Attributes\DataProvider('badDeclarations')]
    public function testDeclarationMistakes(\Closure $declare, string $expected): void
    {
        $env = Env::declare('svc');
        $declare($env);
        try {
            $env->spec();
            self::fail('expected a DeclarationError');
        } catch (DeclarationError $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testDeprecatedInputWarnsAtBootWithoutItsValue(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('OLD_TOKEN')->secret()->deprecated('The billing API no longer takes a token')->describe('Token of the retired billing API');
        $env->ifPresent('OLD_PORT')->isInteger()->deprecated('Use PORT instead', 'PORT')->describe('Old name of the listen port');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        Console::$stderr = $stderr;
        putenv('OLD_TOKEN=tok-0123456789');
        putenv('OLD_PORT=9090');
        try {
            $values = $env->load();
            self::assertSame(9090, $values->int('OLD_PORT'));
        } finally {
            putenv('OLD_TOKEN');
            putenv('OLD_PORT');
            Console::$stderr = null;
        }
        rewind($stderr);
        $out = (string) stream_get_contents($stderr);
        self::assertStringContainsString('docuconf: warning: OLD_TOKEN is deprecated: The billing API no longer takes a token', $out);
        self::assertStringContainsString('docuconf: warning: OLD_PORT is deprecated: Use PORT instead (replaced by PORT)', $out);
        self::assertStringNotContainsString('tok-0123456789', $out);
        self::assertStringNotContainsString('9090', $out);
    }
}
