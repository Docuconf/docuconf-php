<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * `vendor/bin/docuconf`:
 *
 *     docuconf export env.php [-o contract.cue] [--check] [--package NAME]
 *     docuconf check env.php
 *
 * `env.php` returns a Declaration.
 *
 * @internal
 */
final class Cli
{
    private const USAGE = <<<TXT
        usage: docuconf export <declaration.php> [-o FILE] [--check] [--package NAME]
               docuconf check <declaration.php>
               docuconf --version

        <declaration.php> is a PHP file that returns a Docuconf\\Declaration.
          export   writes the contract as CUE (to stdout, or FILE); with --check, exits 1
                   when FILE differs from what would be written
          check    validates the process environment and files, and lists every problem
        TXT;

    /**
     * @param list<string> $argv
     * @param resource $out
     * @param resource $err
     */
    public static function main(array $argv, $out, $err): int
    {
        $args = array_slice($argv, 1);
        $command = array_shift($args);
        if ($command === '--version' || $command === 'version') {
            fwrite($out, Version::SDK . ' ' . Version::VERSION . "\n");
            return 0;
        }
        if ($command === null || in_array($command, ['-h', '--help', 'help'], true)) {
            fwrite($command === null ? $err : $out, self::USAGE . "\n");
            return $command === null ? 2 : 0;
        }
        $file = null;
        $output = null;
        $check = false;
        $package = null;
        while ($args !== []) {
            $a = array_shift($args);
            match (true) {
                $a === '-o' || $a === '--output' => $output = array_shift($args),
                str_starts_with($a, '--output=') => $output = substr($a, 9),
                $a === '--check' => $check = true,
                $a === '--package' => $package = array_shift($args),
                str_starts_with($a, '--package=') => $package = substr($a, 10),
                default => $file = $a,
            };
        }
        if ($file === null || !in_array($command, ['export', 'check'], true)) {
            fwrite($err, self::USAGE . "\n");
            return 2;
        }
        try {
            $declaration = self::declaration($file);
            if ($command === 'export') {
                $cue = $declaration->export($package);
                return self::write($cue, $output, $check, $out, $err);
            }
            $result = $declaration->check();
            foreach ($result->warnings as $w) {
                fwrite($err, "docuconf: warning: $w\n");
            }
            if (!$result->ok()) {
                fwrite($err, ConfigurationError::format($result->violations) . "\n");
                return 1;
            }
            fwrite($out, "docuconf: configuration ok\n");
            return 0;
        } catch (DeclarationError $e) {
            fwrite($err, $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Writes $cue to $output (or stdout); with $check, compares instead.
     *
     * @param resource $out
     * @param resource $err
     */
    public static function write(string $cue, ?string $output, bool $check, $out, $err): int
    {
        if ($check) {
            if ($output === null) {
                fwrite($err, "docuconf: --check needs -o FILE\n");
                return 2;
            }
            $current = is_file($output) ? file_get_contents($output) : null;
            if ($current !== $cue) {
                fwrite($err, "docuconf: $output is out of date; re-export it\n");
                return 1;
            }
            fwrite($out, "docuconf: $output is up to date\n");
            return 0;
        }
        if ($output === null) {
            fwrite($out, $cue);
            return 0;
        }
        file_put_contents($output, $cue);
        fwrite($out, "docuconf: wrote $output\n");
        return 0;
    }

    private static function declaration(string $file): Declaration
    {
        if (!is_file($file)) {
            throw new DeclarationError(["$file does not exist"]);
        }
        $declaration = (static fn (string $f): mixed => require $f)($file);
        if (!$declaration instanceof Declaration) {
            throw new DeclarationError(["$file must return a Docuconf\\Declaration, got " . get_debug_type($declaration)]);
        }
        return $declaration;
    }
}
