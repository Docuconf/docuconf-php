<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\LoadResult;
use Docuconf\Laravel\Env;
use Docuconf\Symfony\Docuconf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The examples' webhook key set (SPEC §6.1): WEBHOOK_KEYS, declared in the
 * Laravel example's config/orders.php and the Symfony example's
 * docuconf.yaml, and App\Webhooks::verify, which accepts a signature made
 * with any key in the set.
 */
final class ExampleWebhooksTest extends TestCase
{
    private const OLD = 'oooooooooooooooooooooooooooooooo';
    private const NEW = 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn';
    private const BODY = '{"order":"42","status":"paid"}';
    private const EXAMPLES = __DIR__ . '/../examples';

    public static function setUpBeforeClass(): void
    {
        require_once self::EXAMPLES . '/orders/app/Webhooks.php';
    }

    protected function tearDown(): void
    {
        Env::flush();
    }

    private static function sign(string $key): string
    {
        return hash_hmac('sha256', self::BODY, $key);
    }

    /** @param list<string>|null $keys */
    private static function verify(?array $keys, ?string $signature): bool
    {
        return \App\Webhooks::verify($keys, self::BODY, $signature);
    }

    /** @return array<string, array{\Closure(string): LoadResult}> each example's boot check, given WEBHOOK_KEYS */
    public static function examples(): array
    {
        return [
            'laravel' => [static function (string $keys): LoadResult {
                $env = ['DATABASE_URL' => 'postgres://u:p@db/orders', 'WEBHOOK_KEYS' => $keys];
                Env::flush();
                require self::EXAMPLES . '/orders/config/orders.php';
                return Env::declaration('orders')->check($env);
            }],
            'symfony' => [static function (string $keys): LoadResult {
                $env = ['DATABASE_URL' => 'postgres://u:p@db/orders', 'WEBHOOK_KEYS' => $keys];
                /** @var array{docuconf: array{name: string, vars: array<string, array<string, mixed>>}} $yaml */
                $yaml = Yaml::parseFile(self::EXAMPLES . '/orders-symfony/config/packages/docuconf.yaml');
                return (new Docuconf($yaml['docuconf']['name'], null, $yaml['docuconf']['vars'], []))->check($env);
            }],
        ];
    }

    /**
     * A rotation: each step is a rollout with a new WEBHOOK_KEYS, and a
     * webhook signed with the key in use always verifies.
     *
     * @param \Closure(string): LoadResult $check
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('examples')]
    public function testARotationNeverTurnsAwayAWebhook(\Closure $check): void
    {
        $steps = [
            'before' => [self::OLD, true, false],
            'overlap' => [self::OLD . ',' . self::NEW, true, true],
            'after' => [self::NEW, false, true],
        ];
        foreach ($steps as $step => [$value, $old, $new]) {
            $result = $check($value);
            self::assertSame([], array_map('strval', $result->violations), $step);
            /** @var list<string>|null $keys */
            $keys = $result->values->list('WEBHOOK_KEYS');
            self::assertSame($old, self::verify($keys, self::sign(self::OLD)), "$step: old key");
            self::assertSame($new, self::verify($keys, self::sign(self::NEW)), "$step: new key");
            self::assertFalse(self::verify($keys, self::sign(str_repeat('x', 32))), "$step: another key");
            self::assertSame('***', $result->values->redacted()['WEBHOOK_KEYS']);
        }
        self::assertFalse(self::verify([self::OLD], 'not hex'));
        self::assertFalse(self::verify([self::OLD], null));
        self::assertFalse(self::verify(null, self::sign(self::OLD)), 'no keys configured');
    }

    /**
     * An empty or truncated key, or a third key, fails the boot check
     * without printing any key.
     *
     * @param \Closure(string): LoadResult $check
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('examples')]
    public function testABadKeySetFailsAtBoot(\Closure $check): void
    {
        $cases = [
            [self::OLD . ',', 'out_of_range'],
            [self::OLD . ',' . substr(self::NEW, 0, 10), 'out_of_range'],
            [self::OLD . ',' . self::NEW . ',' . str_repeat('x', 32), 'too_many_items'],
        ];
        foreach ($cases as [$value, $code]) {
            $violations = $check($value)->violations;
            self::assertSame(["WEBHOOK_KEYS $code"], array_map(fn ($v) => "$v->input $v->code", $violations));
            $printed = implode("\n", array_map('strval', $violations));
            self::assertStringNotContainsString(self::OLD, $printed);
            self::assertStringNotContainsString(substr(self::NEW, 0, 10), $printed);
        }
    }

    public function testBothExamplesVerifyTheSameWay(): void
    {
        $laravel = (string) file_get_contents(self::EXAMPLES . '/orders/app/Webhooks.php');
        self::assertSame($laravel, file_get_contents(self::EXAMPLES . '/orders-symfony/src/Webhooks.php'));
    }
}
