<?php

declare(strict_types=1);

namespace Docuconf\Laravel;

use Docuconf\ConfigurationError;
use Docuconf\Console;
use Docuconf\Declaration;
use Docuconf\Environment;
use Docuconf\Export\Exporter;
use Docuconf\LoadResult;
use Docuconf\Presets;
use Docuconf\TerminationLog;
use Docuconf\Values;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Validates the declared configuration when the app boots, and registers
 * `php artisan docuconf:export` and `php artisan docuconf:check`.
 *
 * Every artisan command validates at boot (serve, queue workers, Octane,
 * migrate, tinker, your own commands), except the ones in
 * `docuconf.skip_commands` that do not run the app (config:cache,
 * package:discover...); those print a warning for each invalid value
 * instead. A bad configuration prints one line per problem to stderr and
 * exits 1, with no stack trace. Over HTTP the request fails with a
 * ConfigurationError, which Laravel logs once. Either way it is also
 * written to the Kubernetes termination log.
 *
 * With `php artisan config:cache`, the app reads the cached values, so
 * docuconf checks that they are what the environment gives now, and fails
 * when the cache was built with different values (as when it is built
 * into an image before the real environment exists).
 */
final class DocuconfServiceProvider extends ServiceProvider
{
    /** Where config:cache stores what each Env:: call returned (hashed). */
    public const FINGERPRINTS = 'docuconf.fingerprints';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/docuconf.php', 'docuconf');
        $config = $this->config();
        if (!($this->app instanceof Application && $this->app->configurationIsCached())) {
            // Recorded with the configuration, so that `config:cache` keeps
            // what the cached values were built from.
            $config->set(self::FINGERPRINTS, Env::fingerprints());
        }
        $this->app->singleton(Declaration::class, fn () => $this->declaration());
        $this->app->singleton(Values::class, fn () => $this->result()->orThrow());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Commands\ExportCommand::class, Commands\CheckCommand::class]);
            $this->publishes([dirname(__DIR__, 2) . '/config/docuconf.php' => $this->app->configPath('docuconf.php')], 'docuconf-config');
        }
        $config = $this->config();
        if (!$config->get('docuconf.validate', true)) {
            return;
        }
        $console = $this->app->runningInConsole();
        if ($console && $this->app->runningUnitTests()) {
            // Tests check their declarations with Declaration::check([...]).
            return;
        }
        $command = Console::command();
        if ($console && Console::matches($command, $this->skipCommands())) {
            if (!str_starts_with($command, 'docuconf:')) {
                $this->warnInvalid();
            }
            return;
        }
        $result = $this->result();
        $problems = self::problems($this->app, $result);
        if ($console) {
            Console::warnings($result->warnings);
        }
        if ($problems === null) {
            $this->app->instance(Values::class, $result->values);
            return;
        }
        if ($console) {
            Console::fail($problems, Environment::capture());
            return;
        }
        TerminationLog::write($problems, Environment::capture());
        throw $result->ok() ? new StaleConfigCache($problems) : new ConfigurationError($result->violations);
    }

    /**
     * Every problem, formatted for printing, or null when there is none:
     * the violations, then a config cache built from other values.
     */
    public static function problems(\Illuminate\Contracts\Foundation\Application $app, LoadResult $result): ?string
    {
        if (!$result->ok()) {
            return ConfigurationError::format($result->violations);
        }
        return self::staleCache($app, $result);
    }

    /**
     * When the config is cached, the names whose cached value is not what
     * the environment gives now, as a message; null when the cache is
     * current or config is not cached.
     */
    public static function staleCache(\Illuminate\Contracts\Foundation\Application $app, LoadResult $result): ?string
    {
        if (!$app instanceof Application || !$app->configurationIsCached()) {
            return null;
        }
        $cached = $app->make('config')->get(self::FINGERPRINTS);
        if (!is_array($cached)) {
            return 'docuconf: the config cache was built without docuconf, so its values cannot be checked; '
                . 'run `php artisan config:cache` again, at container start';
        }
        $stale = [];
        foreach ($result->values as $name => $value) {
            if (array_key_exists($name, $cached) && $cached[$name] !== Env::fingerprint($value)) {
                $stale[] = $name;
            }
        }
        if ($stale === []) {
            return null;
        }
        $file = str_replace($app->basePath() . '/', '', $app->getCachedConfigPath());
        return 'docuconf: config cache is stale: ' . $file . ' was built with other values of ' . implode(', ', $stale)
            . ' than the environment has now, and the app reads the cached ones; run `php artisan config:cache` at container start, not at build';
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
        $config = $this->config();
        $version = $config->get('docuconf.app_version');
        $declaration = Env::declaration(self::name($config), is_string($version) ? $version : null);
        foreach ((array) $config->get('docuconf.presets', []) as $preset) {
            Presets::apply($declaration, (string) $preset);
        }
        return $declaration;
    }

    /** The contract name: docuconf.name, or the slug of app.name. */
    public static function name(Repository $config): string
    {
        $name = $config->get('docuconf.name');
        return is_string($name) && $name !== '' ? $name : Str::slug((string) $config->get('app.name', 'laravel'));
    }

    /** A warning when the contract would be named after Laravel's default app.name. */
    public static function nameWarning(Repository $config): ?string
    {
        $name = $config->get('docuconf.name');
        if (is_string($name) && $name !== '' || $config->get('app.name', 'Laravel') !== 'Laravel') {
            return null;
        }
        return 'the contract is named "laravel" after Laravel\'s default app.name; set APP_NAME, or DOCUCONF_SERVICE (docuconf.name)';
    }

    private function result(): LoadResult
    {
        return $this->app->make(Declaration::class)->check();
    }

    /** @return list<string> */
    private function skipCommands(): array
    {
        /** @var list<string> */
        return array_values(array_map('strval', (array) $this->config()->get('docuconf.skip_commands', [])));
    }

    /**
     * A command that skips the check still runs with what Env:: returned,
     * which is the default (or null) for an invalid value; say so.
     */
    private function warnInvalid(): void
    {
        try {
            $result = $this->result();
        } catch (\Docuconf\DeclarationError $e) {
            Console::error($e->getMessage());
            return;
        }
        $spec = $this->app->make(Declaration::class)->spec();
        foreach ($result->violations as $v) {
            $var = $spec->vars[$v->input] ?? null;
            if ($var === null || $v->code === 'missing_required') {
                continue;
            }
            $using = $var->hasDefault ? 'its default (' . json_encode(Exporter::plain($var->default), JSON_UNESCAPED_SLASHES) . ')' : 'null';
            Console::error("docuconf: warning: {$v->input} is invalid ({$v->code}); using $using");
        }
    }

    private function config(): Repository
    {
        /** @var Repository */
        return $this->app->make('config');
    }
}
