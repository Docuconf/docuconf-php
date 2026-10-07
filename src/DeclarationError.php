<?php

declare(strict_types=1);

namespace Docuconf;

use LogicException;

/**
 * The declaration itself is wrong: a bad name, a short description, a
 * default that breaks its own constraints, a pattern outside RE2. This is
 * a bug in the app, found when the declaration is built (SPEC §11.2 item 2).
 */
final class DeclarationError extends LogicException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("docuconf: invalid declaration:\n  - " . implode("\n  - ", $problems));
    }
}
