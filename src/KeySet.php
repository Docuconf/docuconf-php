<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * The value of a `keySet` variable (SPEC §4.3, §6.1): secret keys that are
 * all valid at once, so one can be rotated with an overlap in which the old
 * and the new key both work. It is for the side that verifies: webhook
 * signatures, inbound API keys, HMAC-signed tokens.
 *
 * The keys keep the order the platform gave them. Like Secret, a KeySet
 * never prints them: echo, var_dump, print_r, var_export, json_encode and
 * Symfony's VarDumper show "***".
 *
 * ```php
 * $keys = $config->keySet('WEBHOOK_KEYS');
 * $ok = $keys->verify(fn (string $key) => hash_equals(hash_hmac('sha256', $body, $key), $signature));
 * $known = $keys->contains($request->headers->get('X-Api-Key') ?? '');
 * ```
 */
final class KeySet implements \Countable, \JsonSerializable, \Stringable
{
    /**
     * @param list<string> $keys the keys, in order
     */
    public function __construct(#[\SensitiveParameter] array $keys)
    {
        if (!array_is_list($keys)) {
            throw new \InvalidArgumentException('docuconf: a KeySet takes a list of keys');
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('docuconf: every key of a KeySet must be a string, not ' . get_debug_type($key));
            }
        }
        // Held outside the object, where no debug printer can reach it.
        Vault::put($this, $keys);
    }

    /**
     * The keys, in order, each wrapped so that it does not print.
     *
     * @return list<Secret>
     */
    public function keys(): array
    {
        return array_map(fn (string $key) => new Secret($key), $this->reveal());
    }

    /**
     * The keys as plain strings, in order. Only for code that needs them all
     * at once, such as a signing library that takes a list of keys.
     *
     * @return list<string>
     */
    public function reveal(): array
    {
        /** @var list<string> */
        return Vault::get($this);
    }

    /**
     * Whether $candidate is one of the keys, in constant time: every key is
     * compared in full, so the time taken says neither which key matched nor
     * how much of one did.
     */
    public function contains(#[\SensitiveParameter] string $candidate): bool
    {
        $found = 0;
        foreach ($this->reveal() as $key) {
            // Not short-circuited: hash_equals runs for every key.
            $found |= (int) hash_equals($key, $candidate);
        }
        return $found === 1;
    }

    /**
     * Runs $check with every key and returns whether any passed. It never
     * stops at the first match, so the time taken does not say which key
     * matched. $check is the caller's comparison, such as an HMAC check:
     *
     * ```php
     * $keys->verify(fn (string $key) => hash_equals(hash_hmac('sha256', $body, $key), $signature));
     * ```
     *
     * @param callable(string): bool $check
     */
    public function verify(callable $check): bool
    {
        $ok = 0;
        foreach ($this->reveal() as $key) {
            $ok |= (int) ($check($key) === true);
        }
        return $ok === 1;
    }

    public function count(): int
    {
        return count($this->reveal());
    }

    public function __toString(): string
    {
        return Values::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return Values::REDACTED;
    }

    /** @return array{keys: string} */
    public function __debugInfo(): array
    {
        return ['keys' => Values::REDACTED];
    }

    public function __clone()
    {
        throw new \LogicException('docuconf: a KeySet cannot be cloned');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('docuconf: a KeySet cannot be serialized; store the configuration, not its values');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('docuconf: a KeySet cannot be unserialized');
    }
}
