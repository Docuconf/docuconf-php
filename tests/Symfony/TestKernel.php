<?php

declare(strict_types=1);

namespace Docuconf\Tests\Symfony;

use Docuconf\Symfony\DocuconfBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $docuconf */
    public function __construct(private readonly array $docuconf, private readonly string $dir)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DocuconfBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false]);
        $container->extension('docuconf', $this->docuconf);
        $container->parameters()->set('orders.port', '%env(docuconf:ORDERS_PORT)%');
        $container->parameters()->set('orders.timeout', '%env(docuconf_seconds:ORDERS_TIMEOUT)%');
        $container->parameters()->set('orders.no_origins', null);
        $container->parameters()->set('orders.origins_or_default', '%env(default:orders.no_origins:docuconf:ORDERS_ORIGINS)%');
        $container->services()->set('orders.config', \ArrayObject::class)
            ->args([['port' => '%env(docuconf:ORDERS_PORT)%', 'timeout' => '%env(docuconf_seconds:ORDERS_TIMEOUT)%', 'origins' => '%env(docuconf:ORDERS_ORIGINS)%']])
            ->public();
    }

    public function getCacheDir(): string
    {
        return $this->dir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->dir . '/log';
    }

    public function getProjectDir(): string
    {
        return $this->dir;
    }
}
