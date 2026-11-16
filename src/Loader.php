<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Files\FileChecker;
use Docuconf\Spec\ContractSpec;
use Docuconf\Spec\RequiredIf;

/**
 * Loads a contract from an environment: the layers under it (Layers), every
 * variable (VarParser), then every file input (FileChecker). All violations are collected, never just
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
        // Profiles and overlays (contract-first mode) sit under the environment.
        [$layers, $violations, $warnings] = Layers::load($spec, $env);
        // Roles (SPEC §4.9): an input only other roles use is optional here.
        // With no role, or one the contract does not declare, every
        // role-specific input is optional, so the role is the one problem.
        $role = $env[ContractSpec::ROLE_VAR] ?? null;
        $role = $spec->roles !== null && in_array($role, $spec->roles, true) ? $role : null;
        $inRole = fn (?array $used): bool => $used === null || $role !== null && in_array($role, $used, true);
        foreach ($spec->vars as $name => $var) {
            if ($var->required && !$inRole($var->roles)) {
                $var = clone $var;
                $var->required = false;
            }
            $layer = $layers[$name] ?? null;
            [$isSet, $value, $violation, $source] = VarParser::read($var, $env, $layer);
            if ($violation !== null) {
                $violations[] = $violation;
                $value = null;
            }
            $values[$name] = $value;
            $present[$name] = $isSet;
            if ($source === 'env' && $layer !== null && isset($layer['raw'])) {
                $warnings[] = "$name is set in the environment and in {$layer['source']}; the environment wins";
            }
            if ($isSet && $var->deprecated !== null) {
                // The name and the message, never the value (SPEC §11.2).
                $warnings[] = "$name is deprecated" . ($source === 'env' ? '' : " (set in $source)") . ": {$var->deprecated['message']}"
                    . (isset($var->deprecated['replacedBy']) ? " (replaced by {$var->deprecated['replacedBy']})" : '');
            }
        }
        // Conditional requirements (SPEC §4.2.1), on the condition variables'
        // loaded values: the environment, an overlay, the selected profile or
        // the default. A condition variable with a violation has no value, so
        // it makes nothing required.
        $requiredWhen = fn (?RequiredIf $cond, ?array $used): ?RequiredIf =>
            $cond !== null && $inRole($used) && $cond->holdsFor($values[$cond->var] ?? null) ? $cond : null;
        $reported = array_flip(array_map(fn (Violation $v) => $v->input, $violations));
        foreach ($spec->vars as $name => $var) {
            if ($values[$name] === null && !isset($reported[$name]) && ($when = $requiredWhen($var->requiredIf, $var->roles)) !== null) {
                $violations[] = new Violation($name, 'missing_required', "is required when $when");
            }
        }
        $files = [];
        foreach ($spec->files as $name => $file) {
            $when = $requiredWhen($file->requiredIf, $file->roles);
            $required = $inRole($file->roles) && ($file->required || $when !== null);
            if ($required !== $file->required) {
                $file = clone $file;
                $file->required = $required;
            }
            [$loaded, $fileViolations] = FileChecker::check($file, $env, $now);
            if ($when !== null) {
                $fileViolations = array_map(
                    fn (Violation $v) => $v->code === 'file_missing' ? new Violation($v->input, $v->code, "{$v->message}, and is required when $when") : $v,
                    $fileViolations,
                );
            }
            array_push($violations, ...$fileViolations);
            $files[$name] = $loaded;
            if ($loaded !== null && $file->deprecated !== null) {
                $warnings[] = "file $name is deprecated: {$file->deprecated['message']}"
                    . (isset($file->deprecated['replacedBy']) ? " (replaced by {$file->deprecated['replacedBy']})" : '');
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
