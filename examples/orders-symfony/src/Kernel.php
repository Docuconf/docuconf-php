<?php

namespace App;

use Docuconf\Symfony\DocuconfBundle;
use Docuconf\Values;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    // The typed configuration, with the secrets shown as "***".
    #[Route('/config')]
    public function config(Values $config): JsonResponse
    {
        return new JsonResponse($config->redacted());
    }

    // Payment webhooks, signed with any key in WEBHOOK_KEYS (see docuconf.yaml
    // for how to rotate it).
    #[Route('/webhooks/payments', methods: ['POST'])]
    public function paymentWebhook(Request $request, Values $config): Response
    {
        $body = $request->getContent();
        if (strlen($body) > Webhooks::MAX_BODY) {
            return new Response('body too large', 413);
        }
        /** @var list<string>|null $keys */
        $keys = $config->list('WEBHOOK_KEYS');
        $ok = Webhooks::verify($keys, $body, $request->headers->get('X-Signature'));
        return $ok ? new Response('', 204) : new Response('bad signature', 401);
    }
}
