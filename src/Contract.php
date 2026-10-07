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
        foreach (self::unknownKeys($contract, self::TOP_KEYS) as $key) {
            $problems[] = in_array($key, ['overlays', 'profiles'], true)
                ? "$key are not supported by docuconf-php yet; remove them, or load this contract with an SDK that supports them"
                : self::unknown($key, self::TOP_KEYS, 'contract');
        }
        if (($contract['kind'] ?? null) !== 'ConfigContract') {
            $problems[] = 'kind must be ConfigContract';
        }
        if (($contract['apiVersion'] ?? null) !== 'docuconf.dev/v1alpha1') {
            $problems[] = 'apiVersion must be docuconf.dev/v1alpha1';
        }
        $meta = $contract['metadata'] ?? [];
        if (!is_array($meta)) {
            $problems[] = 'metadata must be an object';
            $meta = [];
        }
        foreach (self::unknownKeys($meta, ['name', 'appVersion', 'generator']) as $key) {
            $problems[] = self::unknown($key, ['name', 'appVersion', 'generator'], 'metadata');
        }
        if (isset($meta['generator'])) {
            if (!is_array($meta['generator'])) {
                $problems[] = 'metadata.generator must be an object';
            } else {
                foreach (self::unknownKeys($meta['generator'], ['language', 'sdk', 'version']) as $key) {
                    $problems[] = self::unknown($key, ['language', 'sdk', 'version'], 'metadata.generator');
                }
            }
        }
        $name = $meta['name'] ?? '';
        $appVersion = $meta['appVersion'] ?? null;
        if (!is_string($name) || $appVersion !== null && !is_string($appVersion)) {
            $problems[] = 'metadata.name and metadata.appVersion must be strings';
        }
        $spec = new ContractSpec(is_string($name) ? $name : '', is_string($appVersion) ? $appVersion : null);
        foreach (self::map($contract['vars'] ?? [], 'vars', $problems) as $name => $v) {
            try {
                $spec->vars[$name] = self::var((string) $name, $v);
            } catch (\InvalidArgumentException | \TypeError $e) {
                $problems[] = "$name: " . $e->getMessage();
            }
        }
        foreach (self::map($contract['files'] ?? [], 'files', $problems) as $name => $f) {
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
    public function load(#[\SensitiveParameter] ?array $env = null): Values
    {
        $processEnv = $env === null;
        $env ??= Environment::capture();
        $result = Loader::load($this->spec, $env);
        if (!$result->ok()) {
            $error = new ConfigurationError($result->violations);
            TerminationLog::write($error->getMessage(), $env, $processEnv);
            throw $error;
        }
        return $result->values;
    }

    /**
     * load(), but on any problem prints one line per problem to stderr,
     * writes the termination log and exits 1, with no stack trace.
     *
     * @param array<string, string>|null $env
     */
    public function loadOrExit(#[\SensitiveParameter] ?array $env = null): Values
    {
        return Console::loadOrExit(fn () => $this->spec, $env ?? Environment::capture(), $env === null);
    }

    /** @param array<string, string>|null $env */
    public function check(#[\SensitiveParameter] ?array $env = null): LoadResult
    {
        return Loader::load($this->spec, $env ?? Environment::capture());
    }

    public function toCue(?string $package = null): string
    {
        return Exporter::toCue($this->spec, $package);
    }

    private const TOP_KEYS = ['apiVersion', 'kind', 'metadata', 'vars', 'files'];

    private const VAR_COMMON = ['name', 'type', 'description', 'required', 'secret', 'group', 'examples', 'configKey', 'deprecated', 'default'];

    /** Every key some variable type takes; SpecValidator reports one used on the wrong type. */
    private const VAR_KEYS = [
        ...self::VAR_COMMON,
        'minLength', 'maxLength', 'pattern', 'min', 'max', 'encoding', 'schemes', 'values',
        'items', 'separator', 'minItems', 'maxItems', 'itemMin', 'itemMax', 'schema',
    ];

    private const FILE_KEYS = [
        'name', 'type', 'description', 'required', 'secret', 'path', 'pathEnv', 'reload', 'maxSize', 'group', 'deprecated',
        'format', 'schema', 'dnsNames', 'keyAlgorithms', 'minRemaining', 'requireCA', 'minCertificates', 'passwordVar',
        'pattern', 'minLength', 'maxLength',
    ];

    /**
     * @param list<string> $problems
     * @return array<string, array<string, mixed>>
     */
    private static function map(mixed $m, string $what, array &$problems): array
    {
        if ($m instanceof \stdClass) {
            return [];
        }
        if (!is_array($m) || array_is_list($m) && $m !== []) {
            $problems[] = "$what must be an object keyed by name";
            return [];
        }
        $out = [];
        foreach ($m as $name => $entry) {
            if (!is_array($entry) || array_is_list($entry) && $entry !== []) {
                $problems[] = ($what === 'files' ? 'file ' : '') . "$name: must be an object, got " . get_debug_type($entry);
                continue;
            }
            $out[(string) $name] = $entry;
        }
        return $out;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<string> $known
     * @return list<string>
     */
    private static function unknownKeys(array $object, array $known): array
    {
        return array_values(array_diff(array_map('strval', array_keys($object)), $known));
    }

    /** @param list<string> $known */
    private static function unknown(string $key, array $known, string $where): string
    {
        $hint = '';
        foreach ($known as $k) {
            if (levenshtein(strtolower($key), strtolower($k)) <= 2) {
                $hint = "; did you mean \"$k\"?";
                break;
            }
        }
        return "unknown key \"$key\" in $where$hint";
    }

    /** @param array<string, mixed> $v */
    private static function var(string $name, array $v): VarSpec
    {
        $unknown = self::unknownKeys($v, self::VAR_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(implode(', ', array_map(fn ($k) => self::unknown($k, self::VAR_KEYS, 'the variable'), $unknown)));
        }
        if (isset($v['name']) && $v['name'] !== $name) {
            throw new \InvalidArgumentException('name must be the key it is declared under');
        }
        $s = new VarSpec($name);
        $s->type = self::str($v, 'type') ?? '';
        $s->description = self::str($v, 'description') ?? '';
        $s->required = self::bool($v, 'required') ?? false;
        $s->secret = self::bool($v, 'secret') ?? false;
        $s->group = self::str($v, 'group');
        $s->examples = self::strings($v, 'examples');
        $s->configKey = self::str($v, 'configKey');
        $s->deprecated = self::deprecated($v);
        $s->minLength = self::int($v, 'minLength');
        $s->maxLength = self::int($v, 'maxLength');
        $s->pattern = self::str($v, 'pattern');
        $s->encoding = self::str($v, 'encoding');
        $s->schemes = self::strings($v, 'schemes');
        $s->values = self::strings($v, 'values');
        $s->items = self::str($v, 'items') ?? 'string';
        $s->separator = self::str($v, 'separator') ?? ',';
        $s->minItems = self::int($v, 'minItems');
        $s->maxItems = self::int($v, 'maxItems');
        $s->itemMin = self::int($v, 'itemMin');
        $s->itemMax = self::int($v, 'itemMax');
        if (array_key_exists('schema', $v)) {
            $s->schema = match (true) {
                is_array($v['schema']) => $v['schema'],
                $v['schema'] instanceof \stdClass => [],
                default => throw new \InvalidArgumentException('schema must be an object (a JSON Schema)'),
            };
        }
        $bound = function (string $key) use ($s, $v): int|float|Duration|null {
            if (!array_key_exists($key, $v) || $v[$key] === null) {
                return null;
            }
            $x = $v[$key];
            return match ($s->type) {
                'duration' => is_string($x) ? Duration::fromGo($x) : throw new \InvalidArgumentException("$key must be a Go duration string, such as \"30s\""),
                'float' => is_int($x) || is_float($x) ? (float) $x : throw new \InvalidArgumentException("$key must be a number, got " . get_debug_type($x)),
                default => self::int($v, $key),
            };
        };
        $s->min = $bound('min');
        $s->max = $bound('max');
        if (array_key_exists('default', $v)) {
            $s->hasDefault = true;
            $d = $v['default'];
            $s->default = match ($s->type) {
                'duration' => is_string($d) ? Duration::fromGo($d) : throw new \InvalidArgumentException('default must be a Go duration string, such as "30s"'),
                'float' => is_int($d) ? (float) $d : $d,
                default => $d,
            };
        }
        return $s;
    }

    /** @param array<string, mixed> $f */
    private static function file(string $name, array $f): FileSpec
    {
        $unknown = self::unknownKeys($f, self::FILE_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(implode(', ', array_map(fn ($k) => self::unknown($k, self::FILE_KEYS, 'the file input'), $unknown)));
        }
        if (isset($f['name']) && $f['name'] !== $name) {
            throw new \InvalidArgumentException('name must be the key it is declared under');
        }
        $s = new FileSpec($name, self::str($f, 'type') ?? '');
        $s->description = self::str($f, 'description') ?? '';
        $s->required = self::bool($f, 'required') ?? false;
        $s->secret = self::bool($f, 'secret') ?? $s->secret;
        $s->path = self::str($f, 'path') ?? '';
        $s->pathEnv = self::str($f, 'pathEnv');
        $s->reload = self::str($f, 'reload') ?? 'restart';
        $s->maxSize = self::int($f, 'maxSize');
        $s->group = self::str($f, 'group');
        $s->deprecated = self::deprecated($f);
        $s->format = self::str($f, 'format');
        if (array_key_exists('schema', $f)) {
            $s->schema = match (true) {
                is_array($f['schema']) => $f['schema'],
                $f['schema'] instanceof \stdClass => [],
                default => throw new \InvalidArgumentException('schema must be an object (a JSON Schema)'),
            };
        }
        $s->dnsNames = self::strings($f, 'dnsNames');
        $s->keyAlgorithms = self::strings($f, 'keyAlgorithms');
        $minRemaining = self::str($f, 'minRemaining');
        $s->minRemaining = $minRemaining === null ? null : Duration::fromGo($minRemaining);
        $s->requireCA = self::bool($f, 'requireCA') ?? false;
        $s->minCertificates = self::int($f, 'minCertificates') ?? 1;
        $s->passwordVar = self::str($f, 'passwordVar');
        $s->pattern = self::str($f, 'pattern');
        $s->minLength = self::int($f, 'minLength');
        $s->maxLength = self::int($f, 'maxLength');
        return $s;
    }

    /**
     * @param array<string, mixed> $o
     * @return array{message: string, replacedBy?: string}|null
     */
    private static function deprecated(array $o): ?array
    {
        if (!isset($o['deprecated'])) {
            return null;
        }
        $d = $o['deprecated'];
        if (!is_array($d)) {
            throw new \InvalidArgumentException('deprecated must be an object with a message');
        }
        foreach (self::unknownKeys($d, ['message', 'replacedBy']) as $key) {
            throw new \InvalidArgumentException(self::unknown($key, ['message', 'replacedBy'], 'deprecated'));
        }
        $out = ['message' => self::str($d, 'message') ?? ''];
        $replacedBy = self::str($d, 'replacedBy');
        if ($replacedBy !== null) {
            $out['replacedBy'] = $replacedBy;
        }
        return $out;
    }

    /** @param array<array-key, mixed> $o */
    private static function str(array $o, string $key): ?string
    {
        $x = $o[$key] ?? null;
        if ($x !== null && !is_string($x)) {
            throw new \InvalidArgumentException("$key must be a string, got " . get_debug_type($x));
        }
        return $x;
    }

    /**
     * Strictly true or false: "no", "false" and 0 are mistakes, not false.
     *
     * @param array<array-key, mixed> $o
     */
    private static function bool(array $o, string $key): ?bool
    {
        $x = $o[$key] ?? null;
        if ($x !== null && !is_bool($x)) {
            throw new \InvalidArgumentException("$key must be true or false, got " . (is_string($x) ? "\"$x\"" : get_debug_type($x)));
        }
        return $x;
    }

    /** @param array<array-key, mixed> $o */
    private static function int(array $o, string $key): ?int
    {
        $x = $o[$key] ?? null;
        if ($x !== null && !is_int($x)) {
            throw new \InvalidArgumentException("$key must be an integer, got " . get_debug_type($x));
        }
        return $x;
    }

    /**
     * @param array<array-key, mixed> $o
     * @return list<string>|null
     */
    private static function strings(array $o, string $key): ?array
    {
        $x = $o[$key] ?? null;
        if ($x === null) {
            return null;
        }
        if (!is_array($x) || !array_is_list($x)) {
            throw new \InvalidArgumentException("$key must be a list of strings");
        }
        foreach ($x as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException("$key must be a list of strings, got an item of type " . get_debug_type($item));
            }
        }
        return $x;
    }
}
