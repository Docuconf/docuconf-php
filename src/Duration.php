<?php

declare(strict_types=1);

namespace Docuconf;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A length of time, held exactly in nanoseconds.
 *
 * Contracts write durations in Go syntax ("1m30s"); the app's env may carry
 * them in any of the wire encodings of SPEC §5 (go, iso8601, seconds,
 * timespan). Both parse into this one type. Only the go encoding takes a
 * sign, so only it can give a negative duration ("-5s").
 */
final class Duration implements JsonSerializable, Stringable
{
    public const NANOSECOND = 1;
    public const MICROSECOND = 1_000;
    public const MILLISECOND = 1_000_000;
    public const SECOND = 1_000_000_000;
    public const MINUTE = 60 * self::SECOND;
    public const HOUR = 60 * self::MINUTE;

    public const ENCODINGS = ['go', 'iso8601', 'seconds', 'timespan'];

    private const GO_UNITS = [
        'ns' => self::NANOSECOND,
        'us' => self::MICROSECOND,
        'µs' => self::MICROSECOND,
        'μs' => self::MICROSECOND,
        'ms' => self::MILLISECOND,
        's' => self::SECOND,
        'm' => self::MINUTE,
        'h' => self::HOUR,
    ];

    private function __construct(public readonly int $nanoseconds)
    {
    }

    public static function ofNanoseconds(int $nanoseconds): self
    {
        if ($nanoseconds < 0) {
            throw new InvalidArgumentException('a duration cannot be negative');
        }
        return new self($nanoseconds);
    }

    public static function ofSeconds(int $seconds): self
    {
        return self::ofNanoseconds(self::mul($seconds, self::SECOND));
    }

    public static function ofMilliseconds(int $milliseconds): self
    {
        return self::ofNanoseconds(self::mul($milliseconds, self::MILLISECOND));
    }

    /**
     * Parses a duration written in Go syntax, exactly as Go's
     * time.ParseDuration reads it (SPEC §5): an optional sign, then "0" or
     * numbers each followed by a unit ("1m30s", "1.5h", "-250ms", "+5s").
     *
     * @throws InvalidArgumentException when the text is not a valid Go duration
     */
    public static function fromGo(string $text): self
    {
        $negative = false;
        $body = $text;
        if ($body !== '' && ($body[0] === '-' || $body[0] === '+')) {
            $negative = $body[0] === '-';
            $body = substr($body, 1);
        }
        if ($body === '0') {
            return new self(0);
        }
        if ($body === '' || !preg_match('/^(?:[0-9]*(?:\.[0-9]*)?(?:ns|us|µs|μs|ms|s|m|h))+$/Du', $body)) {
            throw new InvalidArgumentException('not a Go duration, such as 1m30s');
        }
        preg_match_all('/([0-9]*)(?:\.([0-9]*))?(ns|us|µs|μs|ms|s|m|h)/u', $body, $parts, PREG_SET_ORDER);
        $total = 0;
        foreach ($parts as [, $int, $frac, $unit]) {
            if ($int === '' && $frac === '') {
                throw new InvalidArgumentException('not a Go duration, such as 1m30s');
            }
            $total = self::add($total, self::decimal($int, $frac, self::GO_UNITS[$unit]));
        }
        return new self($negative ? -$total : $total);
    }

    /**
     * Parses a duration in one of the wire encodings of SPEC §5.
     *
     * @throws InvalidArgumentException when the text is not in that encoding
     */
    public static function parse(string $text, string $encoding = 'go'): self
    {
        return match ($encoding) {
            'go' => self::fromGo($text),
            'iso8601' => self::fromIso8601($text),
            'seconds' => self::fromSeconds($text),
            'timespan' => self::fromTimespan($text),
            default => throw new InvalidArgumentException("unknown duration encoding \"$encoding\""),
        };
    }

    /**
     * ISO 8601 durations with days, hours, minutes and (fractional) seconds:
     * "PT90S", "PT1.5S", "PT1,5S", "P1DT2H". Upper case and unsigned. Years,
     * months and weeks are rejected: years and months have no fixed length,
     * and SPEC §5 leaves weeks out with them.
     */
    public static function fromIso8601(string $text): self
    {
        $num = '([0-9]+)(?:[.,]([0-9]+))?';
        $re = "/^P(?:{$num}D)?(?:T(?:{$num}H)?(?:{$num}M)?(?:{$num}S)?)?$/D";
        if (!preg_match($re, $text, $m, PREG_UNMATCHED_AS_NULL) || $text === 'P' || str_ends_with($text, 'T')) {
            throw new InvalidArgumentException('not an ISO 8601 duration, such as PT90S');
        }
        $units = [24 * self::HOUR, self::HOUR, self::MINUTE, self::SECOND];
        $total = 0;
        foreach ($units as $i => $unit) {
            $int = $m[1 + 2 * $i] ?? null;
            if ($int !== null) {
                $total = self::add($total, self::decimal($int, $m[2 + 2 * $i] ?? '', $unit));
            }
        }
        return new self($total);
    }

