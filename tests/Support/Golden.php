<?php

declare(strict_types=1);

namespace Docuconf\Tests\Support;

/**
 * Comparisons of exports with committed golden files.
 */
final class Golden
{
    /**
     * Replaces the value of metadata.generator.version. It is Version::VERSION, which every release PR bumps, so
     * comparisons with a committed golden file ignore it; everything else must match exactly.
     */
    public static function withoutGeneratorVersion(string $cue): string
    {
        return (string) preg_replace('/(generator:\s*\{[^{}]*?\bversion:\s*)"[^"]*"/', '$1"<generator-version>"', $cue);
    }
}
