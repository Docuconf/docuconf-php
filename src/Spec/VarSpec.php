<?php

declare(strict_types=1);

namespace Docuconf\Spec;

use Docuconf\Duration;

/**
 * One environment variable of a contract (SPEC §4.2, §4.3).
 *
 * Built by the fluent declaration API or read from a contract's JSON; the
 * loader and the exporter only ever see this form, so both paths run the
 * same checks. Defaults and bounds are held as typed PHP values: ints,
 * floats, Duration objects, lists.
 */
final class VarSpec
{
    public const TYPES = ['string', 'int', 'float', 'bool', 'duration', 'url', 'enum', 'list', 'json'];
    public const LIST_ENCODINGS = ['csv', 'json', 'indexed'];

    public string $type = 'string';
    public string $description = '';
    public bool $required = false;
    public bool $secret = false;
    public ?string $group = null;
    /** @var list<string>|null */
    public ?array $examples = null;
    public ?string $configKey = null;
    /** @var array{message: string, replacedBy?: string}|null */
    public ?array $deprecated = null;

    public bool $hasDefault = false;
    public mixed $default = null;

    // string; maxLength also bounds a url or a json value. Lengths count
    // characters (Unicode code points), never bytes.
    public ?int $minLength = null;
    public ?int $maxLength = null;
    public ?string $pattern = null;

    // int, float, duration
    public int|float|Duration|null $min = null;
    public int|float|Duration|null $max = null;

    // duration and list
    public ?string $encoding = null;

    // url
    /** @var list<string>|null */
    public ?array $schemes = null;

    // enum
    /** @var list<string>|null */
    public ?array $values = null;

    // list
    public string $items = 'string';
    public string $separator = ',';
    public ?int $minItems = null;
    public ?int $maxItems = null;
    public ?int $itemMin = null;
    public ?int $itemMax = null;
    /** Bounds on the length of each item of a string list, in characters. */
    public ?int $itemMinLength = null;
    public ?int $itemMaxLength = null;

    // json
    /** @var array<string, mixed>|null */
    public ?array $schema = null;
    /** @var class-string|null the app's type the value binds to, when declared from a class */
    public ?string $class = null;

    public function __construct(public readonly string $name)
    {
    }

    /** The encoding in effect: the declared one, or the type's default. */
    public function encoding(): ?string
    {
        return match ($this->type) {
            'duration' => $this->encoding ?? 'go',
            'list' => $this->encoding ?? 'csv',
            default => null,
        };
    }
}
