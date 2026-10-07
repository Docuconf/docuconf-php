<?php

declare(strict_types=1);

namespace Docuconf\Symfony;

use Docuconf\ConfigurationError;

/**
 * Validates at kernel boot: on every web request boot, and for the console
 * commands that run the app (messenger:consume, ...). A failed console boot
 * prints one line per problem and exits 1, without a stack trace.
 */
final class BootValidator
{
    /**
     * How a failed console boot ends the process. Tests replace it.
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $exitUsing = null;

    /** @param list<string> $consoleCommands */
    public function __construct(
        private readonly Docuconf $docuconf,
        private readonly bool $enabled,
        private readonly array $consoleCommands,
    ) {
    }

    public function validate(): void
    {
        if (!$this->enabled) {
            return;
        }
        $console = \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';
        if ($console) {
            $argv = $_SERVER['argv'] ?? [];
            if (!is_array($argv) || !in_array($argv[1] ?? null, $this->consoleCommands, true)) {
                return;
            }
        }
        $result = $this->docuconf->boot();
        if ($result->ok()) {
            return;
        }
        $message = ConfigurationError::format($result->violations);
        if ($console) {
            fwrite(STDERR, $message . "\n");
            (self::$exitUsing ?? static function (int $code): void {
                exit($code);
            })(1);
            return;
        }
        error_log($message);
        throw new ConfigurationError($result->violations);
    }
}
