<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * The process environment as the app sees it at boot (SPEC §11.2 item 4).
 *
 * Real environment variables come from getenv(). Values phpdotenv loaded
 * from a `.env` file live in $_ENV (and $_SERVER); they are used only for
 * names the real environment does not set, so the real environment always
 * wins, as phpdotenv's immutable mode intends.
 */
final class Environment
{
    /** @return array<string, string> */
    public static function capture(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[(string) $key] = $value;
            }
        }
        foreach (getenv() as $key => $value) {
            $env[(string) $key] = $value;
        }
        return $env;
    }
}
