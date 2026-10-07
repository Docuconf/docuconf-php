<?php

declare(strict_types=1);

namespace Docuconf\Laravel\Commands;

use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Docuconf\Laravel\DocuconfServiceProvider;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php artisan docuconf:check`: validates the environment and files, lists
 * every problem, and exits 1 if there are any, including a config cache
 * built from other values. Useful as a container entrypoint step before
 * php-fpm starts.
 */
final class CheckCommand extends Command
{
    protected $signature = 'docuconf:check';

    protected $description = 'Check the environment and files against the declared configuration';

    public function handle(Declaration $declaration): int
    {
        try {
            $result = $declaration->check();
        } catch (DeclarationError $e) {
            $this->getOutput()->getErrorStyle()->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        foreach ($result->warnings as $warning) {
            $this->getOutput()->getErrorStyle()->writeln("docuconf: warning: $warning", OutputInterface::OUTPUT_RAW);
        }
        $problems = DocuconfServiceProvider::problems($this->laravel, $result);
        if ($problems !== null) {
            $this->getOutput()->getErrorStyle()->writeln($problems, OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        $this->line('docuconf: configuration ok');
        return self::SUCCESS;
    }
}
