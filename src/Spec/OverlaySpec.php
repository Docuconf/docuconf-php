<?php

declare(strict_types=1);

namespace Docuconf\Spec;

/**
 * One config-file overlay (SPEC §4.7): a file the platform mounts between
 * the files baked into the image and the environment. Each variable with a
 * `configKey` is read from it at that key, split on `keySeparator`.
 */
final class OverlaySpec
{
    public const FORMATS = ['json', 'yaml', 'toml'];

    public ?string $description = null;
    public string $format = '';
    public string $path = '';
    public string $keySeparator = '';
    public string $reload = 'restart';

    public function __construct(public readonly string $name)
    {
    }
}
