<?php

declare(strict_types=1);

namespace Docuconf\Spec;

/**
 * A whole contract: the service's metadata, its variables and its files,
 * and, in contract-first mode, its profiles and overlays.
 */
final class ContractSpec
{
    /** @var array<string, VarSpec> */
    public array $vars = [];
    /** @var array<string, FileSpec> */
    public array $files = [];
    /** Profiles (SPEC §4.4); contract-first mode only. */
    public ?ProfilesSpec $profiles = null;
    /** @var array<string, OverlaySpec> config-file overlays (SPEC §4.7); contract-first mode only */
    public array $overlays = [];

    public function __construct(public string $name, public ?string $appVersion = null)
    {
    }

    /** @return array<string, VarSpec> sorted by name */
    public function sortedVars(): array
    {
        $vars = $this->vars;
        ksort($vars, SORT_STRING);
        return $vars;
    }

    /** @return array<string, FileSpec> sorted by name */
    public function sortedFiles(): array
    {
        $files = $this->files;
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, OverlaySpec> sorted by name */
    public function sortedOverlays(): array
    {
        $overlays = $this->overlays;
        ksort($overlays, SORT_STRING);
        return $overlays;
    }
}
