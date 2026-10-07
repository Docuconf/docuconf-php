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
        return new LoadResult(new Values($spec, $values, $present, $files), $violations, $warnings);
    }
}
