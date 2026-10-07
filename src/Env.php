<?php

declare(strict_types=1);

namespace Docuconf;

use Dotenv\Dotenv;

/**
 * Entry point for declaring a service's configuration.
 *
 * ```php
 * $env = Env::declare('orders');                                  // a contract named "orders"
 * $env = Env::createImmutable(__DIR__, 'orders');                 // the same, reading .env with phpdotenv
 * ```
 */
final class Env
{
    /**
     * @param string $name the service name, a DNS label ("orders")
     * @param string|null $appVersion the version or git SHA, written into the contract
     */
    public static function declare(string $name, ?string $appVersion = null): Declaration
    {
        return new Declaration($name, $appVersion);
    }

    /**
     * A declaration that loads `.env` from $paths with phpdotenv's immutable
     * mode before reading the environment, as `Dotenv::createImmutable()` does.
     *
     * @param string|list<string> $paths
     */
    public static function createImmutable(string|array $paths, string $name, ?string $appVersion = null): Declaration
    {
        return (new Declaration($name, $appVersion))->withDotenv(Dotenv::createImmutable($paths));
    }
}
