<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * RE2 patterns on top of PCRE.
 *
 * Contracts use RE2 (SPEC §4.3): the platform matches with CUE's `=~`, which
 * is Go's regexp. PCRE accepts a superset of RE2's syntax and differs in a
 * few places, so this class
 *
 *  - rejects what RE2 lacks (backreferences, lookaround, atomic groups,
 *    possessive quantifiers, recursion, conditionals, callouts and verbs,
 *    `\G`, `\K`, `\R`, `\X`, `\h`, `\e`, the `x` flag, repeat counts above
 *    1000, and other escapes RE2 does not define), and
 *  - compiles the rest so PCRE matches exactly as RE2 does: a partial
 *    (unanchored) match, `$` only at the very end without `(?m)`, UTF-8
 *    characters, and ASCII-only `\d`, `\w`, `\s` and `\b`, as in RE2.
 */
final class Re2
{
    /**
     * Returns why $pattern is not RE2, or null when it is.
     */
    public static function check(string $pattern): ?string
    {
        if (!mb_check_encoding($pattern, 'UTF-8')) {
            return 'pattern is not valid UTF-8';
        }
        $len = strlen($pattern);
        $inClass = false;
        $afterQuantifier = false;
        $lazy = false;
        $atomStart = true;
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if ($i + 1 >= $len) {
                    return 'trailing backslash';
                }
                $e = $pattern[$i + 1];
                if ($e === 'Q') {
                    $end = strpos($pattern, '\\E', $i + 2);
                    $i = $end === false ? $len : $end + 1;
                    $afterQuantifier = false;
                    $atomStart = false;
                    continue;
                }
                $err = self::checkEscape($pattern, $i, $inClass);
                if ($err !== null) {
                    return $err;
                }
                $i++;
                if ($e === 'p' || $e === 'P') {
                    if (($pattern[$i + 1] ?? '') === '{') {
                        $close = strpos($pattern, '}', $i + 1);
                        if ($close === false) {
                            return 'unterminated \\p{...}';
                        }
                        $i = $close;
                    } else {
                        $i++;
                    }
                } elseif ($e === 'x') {
                    if (($pattern[$i + 1] ?? '') === '{') {
                        $close = strpos($pattern, '}', $i + 1);
                        $i = $close === false ? $len : $close;
                    } else {
                        $i += 2;
                    }
                }
                $afterQuantifier = false;
                $atomStart = false;
                continue;
            }
            if ($inClass) {
                if ($c === '[' && ($pattern[$i + 1] ?? '') === ':') {
                    $close = strpos($pattern, ':]', $i + 2);
                    if ($close !== false) {
                        $i = $close + 1;
                    }
                } elseif ($c === ']') {
                    $inClass = false;
                }
                continue;
            }
            switch ($c) {
                case '[':
                    $inClass = true;
                    // A ']' right after '[' or '[^' is a literal.
                    if (($pattern[$i + 1] ?? '') === '^') {
                        $i++;
                    }
                    if (($pattern[$i + 1] ?? '') === ']') {
                        $i++;
                    }
                    $afterQuantifier = false;
                    $atomStart = false;
                    break;
                case '(':
                    if (($pattern[$i + 1] ?? '') === '*') {
                        return 'PCRE verbs such as (*UTF) are not RE2';
                    }
                    if (($pattern[$i + 1] ?? '') === '?') {
                        $err = self::checkGroup(substr($pattern, $i + 2));
                        if ($err !== null) {
                            return $err;
                        }
                        // Skip the group's prefix: "?:", "?P<name>", "?<name>", "?i)" or "?i:".
                        preg_match('/\G\(\?(?:P?<[A-Za-z0-9_]+>|[imsU-]*[:)])/', $pattern, $m, 0, $i);
                        $i += strlen($m[0] ?? '(?') - 1;
                    }
                    $afterQuantifier = false;
                    $atomStart = true;
                    break;
                case '*':
                case '+':
                case '?':
                    if ($afterQuantifier) {
                        if ($c === '?' && !$lazy) {
                            // Lazy quantifier: a*? a+? a?? a{n}?
                            $lazy = true;
                            break;
                        }
                        return $c === '+'
                            ? 'possessive quantifiers are not RE2'
                            : 'nested repetition operators are not RE2';
                    }
                    if ($atomStart) {
                        return "missing argument to repetition operator $c";
                    }
                    $afterQuantifier = true;
                    $lazy = false;
                    break;
                case '{':
                    if (preg_match('/\G\{([0-9]+)(?:(,)([0-9]*))?\}/', $pattern, $m, 0, $i)) {
                        foreach ([$m[1], $m[3] ?? ''] as $n) {
                            if ($n !== '' && (int) $n > 1000) {
                                return 'repeat count above 1000 is not RE2';
                            }
                        }
                        if (isset($m[3]) && $m[3] !== '' && (int) $m[3] < (int) $m[1]) {
                            return "invalid repeat count {$m[0]}";
                        }
                        if ($afterQuantifier) {
                            return 'nested repetition operators are not RE2';
                        }
                        if ($atomStart) {
                            return 'missing argument to repetition operator';
                        }
                        $i += strlen($m[0]) - 1;
                        $afterQuantifier = true;
                        $lazy = false;
                        break;
                    }
                    // A '{' that does not start a repeat is a literal.
                    $afterQuantifier = false;
                    $atomStart = false;
                    break;
                case '|':
                    $afterQuantifier = false;
                    $atomStart = true;
                    break;
                case ')':
                case '^':
                case '$':
                    $afterQuantifier = false;
                    $atomStart = $c !== ')';
                    if ($c === '$' || $c === '^') {
                        $atomStart = false;
                    }
                    break;
                default:
                    $afterQuantifier = false;
                    $atomStart = false;
            }
        }
        if ($inClass) {
            return 'missing closing ]';
        }
        if (@preg_match(self::toPcre($pattern), '') === false) {
            return 'invalid pattern: ' . preg_last_error_msg();
        }
        return null;
    }

    /** Whether $value contains a match of the RE2 $pattern anywhere. */
    public static function matches(string $pattern, string $value): bool
    {
        return @preg_match(self::toPcre($pattern), $value) === 1;
    }

    /**
     * The PCRE form of an RE2 pattern: UTF-8 without Unicode properties for
     * \d, \w, \s and \b (as RE2), and `$` matching only at the end (`D`).
     */
    public static function toPcre(string $pattern): string
    {
        $body = str_replace("\x01", '\\x{01}', $pattern);
        // RE2's \v is a vertical tab; PCRE's is any vertical whitespace.
        $body = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\v/', '$1\\x{0B}', $body) ?? $body;
        return "\x01(*UTF)(?:" . $body . ")\x01D";
    }

    private static function checkEscape(string $pattern, int $i, bool $inClass): ?string
    {
        $e = $pattern[$i + 1];
        if (ctype_digit($e)) {
            if ($e === '0') {
                return null;
            }
            $next = $pattern[$i + 2] ?? '';
            if ($e <= '7' && $next >= '0' && $next <= '7') {
                return null; // octal \12
            }
            return 'backreferences are not RE2';
        }
        if (!ctype_alpha($e)) {
            return null; // \. \\ \- and other escaped punctuation
        }
        $ok = $inClass ? 'afnrtvxdDsSwWpP' : 'afnrtvxdDsSwWpPbBAzC';
        if (str_contains($ok, $e)) {
            return null;
        }
        return match ($e) {
            'k', 'g' => 'backreferences are not RE2',
            'G' => '\\G is not RE2',
            'K' => '\\K is not RE2',
            'Z' => '\\Z is not RE2; use \\z',
            default => "\\$e is not RE2",
        };
    }

    private static function checkGroup(string $rest): ?string
    {
        if ($rest === '') {
            return 'missing closing )';
        }
        if (str_starts_with($rest, ':')) {
            return null;
        }
        if (preg_match('/^P?<[A-Za-z_][A-Za-z0-9_]*>/', $rest)) {
            return null;
        }
        if (preg_match('/^[imsU-]+[:)]/', $rest) && !preg_match('/^[^:)]*-[^:)]*-/', $rest)) {
            return null;
        }
        return match (true) {
            str_starts_with($rest, '='), str_starts_with($rest, '!') => 'lookahead is not RE2',
            str_starts_with($rest, '<='), str_starts_with($rest, '<!') => 'lookbehind is not RE2',
            str_starts_with($rest, '>') => 'atomic groups are not RE2',
            str_starts_with($rest, 'P='), str_starts_with($rest, 'P>'), str_starts_with($rest, '&') => 'backreferences and recursion are not RE2',
            str_starts_with($rest, 'R'), (bool) preg_match('/^[-+]?[0-9]/', $rest) => 'recursion is not RE2',
            str_starts_with($rest, '(') => 'conditionals are not RE2',
            str_starts_with($rest, '#') => 'comments are not RE2',
            str_starts_with($rest, 'C') => 'callouts are not RE2',
            (bool) preg_match('/^[a-zA-Z-]+[:)]/', $rest) => 'only the flags i, m, s and U are RE2',
            default => 'unsupported group (?' . mb_substr($rest, 0, 3) . '... is not RE2',
        };
    }
}
