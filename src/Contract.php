<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Export\Exporter;
use Docuconf\Schema\Json;
use Docuconf\Spec\ContractSpec;
use Docuconf\Spec\FileSpec;
use Docuconf\Spec\SpecValidator;
use Docuconf\Spec\VarSpec;

/**
 * Contract-first mode (SPEC §11.2 item 11): validate an environment against
 * a contract given as JSON (`cue export contract.cue`), with no PHP
 * declaration. It parses every list and duration encoding and runs exactly
 * the checks the declaration API runs.
 *
 * ```php
 * $values = Contract::fromJson(file_get_contents('contract.json'))->load();
 * $values->int('PORT');
 * ```
 */
final class Contract
{
    private function __construct(public readonly ContractSpec $spec)
    {
    }

    /**
     * @param string|array<string, mixed> $contract JSON text, or the decoded document
     * @throws DeclarationError when the contract is not valid
     */
    public static function fromJson(string|array $contract): self
    {
        if (is_string($contract)) {
            try {
                $contract = Json::decode($contract);
            } catch (\JsonException $e) {
                throw new DeclarationError(['contract is not valid JSON: ' . $e->getMessage()]);
            }
        } else {
            $contract = Json::normalize(json_decode(Json::encode($contract)));
        }
        if (!is_array($contract)) {
            throw new DeclarationError(['contract must be a JSON object']);
        }
        $problems = [];
        if (($contract['kind'] ?? null) !== 'ConfigContract') {
            $problems[] = 'kind must be ConfigContract';
        }
        if (($contract['apiVersion'] ?? null) !== 'docuconf.dev/v1alpha1') {
            $problems[] = 'apiVersion must be docuconf.dev/v1alpha1';
        }
        $meta = is_array($contract['metadata'] ?? null) ? $contract['metadata'] : [];
        $spec = new ContractSpec((string) ($meta['name'] ?? ''), isset($meta['appVersion']) ? (string) $meta['appVersion'] : null);
        foreach (self::map($contract['vars'] ?? []) as $name => $v) {
            try {
                $spec->vars[$name] = self::var((string) $name, $v);
            } catch (\InvalidArgumentException | \TypeError $e) {
                $problems[] = "$name: " . $e->getMessage();
            }
        }
        foreach (self::map($contract['files'] ?? []) as $name => $f) {
            try {
                $spec->files[$name] = self::file((string) $name, $f);
            } catch (\InvalidArgumentException | \TypeError $e) {
                $problems[] = "file $name: " . $e->getMessage();
            }
        }
        $problems = [...$problems, ...SpecValidator::problems($spec)];
        if ($problems !== []) {
            throw new DeclarationError($problems);
        }
        return new self($spec);
    }

    /**
     * Validates $env (the process environment when null) and returns the
     * typed values, or throws with every violation.
     *
     * @param array<string, string>|null $env
     * @throws ConfigurationError
     */
    public function load(?array $env = null): Values
    {
        $env ??= Environment::capture();
        $result = Loader::load($this->spec, $env);
        if (!$result->ok()) {
            $error = new ConfigurationError($result->violations);
            TerminationLog::write($error->getMessage(), $env);
            throw $error;
        }
        return $result->values;
    }

    /** @param array<string, string>|null $env */
    public function check(?array $env = null): LoadResult
    {
        return Loader::load($this->spec, $env ?? Environment::capture());
    }

    public function toCue(?string $package = null): string
    {
        return Exporter::toCue($this->spec, $package);
    }

    /** @return array<string, array<string, mixed>> */
    private static function map(mixed $m): array
    {
        if ($m instanceof \stdClass || !is_array($m)) {
            return [];
        }
        return array_filter($m, 'is_array');
    }

