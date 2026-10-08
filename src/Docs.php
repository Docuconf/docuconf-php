<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Where an input's `description` and `details` come from (SPEC §4.2, §14.7):
 * the PHPDoc comment written before the declaration, such as a doc comment
 * on the line before `$env->ifPresent('PORT')->isInteger()`.
 *
 * The comment's first paragraph is the description (on one line, without a
 * final period) and the rest is the details, in CommonMark. `describe()` and
 * `details()` set either one explicitly and win over the comment. PHPDoc
 * syntax is converted: `{@link X}` and `{@see X}` become code spans (or
 * links, for a URL), `@see` and `@link` tags sentences, and other tags
 * (`@var`, `@param`...) are dropped.
 *
 * The comment is found with PHP's tokenizer: it is the doc comment directly
 * before the statement, or the array element (`'port' => Env::int(...)`),
 * that makes the call.
 *
 * @internal
 */
final class Docs
{
    /** The most characters (Unicode code points) details may have (SPEC §4.2). */
    public const MAX_DETAILS = 4000;

    /** @var array<string, list<array{int, int, string, list<int>}>> per file: [first line, last line, doc, depth-0 lines] */
    private static array $elements = [];

    /**
     * The file and line of the app's code that called into the SDK, or null
     * when the caller is the SDK itself (the Laravel and Symfony
     * integrations, contract-first mode).
     *
     * @return array{string, int}|null
     */
    public static function callSite(): ?array
    {
        $src = __DIR__ . DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) {
                return null;
            }
            if (str_starts_with($file, $src)) {
                // Called from Declaration or a builder: keep looking. Called
                // from an integration: it reads the comment itself.
                if (!str_ends_with($file, DIRECTORY_SEPARATOR . 'Declaration.php') && !str_ends_with($file, DIRECTORY_SEPARATOR . 'Docs.php')) {
                    return null;
                }
                continue;
            }
            return [$file, (int) ($frame['line'] ?? 0)];
        }
        return null;
    }

    /** The raw doc comment before the statement or array element at $file:$line, or null. */
    public static function commentAt(string $file, int $line): ?string
    {
        $best = null;
        foreach (self::elements($file) as [$first, $last, $doc, $lines]) {
            if ($line < $first || $line > $last || !in_array($line, $lines, true)) {
                continue;
            }
            if ($best === null || $first > $best[0]) {
                $best = [$first, $doc];
            }
        }
        return $best[1] ?? null;
    }

    /**
     * Splits a doc comment into its description (first paragraph) and its
     * details (the rest, as CommonMark; null when there is none).
     *
     * @return array{string, ?string}
     */
    public static function split(string $comment): array
    {
        $text = self::strip($comment);
        $parts = preg_split('/\n[ \t]*\n/', $text, 2) ?: [''];
        $first = trim((string) preg_replace('/\s+/', ' ', $parts[0]));
        if (str_starts_with($first, '@')) {
            // Only tags: no description.
            $first = '';
            $parts = ['', $text];
        }
        if (str_ends_with($first, '.') && !str_ends_with($first, '..')) {
            $first = substr($first, 0, -1);
        }
        $first = self::inline($first);
        $rest = isset($parts[1]) ? self::toMarkdown($parts[1]) : '';
        return [$first, $rest === '' ? null : $rest];
    }

    /** Converts PHPDoc text to CommonMark. */
    public static function toMarkdown(string $text): string
    {
        $out = [];
        $fenced = false;
        $lines = explode("\n", $text);
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $fenced = !$fenced;
                $out[] = $line;
                continue;
            }
            if ($fenced) {
                $out[] = $line;
                continue;
            }
            if (preg_match('/^@(\w+)\s*(.*)$/', $line, $m) === 1) {
                $body = trim($m[2]);
                while ($i + 1 < $n && trim($lines[$i + 1]) !== '' && preg_match('/^\s*@/', $lines[$i + 1]) !== 1) {
                    $body .= ' ' . trim($lines[++$i]);
                }
                $sentence = self::tag($m[1], $body);
                if ($sentence !== null) {
                    $out[] = '';
                    $out[] = $sentence;
                    $out[] = '';
                }
                continue;
            }
            $out[] = self::inline($line);
        }
        $md = (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out));
        return trim($md, "\n");
    }

    /**
     * Problems with details: blank, or longer than 4000 code points.
     *
     * @return list<string>
     */
    public static function problems(?string $details): array
    {
        if ($details === null) {
            return [];
        }
        if (trim($details) === '') {
            return ['details must not be blank'];
        }
        $n = mb_strlen($details, 'UTF-8');
        if ($n > self::MAX_DETAILS) {
            return ["details are $n characters; at most " . self::MAX_DETAILS . ' are allowed'];
        }
        return [];
    }

    /** The comment's text without the comment markers and leading asterisks. */
    private static function strip(string $comment): string
    {
        $body = (string) preg_replace(['#^\s*/\*\*+#', '#\*+/\s*$#'], '', $comment);
        $lines = array_map(
            fn (string $l) => (string) preg_replace('/^\s*\* ?/', '', rtrim($l)),
            explode("\n", $body),
        );
        return str_replace('{@*}', '*/', trim(implode("\n", $lines), "\n "));
    }

    private static function tag(string $name, string $body): ?string
    {
        if ($name !== 'see' && $name !== 'link') {
            return null; // @var, @param, @return, @deprecated...: API docs, not configuration docs
        }
        $parts = preg_split('/\s+/', $body, 2) ?: [''];
        $target = $parts[0];
        if ($target === '') {
            return null;
        }
        $ref = preg_match('#^https?://#', $target) === 1 ? "<$target>" : "`$target`";
        return 'See ' . $ref . (isset($parts[1]) ? ' (' . self::inline($parts[1]) . ')' : '') . '.';
    }

    /** `{@link X}`, `{@see X}` and `{@inheritDoc}` outside code spans. */
    private static function inline(string $line): string
    {
        $pieces = preg_split('/(`[^`]*`)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        foreach ($pieces as $k => $piece) {
            if ($k % 2 === 1) {
                continue;
            }
            $piece = (string) preg_replace('/\{@inheritDoc\}/i', '', $piece);
            $pieces[$k] = (string) preg_replace_callback(
                '/\{@(?:link|see|linkplain)\s+([^\s}]+)(?:\s+([^}]*))?\}/',
                function (array $m): string {
                    $title = isset($m[2]) ? trim($m[2]) : '';
                    if (preg_match('#^https?://#', $m[1]) === 1) {
                        return $title !== '' ? "[$title]({$m[1]})" : "<{$m[1]}>";
                    }
                    return '`' . ($title !== '' ? $title : $m[1]) . '`';
                },
                $piece,
            );
        }
        return implode('', $pieces);
    }

    /**
     * Every doc comment of $file with the element it documents.
     *
     * @return list<array{int, int, string, list<int>}>
     */
    private static function elements(string $file): array
    {
        if (isset(self::$elements[$file])) {
            return self::$elements[$file];
        }
        $source = @file_get_contents($file);
        if ($source === false) {
            return self::$elements[$file] = [];
        }
        try {
            $tokens = \PhpToken::tokenize($source);
        } catch (\Throwable) {
            return self::$elements[$file] = [];
        }
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $open = ['(', '[', '{'];
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t->id !== T_DOC_COMMENT) {
                continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && in_array($tokens[$j]->id, $skip, true)) {
                $j++;
            }
            if (!isset($tokens[$j])) {
                continue;
            }
            $depth = 0;
            $lines = [];
            $last = $tokens[$j]->line;
            for ($k = $j; isset($tokens[$k]); $k++) {
                $tok = $tokens[$k];
                $text = $tok->text;
                if ($depth === 0 && ($text === ';' || $text === ',')) {
                    break;
                }
                if (in_array($text, $open, true) || $tok->id === T_CURLY_OPEN || $tok->id === T_DOLLAR_OPEN_CURLY_BRACES || $tok->id === T_ATTRIBUTE) {
                    if ($depth === 0) {
                        $lines[] = $tok->line;
                    }
                    $depth++;
                } elseif ($text === ')' || $text === ']' || $text === '}') {
                    $depth--;
                    if ($depth < 0) {
                        break;
                    }
                    if ($depth === 0) {
                        $lines[] = $tok->line;
                    }
                } elseif ($depth === 0 && !in_array($tok->id, $skip, true)) {
                    $lines[] = $tok->line;
                }
                $last = $tok->line + substr_count($text, "\n");
            }
            $out[] = [$tokens[$j]->line, $last, $t->text, array_values(array_unique($lines))];
        }
        return self::$elements[$file] = $out;
    }
}
