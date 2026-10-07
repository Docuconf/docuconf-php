<?php

declare(strict_types=1);

namespace Docuconf\Symfony\Command;

use Docuconf\ConfigurationError;
use Docuconf\DeclarationError;
use Docuconf\Symfony\Docuconf;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'docuconf:check', description: 'Check the environment and files against the declared configuration')]
final class CheckCommand extends Command
{
    public function __construct(private readonly Docuconf $docuconf)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $result = $this->docuconf->check();
        } catch (DeclarationError $e) {
            $err->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        foreach ($result->warnings as $warning) {
            $err->writeln("docuconf: warning: $warning", OutputInterface::OUTPUT_RAW);
        }
        if (!$result->ok()) {
            $err->writeln(ConfigurationError::format($result->violations), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        $output->writeln('docuconf: configuration ok');
        return self::SUCCESS;
    }
}