    /** @param array<string, mixed> $v */
    private static function var(string $name, array $v): VarSpec
    {
        $s = new VarSpec($name);
        $s->type = (string) ($v['type'] ?? '');
        $s->description = (string) ($v['description'] ?? '');
        $s->required = (bool) ($v['required'] ?? false);
        $s->secret = (bool) ($v['secret'] ?? false);
        $s->group = isset($v['group']) ? (string) $v['group'] : null;
        $s->examples = isset($v['examples']) ? array_values(array_map('strval', (array) $v['examples'])) : null;
        $s->configKey = isset($v['configKey']) ? (string) $v['configKey'] : null;
        $s->deprecated = isset($v['deprecated']) && is_array($v['deprecated']) ? self::deprecated($v['deprecated']) : null;
        $s->minLength = self::intOrNull($v['minLength'] ?? null);
        $s->maxLength = self::intOrNull($v['maxLength'] ?? null);
        $s->pattern = isset($v['pattern']) ? (string) $v['pattern'] : null;
        $s->encoding = isset($v['encoding']) ? (string) $v['encoding'] : null;
        $s->schemes = isset($v['schemes']) ? array_values(array_map('strval', (array) $v['schemes'])) : null;
        $s->values = isset($v['values']) ? array_values(array_map('strval', (array) $v['values'])) : null;
        $s->items = (string) ($v['items'] ?? 'string');
        $s->separator = (string) ($v['separator'] ?? ',');
        $s->minItems = self::intOrNull($v['minItems'] ?? null);
        $s->maxItems = self::intOrNull($v['maxItems'] ?? null);
        $s->itemMin = self::intOrNull($v['itemMin'] ?? null);
        $s->itemMax = self::intOrNull($v['itemMax'] ?? null);
        if (isset($v['schema']) && is_array($v['schema'])) {
            $s->schema = $v['schema'];
        } elseif (isset($v['schema']) && $v['schema'] instanceof \stdClass) {
            $s->schema = [];
        }
        $bound = fn (mixed $x) => match ($s->type) {
            'duration' => Duration::fromGo((string) $x),
            'float' => (float) $x,
            default => self::intOrNull($x),
        };
        $s->min = isset($v['min']) ? $bound($v['min']) : null;
        $s->max = isset($v['max']) ? $bound($v['max']) : null;
        if (array_key_exists('default', $v)) {
            $s->hasDefault = true;
            $d = $v['default'];
            $s->default = match ($s->type) {
                'duration' => Duration::fromGo((string) $d),
                'float' => is_int($d) ? (float) $d : $d,
                default => $d,
            };
        }
        return $s;
    }

    /** @param array<string, mixed> $f */
    private static function file(string $name, array $f): FileSpec
    {
        $s = new FileSpec($name, (string) ($f['type'] ?? ''));
        $s->description = (string) ($f['description'] ?? '');
        $s->required = (bool) ($f['required'] ?? false);
        $s->secret = (bool) ($f['secret'] ?? $s->secret);
        $s->path = (string) ($f['path'] ?? '');
        $s->pathEnv = isset($f['pathEnv']) ? (string) $f['pathEnv'] : null;
        $s->reload = (string) ($f['reload'] ?? 'restart');
        $s->maxSize = self::intOrNull($f['maxSize'] ?? null);
        $s->group = isset($f['group']) ? (string) $f['group'] : null;
        $s->deprecated = isset($f['deprecated']) && is_array($f['deprecated']) ? self::deprecated($f['deprecated']) : null;
        $s->format = isset($f['format']) ? (string) $f['format'] : null;
        $s->schema = isset($f['schema']) && is_array($f['schema']) ? $f['schema'] : null;
        $s->dnsNames = isset($f['dnsNames']) ? array_values(array_map('strval', (array) $f['dnsNames'])) : null;
        $s->keyAlgorithms = isset($f['keyAlgorithms']) ? array_values(array_map('strval', (array) $f['keyAlgorithms'])) : null;
        $s->minRemaining = isset($f['minRemaining']) ? Duration::fromGo((string) $f['minRemaining']) : null;
        $s->requireCA = (bool) ($f['requireCA'] ?? false);
        $s->minCertificates = self::intOrNull($f['minCertificates'] ?? null) ?? 1;
        $s->passwordVar = isset($f['passwordVar']) ? (string) $f['passwordVar'] : null;
        $s->pattern = isset($f['pattern']) ? (string) $f['pattern'] : null;
        $s->minLength = self::intOrNull($f['minLength'] ?? null);
        $s->maxLength = self::intOrNull($f['maxLength'] ?? null);
        return $s;
    }

    /**
     * @param array<array-key, mixed> $d
     * @return array{message: string, replacedBy?: string}
     */
    private static function deprecated(array $d): array
    {
        $out = ['message' => (string) ($d['message'] ?? '')];
        if (isset($d['replacedBy'])) {
            $out['replacedBy'] = (string) $d['replacedBy'];
        }
        return $out;
    }

    private static function intOrNull(mixed $x): ?int
    {
        if ($x === null) {
            return null;
        }
        if (!is_int($x)) {
            throw new \InvalidArgumentException('expected an integer, got ' . get_debug_type($x));
        }
        return $x;
    }
}
