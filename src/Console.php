<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * How a failed boot reports and ends the process: one line per problem on
 * stderr (the server's error log outside the CLI), the termination log,
 * and exit code 1. No stack trace, and nothing printed twice. Shared by
 * `loadOrExit()` and the Laravel and Symfony integrations.
 *
 * @internal
 */
final class Console
{
    /**
     * Where problems and warnings go instead of stderr. Tests replace it.
     *
     * @var resource|null
     */
    public static $stderr = null;

    /**
     * How a failed boot ends the process. Tests replace it.
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $exitUsing = null;

    public static function isCli(): bool
    {
        return \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';
    }

    /** Writes one message (it may span lines) to stderr, or the error log outside the CLI. */
    public static function error(string $message): void
    {
        if (self::$stderr !== null) {
            fwrite(self::$stderr, $message . "\n");
            return;
        }
        if (self::isCli()) {
            $err = fopen('php://stderr', 'w');
            if ($err !== false) {
                fwrite($err, $message . "\n");
                fclose($err);
                return;
            }
        }
        error_log($message);
    }

    /** @param list<string> $warnings */
    public static function warnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            self::error("docuconf: warning: $warning");
        }
    }

    /**
     * Reports a failed boot and ends the process with exit code 1 (HTTP 500
     * outside the CLI).
     *
     * @param array<string, string> $env what was loaded, for DOCUCONF_TERMINATION_LOG
     */
    public static function fail(string $message, #[\SensitiveParameter] array $env, bool $processEnv = true): void
    {
        TerminationLog::write($message, $env, $processEnv);
        self::error($message);
        if (!self::isCli() && !headers_sent()) {
            http_response_code(500);
        }
        (self::$exitUsing ?? static function (int $code): void {
            exit($code);
        })(1);
    }

    /**
     * load(), but reporting a failure with fail() instead of throwing.
     *
     * @param \Closure(): \Docuconf\Spec\ContractSpec $spec
     * @param array<string, string> $env
     */
    public static function loadOrExit(\Closure $spec, #[\SensitiveParameter] array $env, bool $processEnv): Values
    {
        try {
            $result = Loader::load($spec(), $env);
        } catch (DeclarationError $e) {
            self::fail($e->getMessage(), $env, $processEnv);
            throw $e;
        }
        self::warnings($result->warnings);
        if (!$result->ok()) {
            self::fail(ConfigurationError::format($result->violations), $env, $processEnv);
            throw new ConfigurationError($result->violations);
        }
        return $result->values;
    }

    /** The console command being run: the first argument that is not an option ("list" when none). */
    public static function command(): string
    {
        $argv = $_SERVER['argv'] ?? [];
        $args = is_array($argv) ? array_values(array_slice($argv, 1)) : [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (!is_string($arg) || $arg === '') {
                continue;
            }
            if ($arg === '-e' || $arg === '--env') {
                $i++; // its value
                continue;
            }
            if ($arg[0] !== '-') {
                return $arg;
            }
        }
        return 'list';
    }

    /** @param list<string> $patterns command names; a trailing * matches any suffix */
    public static function matches(string $command, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if ($p === $command || str_ends_with($p, '*') && str_starts_with($command, substr($p, 0, -1))) {
                return true;
            }
        }
        return false;
    }
}
