<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * What loading produced: the values (with null for anything that failed),
 * every violation, and warnings such as deprecated variables being set.
 */
final class LoadResult
{
    /**
     * @param list<Violation> $violations
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly Values $values,
        public readonly array $violations,
        public readonly array $warnings,
    ) {
    }

    public function ok(): bool
    {
        return $this->violations === [];
    }

    /**
     * What var_dump(), print_r() and VarDumper show: secrets redacted.
     *
     * @return array{values: array<string, mixed>, violations: list<string>, warnings: list<string>}
     */
    public function __debugInfo(): array
    {
        return [
            'values' => $this->values->redacted(),
            'violations' => array_map(fn (Violation $v) => (string) $v, $this->violations),
            'warnings' => $this->warnings,
        ];
    }

    /** The values, or a ConfigurationError holding every violation. */
    public function orThrow(): Values
    {
        if ($this->violations !== []) {
            throw new ConfigurationError($this->violations);
        }
        return $this->values;
    }
}
