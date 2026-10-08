<?php

declare(strict_types=1);

namespace Vitals\Format;

use Vitals\Builder;
use Vitals\Exception\BuildException;
use Vitals\Exception\FormatException;
use Vitals\Flag\ByteSizeFlag;
use Vitals\Internal\AbstractFormat;
use Vitals\Parser;
use Vitals\Validator;
use Vitals\ViolationCode;

/**
 * Byte quantities as in php.ini: `128M` <-> 134217728. Multiplier is 1024.
 *
 * Stricter than ini_parse_quantity(): decimal digits only (a leading `0` would be octal there),
 * no `0x`/`0o`/`0b` prefixes, no trailing garbage, no overflow.
 */
final readonly class ByteSize extends AbstractFormat implements Parser, Builder, Validator
{
    private const MULTIPLIERS = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];

    protected static function flagEnum(): string
    {
        return ByteSizeFlag::class;
    }

    /**
     * @return array{bytes: int, value: int, unit: ''|'K'|'M'|'G'}
     */
    public function parse(string $input): array
    {
        /** @var array{bytes: int, value: int, unit: ''|'K'|'M'|'G'} */
        return parent::parse($input);
    }

    /**
     * @return array{bytes: int, value: int, unit: ''|'K'|'M'|'G'}
     */
    protected function analyse(string $input): array
    {
        $start = 0;
        $end = strlen($input);
        if ($this->config->has(ByteSizeFlag::AllowWhitespace)) {
            $start = strspn($input, " \t\n\r\v\f");
            $end = $start === $end ? $end : strlen(rtrim($input, " \t\n\r\v\f"));
        }

        if ($start === $end) {
            throw FormatException::of(ViolationCode::CommonEmptyInput, 'byte size is empty', $start);
        }

        $pos = $start;
        $negative = $input[$pos] === '-';
        if ($negative) {
            $pos++;
        }

        $digits = strspn($input, '0123456789', $pos, $end - $pos);
        if ($digits === 0) {
            throw FormatException::of(ViolationCode::BytesizeInvalidNumber, 'expected decimal digits', $pos);
        }
        if ($input[$pos] === '0' && $digits > 1) {
            throw FormatException::of(ViolationCode::BytesizeInvalidPrefix, 'leading 0 (octal) is not allowed', $pos);
        }
        if ($input[$pos] === '0' && $pos + 1 < $end && in_array($input[$pos + 1], ['x', 'X', 'o', 'O', 'b', 'B'], true)) {
            throw FormatException::of(ViolationCode::BytesizeInvalidPrefix, sprintf('prefix 0%s is not allowed', $input[$pos + 1]), $pos);
        }

        $number = substr($input, $pos, $digits);
        $value = $this->toInt($number, $negative, $pos);
        $pos += $digits;

        $unit = '';
        if ($pos < $end) {
            $unit = strtoupper($input[$pos]);
            if (!isset(self::MULTIPLIERS[$unit]) || $unit === '') {
                throw FormatException::of(ViolationCode::BytesizeInvalidUnit, sprintf('unknown unit "%s"', $input[$pos]), $pos);
            }
            $pos++;
        }

        if ($pos < $end) {
            throw FormatException::of(ViolationCode::CommonTrailingData, 'unexpected characters after the unit', $pos);
        }

        /** @var ''|'K'|'M'|'G' $unit */
        $bytes = $this->multiply($value, $unit);
        if ($bytes === null) {
            throw FormatException::of(ViolationCode::BytesizeOverflow, 'byte size exceeds the integer range', $start);
        }

        return ['bytes' => $bytes, 'value' => $value, 'unit' => $unit];
    }

    public function build(array $parts): string
    {
        $unknown = array_diff(array_keys($parts), ['bytes', 'value', 'unit']);
        if ($unknown !== []) {
            throw new BuildException(sprintf('unknown key "%s"', (string) reset($unknown)));
        }

        $bytes = $parts['bytes'] ?? null;
        $value = $parts['value'] ?? null;
        $unit = $parts['unit'] ?? null;

        if ($bytes !== null && !is_int($bytes)) {
            throw new BuildException('bytes must be an int');
        }
        if ($value !== null && !is_int($value)) {
            throw new BuildException('value must be an int');
        }
        if ($unit !== null && (!is_string($unit) || !isset(self::MULTIPLIERS[$unit]))) {
            throw new BuildException("unit must be one of '', 'K', 'M', 'G'");
        }

        if ($value === null) {
            if ($unit !== null && $unit !== '') {
                throw new BuildException('unit given without value');
            }
            if ($bytes === null) {
                throw new BuildException('either bytes or value is required');
            }

            return $this->largestUnit($bytes);
        }

        $unit ??= '';
        $computed = $this->multiply($value, $unit);
        if ($computed === null) {
            throw new BuildException('byte size exceeds the integer range');
        }
        if ($bytes !== null && $bytes !== $computed) {
            throw new BuildException(sprintf('bytes %d conflicts with %d%s', $bytes, $value, $unit));
        }

        return $value . $unit;
    }

    private function toInt(string $digits, bool $negative, int $offset): int
    {
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw FormatException::of(ViolationCode::BytesizeOverflow, 'number exceeds the integer range', $offset);
        }

        if ($negative) {
            return $digits === $limit ? PHP_INT_MIN : -(int) $digits;
        }

        return (int) $digits;
    }

    private function multiply(int $value, string $unit): ?int
    {
        $result = $value * self::MULTIPLIERS[$unit];

        return is_int($result) ? $result : null;
    }

    private function largestUnit(int $bytes): string
    {
        foreach (['G', 'M', 'K'] as $unit) {
            if ($bytes !== 0 && $bytes % self::MULTIPLIERS[$unit] === 0) {
                return intdiv($bytes, self::MULTIPLIERS[$unit]) . $unit;
            }
        }

        return (string) $bytes;
    }
}
