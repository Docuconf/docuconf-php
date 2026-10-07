<?php

declare(strict_types=1);

namespace Docuconf\Symfony;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * docuconf for Symfony.
 *
 * ```yaml
 * # config/packages/docuconf.yaml
 * docuconf:
 *     name: orders
 *     vars:
 *         PORT: {type: int, description: HTTP listen port, min: 1, max: 65535, default: 8080}
 *         DATABASE_URL: {type: url, description: Orders database, required: true, secret: true, schemes: [postgres]}
 * ```
 *
 * then `%env(docuconf:PORT)%` wherever `%env(int:PORT)%` was used. The
 * kernel validates every declared variable at boot, and
 * `bin/console docuconf:export` writes the contract.
 */
final class DocuconfBundle extends AbstractBundle
{
    public const DEFAULT_CONSOLE_COMMANDS = ['messenger:consume', 'scheduler:run', 'server:run'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('name')->isRequired()->cannotBeEmpty()->info('The service name in the contract, a DNS label')->end()
                ->scalarNode('app_version')->defaultNull()->end()
                ->scalarNode('declaration')->defaultNull()->info('A PHP file returning a Docuconf\\Declaration, instead of vars/files')->end()
                ->arrayNode('vars')
                    ->info('Variables, written as in contract.cue')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->variablePrototype()->end()
                ->end()
                ->arrayNode('files')
                    ->info('File inputs, written as in contract.cue')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->variablePrototype()->end()
                ->end()
                ->booleanNode('validate')->defaultTrue()->end()
                ->arrayNode('console_commands')
                    ->scalarPrototype()->end()
                    ->defaultValue(self::DEFAULT_CONSOLE_COMMANDS)
                ->end()
            ->end();
    }

    /** @param array{name: string, app_version: ?string, declaration: ?string, vars: array<string, mixed>, files: array<string, mixed>, validate: bool, console_commands: list<string>} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set(Docuconf::class)
            ->args([$config['name'], $config['app_version'], $config['vars'], $config['files'], $config['declaration']])
            ->public();
        $services->set(BootValidator::class)
            ->args([service(Docuconf::class), $config['validate'], $config['console_commands']])
            ->public();
        $services->set(EnvVarProcessor::class)
            ->args([service(Docuconf::class)])
            ->tag('container.env_var_processor');
        $services->set(Command\ExportCommand::class)
            ->args([service(Docuconf::class)])
            ->tag('console.command');
        $services->set(Command\CheckCommand::class)
            ->args([service(Docuconf::class)])
            ->tag('console.command');
    }

    public function boot(): void
    {
        $validator = $this->container?->get(BootValidator::class);
        if ($validator instanceof BootValidator) {
            $validator->validate();
        }
    }
}
