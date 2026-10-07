<?php

declare(strict_types=1);

namespace Docuconf\Symfony\Command;

use Docuconf\DeclarationError;
use Docuconf\Symfony\Docuconf;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'docuconf:export', description: 'Export the configuration contract (contract.cue)')]
final class ExportCommand extends Command
{
    public function __construct(private readonly Docuconf $docuconf)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 1 if --output differs from what would be written')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'The CUE package name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $package = $input->getOption('package');
            $cue = $this->docuconf->export(is_string($package) ? $package : null);
        } catch (DeclarationError $e) {
            $err->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        $file = $input->getOption('output');
        $file = is_string($file) ? $file : null;
        if ($input->getOption('check')) {
            if ($file === null) {
                $err->writeln('docuconf: --check needs --output', OutputInterface::OUTPUT_RAW);
                return self::INVALID;
            }
            if (!is_file($file) || file_get_contents($file) !== $cue) {
                $err->writeln("docuconf: $file is out of date; run bin/console docuconf:export --output=$file", OutputInterface::OUTPUT_RAW);
                return self::FAILURE;
            }
            $output->writeln("docuconf: $file is up to date");
            return self::SUCCESS;
        }
        if ($file === null) {
            $output->write($cue, false, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        file_put_contents($file, $cue);
        $output->writeln("docuconf: wrote $file");
        return self::SUCCESS;
    }
}
