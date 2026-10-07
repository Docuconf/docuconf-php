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
use Docuconf\Spec\ContractSpec;
use Docuconf\TerminationLog;
use Docuconf\Values;

/**
 * The app's declaration inside a Symfony container: built from the
 * `docuconf.vars` and `docuconf.files` bundle configuration (contract-shaped
 * YAML), or from a PHP file returning a Docuconf\Declaration. Values are
 * loaded once per process.
 */
final class Docuconf
{
    private ?ContractSpec $spec = null;
    private ?LoadResult $result = null;

    /**
     * @param array<string, mixed> $vars contract-shaped variables, as in contract.cue
     * @param array<string, mixed> $files contract-shaped file inputs
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $appVersion = null,
        private readonly array $vars = [],
        private readonly array $files = [],
        private readonly ?string $declarationFile = null,
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
        return $this->spec = Contract::fromJson($contract)->spec;
    }

    /** Validates the process environment (once) and returns every violation found. */
    public function check(): LoadResult
    {
        return $this->result ??= Loader::load($this->spec(), Environment::capture());
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
            TerminationLog::write(ConfigurationError::format($result->violations), Environment::capture());
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
    }
}
