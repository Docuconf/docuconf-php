<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Files\FileChecker;
use Docuconf\Spec\ContractSpec;

/**
 * Loads a contract from an environment: every variable (VarParser), then
 * every file input (FileChecker). All violations are collected, never just
 * the first. Variables not in the contract are ignored (SPEC §11.2 item 10).
 *
 * @internal
 */
final class Loader
{
    /**
     * @param array<string, string> $env the whole environment
     * @param int|null $now Unix time for certificate checks (tests)
     */
    public static function load(ContractSpec $spec, array $env, ?int $now = null): LoadResult
    {
        $values = [];
        $present = [];
        $violations = [];
        $warnings = [];
        foreach ($spec->vars as $name => $var) {
            [$isSet, $value, $violation] = VarParser::read($var, $env);
            if ($violation !== null) {
                $violations[] = $violation;
                $value = null;
            }
            $values[$name] = $value;
            $present[$name] = $isSet;
            if ($isSet && $var->deprecated !== null) {
                $warnings[] = "$name is deprecated: {$var->deprecated['message']}"
                    . (isset($var->deprecated['replacedBy']) ? "; use {$var->deprecated['replacedBy']}" : '');
            }
        }
        $files = [];
        foreach ($spec->files as $name => $file) {
            [$loaded, $fileViolations] = FileChecker::check($file, $env, $now);
            array_push($violations, ...$fileViolations);
            $files[$name] = $loaded;
            if ($loaded !== null && $file->deprecated !== null) {
                $warnings[] = "file $name is deprecated: {$file->deprecated['message']}";
            }
        }
        array_push($warnings, ...self::typos($spec, $env));
        return new LoadResult(new Values($spec, $values, $present, $files), $violations, $warnings);
    }

    /**
     * Hints for variables that are set but not declared, and are one or two
     * edits away from a declared name: "DATABSE_URL is set but not
     * declared; did you mean DATABASE_URL?". The value is never shown.
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function typos(ContractSpec $spec, #[\SensitiveParameter] array $env): array
    {
        $declared = array_keys($spec->vars);
        foreach ($spec->files as $file) {
            if ($file->pathEnv !== null) {
                $declared[] = $file->pathEnv;
            }
        }
        if ($declared === []) {
            return [];
        }
        $known = array_flip($declared);
        $hints = [];
        foreach (array_keys($env) as $name) {
            $name = (string) $name;
            if (isset($known[$name]) || str_starts_with($name, 'DOCUCONF_') || !preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                continue;
            }
            if (preg_match('/^(.+)__\d+$/', $name, $m) && isset($known[$m[1]])) {
                continue; // an item of an indexed list
            }
            $best = null;
            $bestDistance = 3;
            foreach ($declared as $candidate) {
                $d = levenshtein($name, $candidate);
                // Short names need the distance to leave most of the name intact.
                if ($d < $bestDistance && $d <= strlen($candidate) - 2) {
                    $best = $candidate;
                    $bestDistance = $d;
                }
            }
            if ($best !== null) {
                $hints[] = "$name is set but not declared; did you mean $best?";
            }
        }
        return $hints;
    }
}
