<?php

declare(strict_types=1);

namespace Docuconf\Files;

/** A change to a watched file input that failed its checks: when, which input and the violation codes, never the content. */
final class RejectedReload
{
    public function __construct(
        public readonly \DateTimeImmutable $at,
        public readonly string $name,
        /** @var list<string> the violation codes, such as keystore_unreadable */
        public readonly array $codes,
    ) {
    }
}
