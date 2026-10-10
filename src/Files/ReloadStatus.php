<?php

declare(strict_types=1);

namespace Docuconf\Files;

/**
 * Where a watched file input stands (SPEC §4.6.2), for a health check or a
 * metric. It never holds the file's content.
 */
final class ReloadStatus
{
    public function __construct(
        /** 1 after boot, plus one per accepted reload. */
        public readonly int $generation,
        /** When the last accepted reload happened; null until the first one. */
        public readonly ?\DateTimeImmutable $lastReload,
        /** The last change that failed its checks; null when a later change was accepted, or none failed. */
        public readonly ?RejectedReload $lastRejected,
    ) {
    }
}
