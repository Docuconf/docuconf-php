<?php

declare(strict_types=1);

namespace Docuconf\Symfony;

use Docuconf\DeclarationError;
use Docuconf\Presets;
use Docuconf\Spec\FileSpec;
use Docuconf\Spec\VarSpec;
use Docuconf\Values;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
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
 *         DATABASE_URL: {type: url, description: Orders database, required: true, secret: true, schemes: [postgres, postgresql]}
 * ```
 *
 * then `%env(docuconf:PORT)%` wherever `%env(int:PORT)%` was used, or the
 * `Docuconf\Values` service. Values are read through Symfony's own env
 * resolution, so `secrets:set` and `env(NAME)` parameter defaults count.
 * The kernel validates every declared variable at boot, and
 * `bin/console docuconf:export` writes the contract.
 * `bin/console config:dump-reference docuconf` lists every key.
 */
final class DocuconfBundle extends AbstractBundle
{
    /**
     * Console commands that do not run the app, and so skip the boot check
     * (a trailing * matches any suffix). Every other command validates.
     */
    public const DEFAULT_SKIP_COMMANDS = [
        'list', 'help', 'completion', '_complete', 'about',
        'cache:*', 'assets:install', 'importmap:*', 'asset-map:*', 'config:*', 'debug:*', 'lint:*',
        'secrets:*', 'docuconf:*', 'make:*', 'translation:*',
    ];

    private const VAR_TYPES = VarSpec::TYPES;
    private const FILE_TYPES = ['config', 'tls', 'caBundle', 'keystore', 'text', 'binary'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $vars = $definition->rootNode()
            ->children()
                ->scalarNode('name')->isRequired()->cannotBeEmpty()->info('The service name in the contract, a DNS label')->end()
                ->scalarNode('app_version')->defaultNull()->end()
                ->scalarNode('declaration')->defaultNull()->info('A PHP file returning a Docuconf\\Declaration, instead of vars/files')->end()
                ->arrayNode('presets')
                    ->info('Also declare the framework\'s own variables: [symfony] adds APP_SECRET, APP_ENV and APP_DEBUG')
                    ->enumPrototype()->values(Presets::NAMES)->end()
                ->end()
                ->arrayNode('vars')
                    ->info('Variables, written as in contract.cue')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children();
        self::varNode($vars);
        $files = $vars->end()->end()->end()
                ->arrayNode('files')
                    ->info('File inputs, written as in contract.cue')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children();
        self::fileNode($files);
        $files->end()->end()->end()
                ->booleanNode('validate')->defaultTrue()->end()
                ->arrayNode('skip_commands')
                    ->info('Console commands that skip the boot check; every other command validates')
                    ->scalarPrototype()->end()
                    ->defaultValue(self::DEFAULT_SKIP_COMMANDS)
                ->end()
            ->end();
    }

    private static function varNode(NodeBuilder $n): void
    {
        self::common($n);
        $n->enumNode('type')->values(self::VAR_TYPES)->isRequired()->end();
        $n->scalarNode('configKey')->end();
        self::strings($n, 'examples');
        $n->variableNode('default')->info('A value of the variable\'s type; durations in Go form ("30s")')->end();
        $n->integerNode('minLength')->min(0)->end();
        $n->integerNode('maxLength')->min(0)->end();
        $n->scalarNode('pattern')->info('RE2')->end();
        self::bound($n, 'min');
        self::bound($n, 'max');
        $n->enumNode('encoding')->values(['go', 'iso8601', 'seconds', 'timespan', 'csv', 'json', 'indexed'])->end();
        self::strings($n, 'schemes');
        self::strings($n, 'values');
        $n->enumNode('items')->values(['string', 'int'])->end();
        $n->scalarNode('separator')->end();
        $n->integerNode('minItems')->min(0)->end();
        $n->integerNode('maxItems')->min(0)->end();
        $n->integerNode('itemMin')->end();
        $n->integerNode('itemMax')->end();
        $n->integerNode('itemMinLength')->min(0)->end();
        $n->integerNode('itemMaxLength')->min(0)->end();
        $n->integerNode('minKeys')->min(1)->end();
        $n->integerNode('maxKeys')->min(1)->end();
        $n->integerNode('keyMinLength')->min(1)->end();
        $n->integerNode('keyMaxLength')->min(1)->end();
        $n->variableNode('schema')->info('A JSON Schema')->end();
    }

    private static function fileNode(NodeBuilder $n): void
    {
        self::common($n);
        $n->enumNode('type')->values(self::FILE_TYPES)->isRequired()->end();
        $n->scalarNode('path')->isRequired()->end();
        $n->scalarNode('pathEnv')->end();
        $n->enumNode('reload')->values(['restart', 'watch'])->end();
        $n->integerNode('maxSize')->min(1)->end();
        $n->enumNode('format')->values(['json', 'yaml', 'toml', 'pkcs12', 'jks'])->end();
        $n->variableNode('schema')->info('A JSON Schema')->end();
        self::strings($n, 'dnsNames');
        self::strings($n, 'keyAlgorithms');
        $n->scalarNode('minRemaining')->info('A Go duration, such as 720h')->end();
        $n->booleanNode('requireCA')->end();
        $n->integerNode('minCertificates')->min(1)->end();
        $n->scalarNode('passwordVar')->end();
        $n->scalarNode('pattern')->end();
        $n->integerNode('minLength')->min(0)->end();
        $n->integerNode('maxLength')->min(0)->end();
    }

    private static function common(NodeBuilder $n): void
    {
        $n->scalarNode('description')->isRequired()->info('What it is for; at least 5 characters')->end();
        $n->scalarNode('details')->info('Longer CommonMark for generated docs; at most 4000 characters')->end();
        // Strictly true or false: Symfony rejects "no" or "false" here.
        $n->booleanNode('required')->end();
        $n->booleanNode('secret')->end();
        $n->scalarNode('group')->end();
        $n->arrayNode('deprecated')
            ->children()
                ->scalarNode('message')->isRequired()->end()
                ->scalarNode('replacedBy')->end()
            ->end()
        ->end();
    }

    /** A list of strings; a variable node, so that an unset list stays unset rather than []. */
    private static function strings(NodeBuilder $n, string $name): void
    {
        $n->variableNode($name)
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => !is_array($v) || !array_is_list($v) || array_filter($v, 'is_string') !== $v)
                ->thenInvalid('%s is not a list of strings')
            ->end()
        ->end();
    }

    private static function bound(NodeBuilder $n, string $name): void
    {
        $n->scalarNode($name)
            ->info('An int or float, or a Go duration ("30s") for a duration')
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => is_bool($v))
                ->thenInvalid('%s is not a number or a duration')
            ->end()
        ->end();
    }

    /**
     * @param array{name: string, app_version: ?string, declaration: ?string, presets: list<string>, vars: array<string, array<string, mixed>>, files: array<string, array<string, mixed>>, validate: bool, skip_commands: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($config['declaration'] === null) {
            // Check the contract now, so a mistake fails cache:clear and
            // container compilation rather than the first request.
            try {
                (new Docuconf($config['name'], $config['app_version'], $config['vars'], $config['files'], null, $config['presets']))->spec();
            } catch (DeclarationError $e) {
                throw new InvalidConfigurationException("Invalid configuration for path \"docuconf\":\n" . $e->getMessage(), 0, $e);
            }
        }
        $services = $container->services();
        $services->set(Docuconf::class)
            ->args([
                $config['name'], $config['app_version'], $config['vars'], $config['files'], $config['declaration'], $config['presets'],
                service('container.env_var_processor')->nullOnInvalid(),
                param('kernel.environment'),
            ])
            ->public();
        $services->set(Values::class)
            ->factory([service(Docuconf::class), 'values'])
            ->public();
        $services->set(BootValidator::class)
            ->args([service(Docuconf::class), $config['validate'], $config['skip_commands']])
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
