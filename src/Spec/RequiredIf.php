<?php

declare(strict_types=1);

namespace Docuconf\Spec;

/**
 * A conditional requirement (SPEC §4.2.1): the input is required while the
 * variable $var has $value. The value is a string, an int or a bool, and
 * true is never 1.
 */
final class RequiredIf
{
    public function __construct(public readonly string $var, public readonly string|int|bool $value)
    {
    }

    /** Whether a variable's loaded value is the condition's value: the same type and the same value. */
    public function holdsFor(mixed $value): bool
    {
        return $value === $this->value;
    }

    /** The condition as messages write it, the value as JSON: `LOG_FORMAT is "json"`. */
    public function __toString(): string
    {
        return "{$this->var} is " . json_encode($this->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