    /** A plain decimal number of seconds: "90", "1.5". */
    public static function fromSeconds(string $text): self
    {
        if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $text, $m)) {
            throw new InvalidArgumentException('not a number of seconds, such as 90 or 1.5');
        }
        return new self(self::decimal($m[1], $m[2] ?? '', self::SECOND));
    }

    /**
     * .NET TimeSpan form, as SPEC §5 reads it: "[d.]hh:mm:ss[.fffffff]", with
     * hh one or two digits below 24, mm and ss two digits below 60. Unsigned.
     */
    public static function fromTimespan(string $text): self
    {
        if (!preg_match('/^(?:([0-9]+)\.)?([0-9]{1,2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,7}))?$/D', $text, $m)) {
            throw new InvalidArgumentException('not a .NET TimeSpan, such as 00:01:30');
        }
        [$h, $min, $s] = [(int) $m[2], (int) $m[3], (int) $m[4]];
        if ($h > 23 || $min > 59 || $s > 59) {
            throw new InvalidArgumentException('not a .NET TimeSpan, such as 00:01:30');
        }
        $total = self::mul((int) $m[1], 24 * self::HOUR);
        $total = self::add($total, $h * self::HOUR + $min * self::MINUTE);
        $total = self::add($total, self::decimal((string) $s, $m[5] ?? '', self::SECOND));
        return new self($total);
    }

    /**
     * The canonical Go form of SPEC §11.2 item 3: units h, m, s, ms, us, ns,
     * each at most once, zero units omitted, "0s" for zero.
     */
    public function toString(): string
    {
        if ($this->nanoseconds === 0) {
            return '0s';
        }
        $out = $this->nanoseconds < 0 ? '-' : '';
        $rest = abs($this->nanoseconds);
        foreach (['h' => self::HOUR, 'm' => self::MINUTE, 's' => self::SECOND, 'ms' => self::MILLISECOND, 'us' => self::MICROSECOND, 'ns' => 1] as $unit => $size) {
            $n = intdiv($rest, $size);
            $rest -= $n * $size;
            if ($n > 0) {
                $out .= $n . $unit;
            }
        }
        return $out;
    }

    /** The duration written in a wire encoding, as #RenderDuration does. */
    public function encode(string $encoding): string
    {
        $ms = intdiv($this->nanoseconds, self::MILLISECOND);
        $secs = intdiv($ms, 1000);
        $frac = $ms % 1000;
        $fracStr = $frac === 0 ? '' : '.' . rtrim(sprintf('%03d', $frac), '0');
        return match ($encoding) {
            'go' => $this->toString(),
            'seconds' => $secs . $fracStr,
            'iso8601' => 'PT' . $secs . $fracStr . 'S',
            'timespan' => (intdiv($secs, 86400) > 0 ? intdiv($secs, 86400) . '.' : '')
                . sprintf('%02d:%02d:%02d', intdiv($secs % 86400, 3600), intdiv($secs % 3600, 60), $secs % 60) . $fracStr,
            default => throw new InvalidArgumentException("unknown duration encoding \"$encoding\""),
        };
    }

    public function toSeconds(): float
    {
        return $this->nanoseconds / self::SECOND;
    }

    public function toMilliseconds(): int
    {
        return intdiv($this->nanoseconds, self::MILLISECOND);
    }

    public function toDateInterval(): \DateInterval
    {
        $abs = abs($this->nanoseconds);
        $secs = intdiv($abs, self::SECOND);
        $interval = new \DateInterval('PT' . $secs . 'S');
        $interval->f = ($abs % self::SECOND) / self::SECOND;
        $interval->invert = $this->nanoseconds < 0 ? 1 : 0;
        return $interval;
    }

    public function compare(self $other): int
    {
        return $this->nanoseconds <=> $other->nanoseconds;
    }

    public function equals(self $other): bool
    {
        return $this->nanoseconds === $other->nanoseconds;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Lets `php artisan config:cache` (var_export) store a Duration.
     *
     * @param array{nanoseconds: int} $state
     */
    public static function __set_state(array $state): self
    {
        return new self($state['nanoseconds']);
    }

    /** $int.$frac units, exactly, in nanoseconds. */
    private static function decimal(string $int, string $frac, int $unit): int
    {
        $whole = $int === '' ? 0 : self::parseInt($int);
        $total = self::mul($whole, $unit);
        if ($frac !== '') {
            // Digits finer than a nanosecond are dropped, as Go does.
            $scale = 1;
            $value = 0;
            foreach (str_split(substr($frac, 0, 18)) as $digit) {
                $value = $value * 10 + (int) $digit;
                $scale *= 10;
            }
            $total = self::add($total, intdiv(self::mulFraction($value, $unit, $scale), 1));
        }
        return $total;
    }

    /** floor($value * $unit / $scale) without overflowing. */
    private static function mulFraction(int $value, int $unit, int $scale): int
    {
        // $value < $scale, so the result is below $unit; split to avoid overflow.
        $g = self::gcd($unit, $scale);
        $unit = intdiv($unit, $g);
        $scale = intdiv($scale, $g);
        $q = intdiv($value, $scale);
        $r = $value % $scale;
        $result = $q * $unit;
        // $r * $unit can overflow when $scale is large; then use floats for the remainder.
        if ($r !== 0 && $unit > intdiv(PHP_INT_MAX, $r)) {
            return $result + (int) floor($r / $scale * $unit);
        }
        return $result + intdiv($r * $unit, $scale);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }

    private static function parseInt(string $digits): int
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > 19 || (strlen($digits) === 19 && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException('too long a duration for 64-bit nanoseconds');
        }
        return (int) $digits;
    }

    private static function mul(int $a, int $b): int
    {
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new InvalidArgumentException('too long a duration for 64-bit nanoseconds');
        }
        return $a * $b;
    }

    private static function add(int $a, int $b): int
    {
        if ($a > PHP_INT_MAX - $b) {
            throw new InvalidArgumentException('too long a duration for 64-bit nanoseconds');
        }
        return $a + $b;
    }
}
