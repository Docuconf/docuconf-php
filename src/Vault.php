<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Holds data for an object outside the object itself, so that no debug
 * printer can reach it: var_export(), serialize(), `(array)` casts and
 * Symfony's VarDumper (which shows real properties, and the variables a
 * closure captures, next to __debugInfo()) see nothing. Entries go away
 * with their owner.
 *
 * @internal
 */
final class Vault
{
    /** @var \WeakMap<object, mixed>|null */
    private static ?\WeakMap $data = null;

    public static function put(object $owner, #[\SensitiveParameter] mixed $data): void
    {
        self::$data ??= new \WeakMap();
        self::$data[$owner] = $data;
    }

    public static function get(object $owner): mixed
    {
        return self::$data[$owner] ?? null;
    }
}
