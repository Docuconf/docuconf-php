<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Files\ConfigFormat;
use Docuconf\Schema\Json;
use Docuconf\Spec\ContractSpec;
use Docuconf\Spec\VarSpec;

/**
 * The layers under the environment (SPEC §4.4, §4.7), in contract-first
 * mode: the selected profile's defaults, then the config-file overlays.
 * The environment is the top layer, and a variable's own default the
 * bottom one; VarParser::read() applies both.
 *
 * For each variable a profile or an overlay sets, load() gives the value of
 * the highest such layer: a profile default, already typed and checked
 * when the contract was read, or an overlay value as the wire string (or
 * list items) it stands for, which is then checked like an env value.
 *
 * @internal
 */
final class Layers
{
    /**
     * @param array<string, string> $env
     * @return array{array<string, array{source: string, typed?: mixed, raw?: string|list<string>, bad?: true}>, list<Violation>, list<string>}
     *         [layer by variable name, violations, warnings]
     */
    public static function load(ContractSpec $spec, #[\SensitiveParameter] array $env): array
    {
        $layers = [];
        $violations = [];
        $warnings = [];
        if ($spec->profiles !== null) {
            $profile = $spec->profiles->selected($spec, $env);
            foreach ($spec->profiles->defaults[$profile] ?? [] as $name => $typed) {
                $layers[$name] = ['source' => "profile $profile", 'typed' => $typed];
            }
        }
        $root = $env['DOCUCONF_FILE_ROOT'] ?? '';
        $from = [];
        foreach ($spec->sortedOverlays() as $name => $overlay) {
            $separator = $overlay->keySeparator;
            if ($separator === '') {
                continue; // SpecValidator rejects it
            }
            $path = $root !== '' ? rtrim($root, '/') . $overlay->path : $overlay->path;
            if (!file_exists($path)) {
                continue; // an overlay is optional
            }
            $fail = function (string $code, string $message) use (&$violations, $name): void {
                $violations[] = new Violation($name, $code, $message);
            };
            $data = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
            if ($data === false) {
                $fail('file_unreadable', "$path is not a readable file");
                continue;
            }
            if (str_starts_with($data, "\xEF\xBB\xBF")) {
                $data = substr($data, 3);
            }
            try {
                $doc = ConfigFormat::decode($overlay->format, $data);
            } catch (\Throwable $e) {
                $fail('file_malformed', "$path is not valid " . strtoupper($overlay->format) . ': ' . strtok($e->getMessage(), "\n"));
                continue;
            }
            // An empty TOML document is an empty table, and decodes to [].
            if (!($doc instanceof \stdClass || is_array($doc) && !array_is_list($doc) || $doc === [] && $overlay->format === 'toml')) {
                $fail('file_malformed', "$path does not hold an object at its top level");
                continue;
            }
            foreach ($spec->sortedVars() as $varName => $var) {
                if ($var->configKey === null || $varName === $spec->profiles?->selector) {
                    continue;
                }
                [$found, $value] = self::lookup($doc, explode($separator, $var->configKey));
                if (!$found || $value === null) {
                    continue; // null is unset
                }
                if (isset($from[$varName])) {
                    $warnings[] = "$varName is set in overlays {$from[$varName]} and $name; the first wins";
                    continue;
                }
                $from[$varName] = $name;
                $source = "overlay $name";
                if ($var->secret) {
                    // Never printed: the value is secret material in a ConfigMap.
                    $violations[] = new Violation($varName, 'invalid_type', "is secret, but $source sets it at {$var->configKey}; supply secrets through the environment");
                    $layers[$varName] = ['source' => $source, 'bad' => true];
                    continue;
                }
                $raw = self::wire($var, $value);
                if (!is_string($raw) && !is_array($raw)) {
                    $violations[] = new Violation($varName, 'invalid_type', "$source, at {$var->configKey}: " . $raw->getMessage());
                    $layers[$varName] = ['source' => $source, 'bad' => true];
                    continue;
                }
                $layers[$varName] = ['source' => $source, 'raw' => $raw];
            }
        }
        return [$layers, $violations, $warnings];
    }

    /**
     * The value at a key path; keys match exactly, as #Render writes them.
     *
     * @param list<string> $parts
     * @return array{bool, mixed}
     */
    private static function lookup(mixed $doc, array $parts): array
    {
        $cur = $doc;
        foreach ($parts as $key) {
            if ($cur instanceof \stdClass) {
                return [false, null]; // an empty object
            }
            if (!is_array($cur) || array_is_list($cur) || !array_key_exists($key, $cur)) {
                return [false, null];
            }
            $cur = $cur[$key];
        }
        return [true, $cur];
    }

    /**
     * A native overlay value as the wire string it stands for (SPEC §4.7): a
     * string as it is, a bool as true or false, an integral number as an
     * integer, any other number in shortest round-trip decimal; list items
     * one by one; and a json variable's value as compact JSON.
     *
     * @return string|list<string>|\InvalidArgumentException the wire form, or why there is none
     */
    private static function wire(VarSpec $var, mixed $value): string|array|\InvalidArgumentException
    {
        if ($var->type === 'json') {
            return Json::encode($value);
        }
        if ($var->type === 'list' || $var->type === 'keySet') {
            if (!is_array($value) || !array_is_list($value)) {
                return new \InvalidArgumentException('is ' . self::kind($value) . ', not a list');
            }
            $items = [];
            foreach ($value as $i => $item) {
                $text = self::scalar($item);
                if ($text === null) {
                    return new \InvalidArgumentException("item $i is " . self::kind($item) . ', not a scalar');
                }
                $items[] = $text;
            }
            return $items;
        }
        return self::scalar($value) ?? new \InvalidArgumentException('is ' . self::kind($value) . ', not a scalar');
    }

    private static function scalar(mixed $v): ?string
    {
        return match (true) {
            is_string($v) => $v,
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string) $v,
            is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 2 ** 63 => (string) (int) $v,
            is_float($v) && is_finite($v) => json_encode($v, JSON_THROW_ON_ERROR),
            default => null,
        };
    }

    private static function kind(mixed $v): string
    {
        return match (true) {
            $v instanceof \stdClass, is_array($v) && !array_is_list($v) => 'an object',
            is_array($v) => 'a list',
            default => get_debug_type($v),
        };
    }
}
