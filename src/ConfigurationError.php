<?php

declare(strict_types=1);

namespace Docuconf;

use RuntimeException;

/**
 * Thrown at boot when the environment or files do not satisfy the
 * declaration. Holds every violation, not just the first. The message is
 * one line per problem and never contains a secret value.
 */
final class ConfigurationError extends RuntimeException
{
    /** @param list<Violation> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(self::format($violations));
    }

    /** @param list<Violation> $violations */
    public static function format(array $violations): string
    {
        $n = count($violations);
        $lines = ["docuconf: $n configuration problem" . ($n === 1 ? '' : 's') . ':'];
        foreach ($violations as $v) {
            $lines[] = '  - ' . $v;
        }
        return implode("\n", $lines);
    }

    /** @return list<array{var: string, code: string}> */
    public function codes(): array
    {
        return array_map(fn (Violation $v) => ['var' => $v->input, 'code' => $v->code], $this->violations);
    }
}
