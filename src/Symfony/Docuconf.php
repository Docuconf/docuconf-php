<?php

declare(strict_types=1);

namespace Docuconf\Symfony;

use Docuconf\ConfigurationError;
use Docuconf\Contract;
use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Docuconf\Environment;
use Docuconf\Export\Exporter;
use Docuconf\Loader;
use Docuconf\LoadResult;
use Docuconf\Presets;
use Docuconf\Spec\ContractSpec;
use Docuconf\TerminationLog;
use Docuconf\Values;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * The app's declaration inside a Symfony container: built from the
 * `docuconf.vars` and `docuconf.files` bundle configuration (contract-shaped
 * YAML), or from a PHP file returning a Docuconf\Declaration. Values are
 * loaded once per process.
 *
 * Each declared variable is read through Symfony's own env resolution (the
 * container's default env var processor): real environment variables,
 * `.env` files, the secrets vault (`secrets:set`) and `env(NAME)` parameter
 * defaults, exactly as `%env(NAME)%` sees them.
 */
final class Docuconf
{
    private ?ContractSpec $spec = null;
    private ?LoadResult $result = null;
    /** @var array<string, string>|null */
    private ?array $env = null;

    /**
     * @param array<string, mixed> $vars contract-shaped variables, as in contract.cue
     * @param array<string, mixed> $files contract-shaped file inputs
     * @param list<string> $presets framework variables to declare too ("symfony")
     * @param EnvVarProcessorInterface|null $resolver Symfony's default env var processor; null outside a container
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $appVersion = null,
        private readonly array $vars = [],
        private readonly array $files = [],
        private readonly ?string $declarationFile = null,
        private readonly array $presets = [],
        private readonly ?EnvVarProcessorInterface $resolver = null,
        private readonly ?string $kernelEnvironment = null,
    ) {
    }

    /** @throws DeclarationError */
    public function spec(): ContractSpec
    {
        if ($this->spec !== null) {
            return $this->spec;
        }
        if ($this->declarationFile !== null) {
            $declaration = (static fn (string $f): mixed => require $f)($this->declarationFile);
            if (!$declaration instanceof Declaration) {
                throw new DeclarationError(["{$this->declarationFile} must return a Docuconf\\Declaration"]);
            }
            foreach ($this->presets as $preset) {
                Presets::apply($declaration, $preset);
            }
            return $this->spec = $declaration->spec();
        }
        $metadata = ['name' => $this->name];
        if ($this->appVersion !== null) {
            $metadata['appVersion'] = $this->appVersion;
        }
        $contract = ['apiVersion' => 'docuconf.dev/v1alpha1', 'kind' => 'ConfigContract', 'metadata' => $metadata, 'vars' => $this->vars];
        if ($this->files !== []) {
            $contract['files'] = $this->files;
        }
        $spec = Contract::fromJson($contract)->spec;
        if ($this->presets !== []) {
            $extra = new Declaration($this->name);
            foreach ($this->presets as $preset) {
                Presets::apply($extra, $preset);
            }
            foreach ($extra->spec()->vars as $name => $var) {
                $spec->vars[$name] ??= $var;
            }
            ksort($spec->vars);
        }
        return $this->spec = $spec;
    }

    /**
     * The environment docuconf validates: the process environment, with
     * every declared name resolved the way Symfony resolves `%env(NAME)%`.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        if ($this->env !== null) {
            return $this->env;
        }
        $env = Environment::capture();
        if ($this->resolver === null) {
            return $this->env = $env;
        }
        $names = ['DOCUCONF_FILE_ROOT', 'DOCUCONF_TERMINATION_LOG'];
        $spec = $this->spec();
        foreach ($spec->vars as $name => $var) {
            $names[] = $name;
        }
        foreach ($spec->files as $file) {
            if ($file->pathEnv !== null) {
                $names[] = $file->pathEnv;
            }
        }
        foreach ($names as $name) {
            $value = $this->resolve($name);
            if ($value !== null) {
                $env[$name] = $value;
            }
        }
        foreach ($spec->vars as $name => $var) {
            if ($var->type === 'list' && $var->encoding() === 'indexed') {
                for ($i = 0; ($value = $this->resolve("{$name}__$i")) !== null; $i++) {
                    $env["{$name}__$i"] = $value;
                }
            }
        }
        return $this->env = $env;
    }

    private function resolve(string $name): ?string
    {
        if ($this->resolver === null) {
            return null;
        }
        try {
            $value = $this->resolver->getEnv('', $name, static function (string $n): never {
                throw new EnvNotFoundException("Environment variable not found: \"$n\".");
            });
        } catch (\Exception $e) {
            if ($e instanceof EnvNotFoundException) {
                return null;
            }
            throw $e;
        }
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * Validates the environment (once) and returns every violation found,
     * and warnings. With an explicit $env map, as in a test, only the map is
     * read, and nothing is cached.
     *
     * @param array<string, string>|null $env
     */
    public function check(#[\SensitiveParameter] ?array $env = null): LoadResult
    {
        if ($env !== null) {
            return Loader::load($this->spec(), $env);
        }
        if ($this->result !== null) {
            return $this->result;
        }
        $result = Loader::load($this->spec(), $this->environment());
        $warnings = [...$result->warnings, ...$this->dotenvWarnings()];
        return $this->result = new LoadResult($result->values, $result->violations, $warnings);
    }

    /**
     * In prod, a required or secret variable whose value only came from a
     * committed `.env` file (a placeholder such as Doctrine's
     * "!ChangeMe!") satisfies `required` without the platform setting it.
     *
     * @return list<string>
     */
    private function dotenvWarnings(): array
    {
        $env = $this->kernelEnvironment ?? ($this->environment()['APP_ENV'] ?? null);
        if ($env !== 'prod') {
            return [];
        }
        $loaded = $_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? '';
        if (!is_string($loaded) || $loaded === '') {
            return [];
        }
        $fromFile = array_flip(explode(',', $loaded));
        $warnings = [];
        foreach ($this->spec()->vars as $name => $var) {
            if (($var->required || $var->secret) && isset($fromFile[$name])) {
                $what = $var->secret ? 'secret' : 'required';
                $warnings[] = "$name ($what) comes from a .env file, not the real environment; set it in the environment or with secrets:set";
            }
        }
        return $warnings;
    }

    /** @throws ConfigurationError */
    public function values(): Values
    {
        return $this->check()->orThrow();
    }

    /** Validates, writes the termination log on failure, and returns the result. */
    public function boot(): LoadResult
    {
        $result = $this->check();
        if (!$result->ok()) {
            TerminationLog::write(ConfigurationError::format($result->violations), $this->environment());
        }
        return $result;
    }

    public function export(?string $package = null): string
    {
        return Exporter::toCue($this->spec(), $package);
    }

    /** Forgets loaded values, so the next call re-reads the environment. */
    public function reset(): void
    {
        $this->result = null;
        $this->env = null;
    }
}
