<?php

declare(strict_types=1);

namespace Docuconf\Laravel;

use Docuconf\ConfigurationError;
use Docuconf\Declaration;
use Docuconf\Environment;
use Docuconf\LoadResult;
use Docuconf\TerminationLog;
use Docuconf\Values;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Validates the declared configuration when the app boots, and registers
 * `php artisan docuconf:export` and `php artisan docuconf:check`.
 *
 * In the console (`php artisan serve`, queue workers, Octane) a bad
 * configuration prints one line per problem to stderr and exits 1, with
 * no stack trace. Over HTTP the same text goes to the server's error log
 * and the request fails with a ConfigurationError. Either way it is also
 * written to the Kubernetes termination log.
 */
final class DocuconfServiceProvider extends ServiceProvider
{
    /**
     * How a failed console boot ends the process. Tests replace it.
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $exitUsing = null;

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/docuconf.php', 'docuconf');
        $this->app->singleton(Declaration::class, fn () => $this->declaration());
        $this->app->singleton(Values::class, fn () => $this->result()->orThrow());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Commands\ExportCommand::class, Commands\CheckCommand::class]);
            $this->publishes([dirname(__DIR__, 2) . '/config/docuconf.php' => $this->app->configPath('docuconf.php')], 'docuconf-config');
        }
        if (!$this->shouldValidate()) {
            return;
        }
        $result = $this->result();
        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
        if ($result->ok()) {
            $this->app->instance(Values::class, $result->values);
            return;
        }
        $message = ConfigurationError::format($result->violations);
        TerminationLog::write($message, Environment::capture());
        if ($this->app->runningInConsole()) {
            fwrite(STDERR, $message . "\n");
            (self::$exitUsing ?? static function (int $code): void {
                exit($code);
            })(1);
            return;
        }
        error_log($message);
        throw new ConfigurationError($result->violations);
    }

    /** The declaration built from every Env:: call in the config files. */
    public function declaration(): Declaration
    {
        if (!Env::hasDeclarations() && $this->app instanceof Application && $this->app->configurationIsCached()) {
            // `config:cache` skips the config files, and with them the Env::
            // calls; run them once more just to record the declarations.
            foreach (glob($this->app->configPath('*.php')) ?: [] as $file) {
                (static function (string $file): void {
                    require $file;
                })($file);
            }
        }
        $config = $this->app->make('config');
        $name = $config->get('docuconf.name') ?: Str::slug((string) $config->get('app.name', 'laravel'));
        $version = $config->get('docuconf.app_version');
        return Env::declaration((string) $name, is_string($version) ? $version : null);
    }

    private function result(): LoadResult
    {
        return $this->app->make(Declaration::class)->check();
    }

    private function shouldValidate(): bool
    {
        $config = $this->app->make('config');
        if (!$config->get('docuconf.validate', true)) {
            return false;
        }
        if (!$this->app->runningInConsole()) {
            return true;
        }
        /** @var list<string> $commands */
        $commands = (array) $config->get('docuconf.console_commands', []);
        $argv = $_SERVER['argv'] ?? [];
        return is_array($argv) && in_array($argv[1] ?? null, $commands, true);
    }

    private function warn(string $warning): void
    {
        if ($this->app->runningInConsole()) {
            fwrite(STDERR, "docuconf: warning: $warning\n");
        } else {
            error_log("docuconf: warning: $warning");
        }
    }
}
