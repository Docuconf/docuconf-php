<?php

declare(strict_types=1);

namespace Docuconf\Laravel;

/**
 * The app runs with `config:cache` values that differ from what the
 * environment gives now, as when the cache was built into an image before
 * the real environment existed.
 */
final class StaleConfigCache extends \RuntimeException
{
}
