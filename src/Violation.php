<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * One problem with one input, found at boot. `input` is a variable name or a
 * file input name; `code` is one of the stable codes of SPEC §11.2 item 5.
 * `message` never contains the value of a secret.
 */
final class Violation implements \JsonSerializable
{
    public const CODES = [
        'missing_required', 'invalid_type', 'out_of_range', 'pattern_mismatch', 'not_in_enum',
        'invalid_scheme', 'too_few_items', 'too_many_items', 'file_missing', 'file_unreadable',
        'file_too_large', 'file_malformed', 'schema_mismatch', 'certificate_invalid',
        'certificate_expiring', 'certificate_name_mismatch', 'key_mismatch', 'keystore_unreadable',
    ];

    public function __construct(
        public readonly string $input,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public function __toString(): string
    {
        return "{$this->input} [{$this->code}]: {$this->message}";
    }

    /** @return array{var: string, code: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['var' => $this->input, 'code' => $this->code, 'message' => $this->message];
    }
}
