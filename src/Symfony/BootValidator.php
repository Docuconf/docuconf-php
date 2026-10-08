<?php

declare(strict_types=1);

namespace Docuconf\Symfony;

use Docuconf\ConfigurationError;
use Docuconf\Console;

/**
 * Validates at kernel boot: on every web request boot, and for every
 * console command except the ones in `docuconf.skip_commands`
 * (cache:clear, assets:install, secrets:*, debug:*...), which do not run
 * the app. A failed console boot prints one line per problem and exits 1,
 * without a stack trace; a failed web boot throws a ConfigurationError,
 * which Symfony logs once.
 */
final class BootValidator
{
    /** @param list<string> $skipCommands */
    public function __construct(
        private readonly Docuconf $docuconf,
        private readonly bool $enabled,
        private readonly array $skipCommands,
    ) {
    }

    public function validate(): void
    {
        if (!$this->enabled) {
            return;
        }
        $console = Console::isCli();
        if ($console && Console::matches(Console::command(), $this->skipCommands)) {
            return;
        }
        $result = $this->docuconf->boot();
        if ($console) {
            Console::warnings($result->warnings);
        }
        if ($result->ok()) {
            return;
        }
        if ($console) {
            Console::fail(ConfigurationError::format($result->violations), $this->docuconf->environment());
            return;
        }
        throw new ConfigurationError($result->violations);
    }
}
