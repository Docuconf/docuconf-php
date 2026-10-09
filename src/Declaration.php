<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Export\Exporter;
use Docuconf\Spec\ContractSpec;
use Docuconf\Spec\FileSpec;
use Docuconf\Spec\SpecValidator;
use Docuconf\Spec\VarSpec;
use Dotenv\Dotenv;

/**
 * Everything a service reads from its environment and filesystem.
 *
 * ```php
 * $env = Docuconf\Env::declare('orders');
 * $env->required('DATABASE_URL')->isUrl('postgres')->secret()->describe('Primary Postgres connection string');
 * $env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
 *
 * $config = $env->loadOrExit();   // or: one line per problem on stderr, exit 1
 * $config->int('PORT');           // 8080
 * echo $env->export();      // contract.cue
 * ```
 */
final class Declaration
{
    /** @var array<string, VarBuilder> */
    private array $vars = [];
    /** @var array<string, FileBuilder> */
    private array $files = [];
    /** @var list<string> */
    private array $duplicates = [];
    /** @var string|list<string>|null */
    private string|array|null $dotenvPaths = null;
    /** @var string|list<string>|null */
    private string|array|null $dotenvNames = null;

    public function __construct(private readonly string $name, private readonly ?string $appVersion = null)
    {
    }

    /** A variable the platform must set: no default allowed. */
    public function required(string $name): VarBuilder
    {
        $builder = $this->var($name);
        $builder->spec()->required = true;
        return $builder;
    }

    /** An optional variable: it may have a default, or be absent (null). */
    public function ifPresent(string $name): VarBuilder
    {
        return $this->var($name);
    }

    /** Alias of ifPresent(), for readers who do not know phpdotenv. */
    public function optional(string $name): VarBuilder
    {
        return $this->var($name);
    }

    /** A structured config file: format "json", "yaml" or "toml". */
    public function configFile(string $name, string $path, string $format = 'json'): FileBuilder
    {
        $builder = $this->file($name, 'config', $path);
        $builder->spec()->format = $format;
        return $builder;
    }

    /** A TLS key pair directory: tls.crt, tls.key and, with requireCA(), ca.crt. */
    public function tls(string $name, string $directory): FileBuilder
    {
        return $this->file($name, 'tls', $directory);
    }

    /** A PEM bundle of one or more CA certificates. */
    public function caBundle(string $name, string $path): FileBuilder
    {
        return $this->file($name, 'caBundle', $path);
    }

    /** A keystore: format "pkcs12" or "jks". Its password is a secret variable (passwordVar). */
    public function keystore(string $name, string $path, string $format = 'pkcs12'): FileBuilder
    {
        $builder = $this->file($name, 'keystore', $path);
        $builder->spec()->format = $format;
        return $builder;
    }

    /** A text file, such as a licence key. */
    public function text(string $name, string $path): FileBuilder
    {
        return $this->file($name, 'text', $path);
    }

    /** Opaque bytes, such as a GeoIP database. */
    public function binary(string $name, string $path): FileBuilder
    {
        return $this->file($name, 'binary', $path);
    }

    /**
     * Also reads `.env` files with phpdotenv, for local development:
     * `Env::declare('orders')->withDotenv(__DIR__)`, or
     * `->withDotenv(__DIR__, '.env.local')`. The arguments are those of
     * `Dotenv::createImmutable($paths, $names)`. Real environment variables
     * win over the files, and the files are read into docuconf only: $_ENV,
     * $_SERVER and getenv() are left alone.
     *
     * @param string|list<string> $paths directories holding the files
     * @param string|list<string>|null $names file names; ".env" when null
     */
    public function withDotenv(string|array $paths, string|array|null $names = null): self
    {
        $this->dotenvPaths = $paths;
        $this->dotenvNames = $names;
        return $this;
    }

