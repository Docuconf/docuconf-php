<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Entry point for declaring a service's configuration.
 *
 * ```php
 * $env = Env::declare('orders');                         // a contract named "orders"
 * $env = Env::declare('orders')->withDotenv(__DIR__);    // the same, also reading .env with phpdotenv
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
}
