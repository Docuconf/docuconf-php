<?php

declare(strict_types=1);

namespace Docuconf\Symfony;

use Docuconf\Duration;
use Docuconf\Export\Exporter;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * `%env(docuconf:PORT)%`: the declared variable's validated, typed value.
 *
 * Symfony's own processors (`%env(int:PORT)%`) cast; this one returns the
 * value docuconf checked against the declaration, or fails with every
 * problem in the configuration. It reads through Symfony's env
 * resolution, so vault secrets count. It goes innermost when chained:
 * `%env(default:fallback:docuconf:PORT)%`.
 *
 * Symfony env processors can only return scalars and arrays, so durations
 * come back in canonical Go form ("1m30s"), and
 * `%env(docuconf_seconds:TIMEOUT)%` gives seconds as a float. For a
 * `Docuconf\Duration` object, inject the `Docuconf\Values` service.
 */
final class EnvVarProcessor implements EnvVarProcessorInterface
{
    public function __construct(private readonly Docuconf $docuconf)
    {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed
    {
        if (str_contains($name, ':')) {
            throw new RuntimeException("Invalid env \"$prefix:$name\": $prefix: reads a declared variable, so it must come last, as in %env(default:fallback:$prefix:NAME)%.");
        }
        $value = $this->docuconf->values()->get($name);
        if ($prefix === 'docuconf_seconds') {
            return $value instanceof Duration ? $value->toSeconds() : null;
        }
        return Exporter::plain($value);
    }

    /** @return array<string, string> */
    public static function getProvidedTypes(): array
    {
        return ['docuconf' => 'bool|int|float|string|array', 'docuconf_seconds' => 'float'];
    }
}
