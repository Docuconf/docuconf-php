<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Declaration;
use Docuconf\Env;
use Docuconf\Secret;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/** A secret never shows up in PHP's normal debug printing, nor in an error. */
final class SecretsTest extends TestCase
{
    private const SECRET = 'postgres://orders:hunter2@db/orders';

    private static function declaration(): Declaration
    {
        $env = Env::declare('orders');
        $env->required('DATABASE_URL')->isUrl('postgres')->secret()->describe('Postgres connection string');
        $env->ifPresent('PORT')->isInteger()->default(8080)->describe('HTTP listen port');
        $env->ifPresent('LICENSE')->secret()->describe('Licence key');
        return $env;
    }

    /** @return array<string, array{\Closure(mixed): string}> */
    public static function printers(): array
    {
        return [
            'var_dump' => [static function (mixed $v): string {
                ob_start();
                var_dump($v);
                return (string) ob_get_clean();
            }],
            'print_r' => [static fn (mixed $v): string => print_r($v, true)],
            'var_export' => [static fn (mixed $v): string => var_export($v, true)],
            'json_encode' => [static fn (mixed $v): string => (string) json_encode($v)],
            'VarDumper (Laravel dump/dd)' => [static function (mixed $v): string {
                $out = fopen('php://memory', 'r+');
                self::assertIsResource($out);
                (new CliDumper($out))->dump((new VarCloner())->cloneVar($v));
                rewind($out);
                return (string) stream_get_contents($out);
            }],
        ];
    }

    /** @param \Closure(mixed): string $print */
    #[\PHPUnit\Framework\Attributes\DataProvider('printers')]
    public function testValuesNeverPrintASecret(\Closure $print): void
    {
        $result = self::declaration()->check(['DATABASE_URL' => self::SECRET, 'LICENSE' => 'lic-hunter2']);
        foreach ([$result->values, $result, $result->values->secret('DATABASE_URL')] as $subject) {
            $out = $print($subject);
            self::assertStringNotContainsString('hunter2', $out);
        }
        // var_export() cannot be redirected, so it shows no values at all.
        if (!str_contains($print([1]), 'array (')) {
            self::assertStringContainsString('***', $print($result->values));
        }
    }

    public function testDebugOutputIsTheRedactedValuesOnly(): void
    {
        $values = self::declaration()->load(['DATABASE_URL' => self::SECRET]);
        self::assertSame(['DATABASE_URL' => '***', 'PORT' => 8080, 'LICENSE' => null], $values->__debugInfo());
        self::assertLessThan(15, substr_count(print_r($values, true), "\n"), 'print_r shows the values, not the contract');
    }

    public function testValuesAndSecretsCannotBeSerialized(): void
    {
        $values = self::declaration()->load(['DATABASE_URL' => self::SECRET]);
        foreach ([$values, $values->secret('DATABASE_URL')] as $subject) {
            try {
                serialize($subject);
                self::fail('serialize() succeeded');
            } catch (\LogicException $e) {
                self::assertStringNotContainsString('hunter2', $e->getMessage());
            }
        }
    }

    public function testSecretWrapper(): void
    {
        $values = self::declaration()->load(['DATABASE_URL' => self::SECRET]);
        $secret = $values->secret('DATABASE_URL');
        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame(self::SECRET, $secret->reveal());
        self::assertSame('***', (string) $secret);
        self::assertSame('url=***', "url=$secret");
        self::assertNull($values->secret('LICENSE'));
        $this->expectExceptionMessage('PORT is not declared secret()');
        $values->secret('PORT');
    }

    public function testSecretsStayOutOfErrors(): void
    {
        $result = self::declaration()->check(['DATABASE_URL' => 'mysql://u:hunter2@db/x']);
        self::assertFalse($result->ok());
        self::assertStringNotContainsString('hunter2', (string) $result->violations[0]);
        self::assertStringNotContainsString('hunter2', print_r($result, true));
    }

    public function testSecretsStayOutOfBindErrors(): void
    {
        $values = self::declaration()->load(['DATABASE_URL' => self::SECRET]);
        try {
            $values->bind(Fixtures\WrongTypes::class);
            self::fail('bind succeeded');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot bind to', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }
}