    /** Whether a variable of this name has been declared. */
    public function has(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    /**
     * The checked declaration.
     *
     * @throws DeclarationError when the declaration itself is wrong
     */
    public function spec(): ContractSpec
    {
        $problems = $this->duplicates;
        $spec = new ContractSpec($this->name, $this->appVersion);
        foreach ($this->vars as $name => $builder) {
            $spec->vars[$name] = $builder->build($problems);
        }
        foreach ($this->files as $name => $builder) {
            $spec->files[$name] = $builder->build($problems);
        }
        $problems = [...$problems, ...SpecValidator::problems($spec)];
        if ($problems !== []) {
            throw new DeclarationError(array_values(array_unique($problems)));
        }
        return $spec;
    }

    /**
     * Hints about the declaration, such as variables that look like feature flags.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return SpecValidator::warnings($this->spec());
    }

    /**
     * Validates the environment and files, and returns the typed values; on
     * any problem, prints one line per problem to stderr, writes the
     * Kubernetes termination log and exits with code 1, with no stack
     * trace. The boot one-liner for an app's entry point:
     *
     * ```php
     * $config = (require __DIR__ . '/../config/env.php')->loadOrExit();
     * ```
     *
     * Warnings (deprecated variables, a misspelt variable name) go to stderr
     * too. Secret values are never printed.
     *
     * @param array<string, string>|null $env the environment; the process environment when null
     */
    public function loadOrExit(#[\SensitiveParameter] ?array $env = null): Values
    {
        return Console::loadOrExit($this->spec(...), $env ?? $this->environment(), $env === null);
    }

    /**
     * Validates the environment and files, and returns the typed values.
     *
     * Every problem is reported together in one ConfigurationError, which
     * is also written to the Kubernetes termination log. Secret values are
     * never printed. For an app's entry point, loadOrExit() reports without
     * a stack trace.
     *
     * With an explicit $env map, as in a unit test, nothing outside the map
     * is read, and the termination log is written only when the map sets
     * DOCUCONF_TERMINATION_LOG.
     *
     * @param array<string, string>|null $env the environment; the process environment when null
     * @throws ConfigurationError
     * @throws DeclarationError
     */
    public function load(#[\SensitiveParameter] ?array $env = null): Values
    {
        $processEnv = $env === null;
        $env ??= $this->environment();
        $result = Loader::load($this->spec(), $env);
        if ($processEnv) {
            // A real boot: warn about deprecated inputs that are set (by
            // name and message, never the value) and likely typos.
            Console::warnings($result->warnings);
        }
        if (!$result->ok()) {
            $error = new ConfigurationError($result->violations);
            TerminationLog::write($error->getMessage(), $env, $processEnv);
            throw $error;
        }
        return $result->values;
    }

    /**
     * Like load(), but returns the result instead of throwing, for tools
     * that report problems themselves.
     *
     * @param array<string, string>|null $env
     */
    public function check(#[\SensitiveParameter] ?array $env = null): LoadResult
    {
        return Loader::load($this->spec(), $env ?? $this->environment());
    }

    /** The contract, as `contract.cue`. */
    public function export(?string $package = null): string
    {
        return Exporter::toCue($this->spec(), $package);
    }

    /**
     * The process environment, plus the `.env` files given to withDotenv()
     * for names the process environment does not set.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $env = Environment::capture();
        if ($this->dotenvPaths !== null) {
            $file = Dotenv::createArrayBacked($this->dotenvPaths, $this->dotenvNames)->safeLoad();
            foreach ($file as $name => $value) {
                if (is_string($value) && !array_key_exists($name, $env)) {
                    $env[$name] = $value;
                }
            }
        }
        return $env;
    }

    private function var(string $name): VarBuilder
    {
        if (isset($this->vars[$name])) {
            $this->duplicates[] = "$name: declared twice";
        }
        return $this->vars[$name] = (new VarBuilder(new VarSpec($name)))->documentedAt(Docs::callSite());
    }

    private function file(string $name, string $type, string $path): FileBuilder
    {
        if (isset($this->files[$name])) {
            $this->duplicates[] = "file $name: declared twice";
        }
        $spec = new FileSpec($name, $type);
        $spec->path = $path;
        return $this->files[$name] = (new FileBuilder($spec))->documentedAt(Docs::callSite());
    }
}
