<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Writes boot failures to the Kubernetes termination log, so
 * `kubectl describe pod` shows why the container stopped. The path is
 * DOCUCONF_TERMINATION_LOG, or /dev/termination-log when it exists.
 *
 * A load from an explicit env map (a unit test) only writes when the map
 * sets DOCUCONF_TERMINATION_LOG, so a failing test in a Kubernetes CI pod
 * does not overwrite the pod's own termination message.
 */
final class TerminationLog
{
    public const DEFAULT_PATH = '/dev/termination-log';

    /**
     * @param array<string, string> $env
     * @param bool $processEnv whether $env is the process environment; when it
     *        is an explicit map, only DOCUCONF_TERMINATION_LOG is written to
     */
    public static function write(string $message, #[\SensitiveParameter] array $env, bool $processEnv = true): void
    {
        $path = $env['DOCUCONF_TERMINATION_LOG'] ?? '';
        if ($path === '') {
            if (!$processEnv || !file_exists(self::DEFAULT_PATH)) {
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
