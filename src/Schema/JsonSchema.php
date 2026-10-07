<?php

declare(strict_types=1);

namespace Docuconf\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates data against a JSON Schema (draft 2020-12) with opis/json-schema.
 *
 * @internal
 */
final class JsonSchema
{
    /**
     * Returns the first problem, or null when $value matches $schema.
     *
     * @param array<string, mixed> $schema
     * @param string|null $json the value's JSON text, when there is one; it
     *                          keeps `{}` and `[]` apart, which PHP arrays do not
     */
    public static function validate(array $schema, mixed $value, ?string $json = null): ?string
    {
        $data = json_decode($json ?? Json::encode($value));
        $validator = new Validator();
        $validator->setMaxErrors(1);
        try {
            $result = $validator->validate($data, Json::encode($schema));
        } catch (\Throwable $e) {
            return 'the schema itself is invalid: ' . $e->getMessage();
        }
        if ($result->isValid()) {
            return null;
        }
        $error = $result->error();
        if ($error === null) {
            return 'does not match';
        }
        $formatted = (new ErrorFormatter())->formatFlat($error);
        $path = '/' . implode('/', $error->data()->fullPath());
        return 'at ' . $path . ': ' . ($formatted[0] ?? 'does not match');
    }
}
