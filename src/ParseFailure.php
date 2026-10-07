<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * A raw value that cannot be read as its type.
 *
 * @internal
 */
final class ParseFailure extends \Exception
{
    public function __construct(public readonly string $violationCode, string $message)
    {
        parent::__construct($message);
    }
}
