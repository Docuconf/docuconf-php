<?php

declare(strict_types=1);

namespace Docuconf\Laravel\Commands;

use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/** `php artisan docuconf:export`: writes the app's contract as CUE. */
final class ExportCommand extends Command
{
    protected $signature = 'docuconf:export
        {--output= : Write to this file instead of stdout (contract.cue)}
        {--check : Exit 1 if --output differs from what would be written}
        {--package= : The CUE package name (default: from the service name)}';

    protected $description = 'Export the configuration contract (contract.cue) declared with Docuconf\Laravel\Env';

    public function handle(Declaration $declaration): int
    {
        try {
            $package = $this->option('package');
            $cue = $declaration->export(is_string($package) ? $package : null);
        } catch (DeclarationError $e) {
            $this->getOutput()->getErrorStyle()->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        $output = $this->option('output');
        $output = is_string($output) ? $output : null;
        $err = $this->getOutput()->getErrorStyle();
        if ($this->option('check')) {
            if ($output === null) {
                $err->writeln('docuconf: --check needs --output', OutputInterface::OUTPUT_RAW);
                return self::INVALID;
            }
            if (!is_file($output) || file_get_contents($output) !== $cue) {
                $err->writeln("docuconf: $output is out of date; run php artisan docuconf:export --output=$output", OutputInterface::OUTPUT_RAW);
                return self::FAILURE;
            }
            $this->line("docuconf: $output is up to date");
            return self::SUCCESS;
        }
        if ($output === null) {
            $this->getOutput()->write($cue, false, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        file_put_contents($output, $cue);
        $this->line("docuconf: wrote $output");
        return self::SUCCESS;
    }
}
