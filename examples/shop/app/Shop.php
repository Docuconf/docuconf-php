<?php

declare(strict_types=1);

namespace App;

use Docuconf\Contract;
use Docuconf\Values;

/**
 * The shop service: one image, two roles (web and worker), configured by
 * contract.json, the contract this example checks in as-is and loads at
 * startup (contract-first). There is no server, database or queue: it
 * validates its configuration, prints one line and exits.
 */
final class Shop
{
    public const CONTRACT = __DIR__ . '/../contract.json';

    public static function contract(): Contract
    {
        $json = file_get_contents(self::CONTRACT);
        if ($json === false) {
            throw new \RuntimeException('cannot read ' . self::CONTRACT);
        }
        return Contract::fromJson($json);
    }

    /**
     * Loads the configuration from the process environment and prints what
     * this role would run. On any problem it prints every one to stderr,
     * without secret values, and exits 1.
     */
    public static function main(): int
    {
        $values = self::contract()->loadOrExit();
        echo self::summary($values), "\n";
        return 0;
    }

    /** The line the service prints once its configuration is valid, from the typed values. */
    public static function summary(Values $values): string
    {
        if ($values->string('DOCUCONF_ROLE') === 'web') {
            return sprintf('shop web: port %d, origins %s', $values->int('PORT'), implode(',', $values->list('ALLOWED_ORIGINS') ?? []));
        }
        return sprintf(
            'shop worker: queues %s, webhooks %s',
            implode(',', $values->list('QUEUES') ?? []),
            $values->bool('WEBHOOKS_ENABLED') ? 'on' : 'off',
        );
    }
}
