<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Writes boot failures to the Kubernetes termination log, so
 * `kubectl describe pod` shows why the container stopped. The path is
 * /dev/termination-log when it exists, or DOCUCONF_TERMINATION_LOG.
 */
final class TerminationLog
{
    public const DEFAULT_PATH = '/dev/termination-log';

    /** @param array<string, string> $env */
    public static function write(string $message, array $env): void
    {
        $path = $env['DOCUCONF_TERMINATION_LOG'] ?? '';
        if ($path === '') {
            if (!file_exists(self::DEFAULT_PATH)) {
                return;
            }
            $path = self::DEFAULT_PATH;
        }
        // Kubernetes keeps at most 4096 bytes of the termination message.
        if (strlen($message) > 4096) {
            $message = substr($message, 0, 4080) . "\n(truncated)";
        }
        @file_put_contents($path, $message . "\n");
    }
}
