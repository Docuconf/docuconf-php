<?php

namespace App;

use Docuconf\Symfony\DocuconfBundle;
use Docuconf\Values;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Attribute\Route;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DocuconfBundle();
    }

    #[Route('/healthz')]
    public function healthz(): Response
    {
        return new Response('ok', 200, ['Content-Type' => 'text/plain']);
    }

    // The typed configuration, with the secret shown as "***".
    #[Route('/config')]
    public function config(Values $config): JsonResponse
    {
        return new JsonResponse($config->redacted());
    }
}
