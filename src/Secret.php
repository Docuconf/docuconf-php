<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * A secret value that does not print itself.
 *
 * `echo`, string interpolation, `var_dump`, `print_r`, `var_export`,
 * `json_encode` and Symfony's VarDumper (Laravel's `dump()`/`dd()`) all show
 * "***". Call `reveal()` where the real value is needed, such as when
 * opening the database connection.
 *
 * ```php
 * $url = $config->secret('DATABASE_URL');   // Docuconf\Secret
 * new PDO($url->reveal());
 * ```
 */
final class Secret implements \JsonSerializable, \Stringable
{
    public function __construct(#[\SensitiveParameter] mixed $value)
    {
        // Held outside the object, where no debug printer can reach it.
        Vault::put($this, $value);
    }

    public function __clone()
    {
        throw new \LogicException('docuconf: a Secret cannot be cloned');
    }

    /** The real value. */
    public function reveal(): mixed
    {
        return Vault::get($this);
    }

    public function __toString(): string
    {
        return Values::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return Values::REDACTED;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => Values::REDACTED];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('docuconf: a Secret cannot be serialized; store the configuration, not its values');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('docuconf: a Secret cannot be unserialized');
    }
}
