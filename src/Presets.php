<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Declarations for the variables a framework reads itself, so the contract
 * covers them too: opt in with `docuconf.presets` (Laravel's
 * config/docuconf.php, or Symfony's docuconf.yaml). A variable the app
 * declares itself is left as the app declared it.
 */
final class Presets
{
    public const NAMES = ['laravel', 'symfony'];

    public static function apply(Declaration $d, string $preset): void
    {
        match ($preset) {
            'laravel' => self::laravel($d),
            'symfony' => self::symfony($d),
            default => throw new DeclarationError(["unknown preset \"$preset\"; use one of: " . implode(', ', self::NAMES)]),
        };
    }

    /** What a fresh Laravel app reads from the environment that matters at deploy time. */
    public static function laravel(Declaration $d): void
    {
        $add = static function (string $name, bool $required, \Closure $declare) use ($d): void {
            if (!$d->has($name)) {
                $declare($required ? $d->required($name) : $d->ifPresent($name));
            }
        };
        $add('APP_KEY', true, fn (VarBuilder $v) => $v->secret()->minLength(32)->group('laravel')
            ->describe('Laravel encryption key (php artisan key:generate --show)'));
        $add('APP_ENV', false, fn (VarBuilder $v) => $v->default('production')->group('laravel')
            ->describe('Laravel environment: production, staging, local...'));
        $add('APP_DEBUG', false, fn (VarBuilder $v) => $v->isBoolean()->default(false)->group('laravel')
            ->describe('Show detailed error pages; never true in production'));
        $add('APP_URL', false, fn (VarBuilder $v) => $v->isUrl('http', 'https')->default('http://localhost')->group('laravel')
            ->describe('Public URL of the app, for generated links'));
        $add('LOG_LEVEL', false, fn (VarBuilder $v) => $v->allowedValues(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])
            ->default('debug')->group('laravel')->describe('Minimum level Laravel logs (PSR-3 level names)'));
        $add('DB_URL', false, fn (VarBuilder $v) => $v->isUrl()->secret()->group('laravel')
            ->describe('Database connection URL, when the app uses one'));
        $add('DB_PASSWORD', false, fn (VarBuilder $v) => $v->secret()->group('laravel')
            ->describe('Database password, when DB_URL is not used'));
    }

    /** What a Symfony app reads from the environment itself. */
    public static function symfony(Declaration $d): void
    {
        $add = static function (string $name, bool $required, \Closure $declare) use ($d): void {
            if (!$d->has($name)) {
                $declare($required ? $d->required($name) : $d->ifPresent($name));
            }
        };
        $add('APP_SECRET', true, fn (VarBuilder $v) => $v->secret()->notEmpty()->group('symfony')
            ->describe('Symfony kernel.secret, for CSRF tokens and signed URLs'));
        $add('APP_ENV', false, fn (VarBuilder $v) => $v->default('prod')->group('symfony')
            ->describe('Symfony environment: prod, dev or test'));
        $add('APP_DEBUG', false, fn (VarBuilder $v) => $v->isBoolean()->default(false)->group('symfony')
            ->describe('Debug mode; never true in production'));
    }
}
