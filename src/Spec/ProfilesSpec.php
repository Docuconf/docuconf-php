<?php

declare(strict_types=1);

namespace Docuconf\Spec;

/**
 * A contract's profiles (SPEC §4.4): the profile files baked into the image,
 * as typed defaults per profile, and the variable that selects one.
 */
final class ProfilesSpec
{
    /**
     * @param array<string, array<string, mixed>> $defaults profile name => variable => typed value
     */
    public function __construct(
        public string $selector,
        public string $default,
        public array $defaults = [],
    ) {
    }

    /**
     * The profile in effect: the selector's value when the environment sets
     * it, read as SPEC §5 reads the selector's type (for a string selector
     * the empty string is a value), else `default`.
     *
     * @param array<string, string> $env
     */
    public function selected(ContractSpec $contract, #[\SensitiveParameter] array $env): string
    {
        $raw = $env[$this->selector] ?? null;
        $type = $contract->vars[$this->selector]->type ?? 'string';
        if ($raw !== null && ($raw !== '' || $type === 'string')) {
            return $raw;
        }
        return $this->default;
    }
}
