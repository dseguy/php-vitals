<?php

declare(strict_types=1);

namespace Vitals\Format;

use Vitals\Builder;
use Vitals\Exception\BuildException;
use Vitals\Exception\FormatException;
use Vitals\Flag\IniFlag;
use Vitals\Internal\AbstractFormat;
use Vitals\Parser;
use Vitals\Validator;
use Vitals\ViolationCode;

/**
 * INI files with the raw semantics of parse_ini_string(INI_SCANNER_RAW): values stay strings,
 * no type conversion, no ${VAR} or constant expansion.
 *
 * Quoted values follow PHP: the quotes are removed, a backslash is kept and protects the next
 * character. Lines PHP silently ignores or misreads (bare words, '#' lines, text after a section
 * or a closing quote) are errors here.
 */
final readonly class Ini extends AbstractFormat implements Parser, Builder, Validator
{
    private const RESERVED = ['null', 'yes', 'no', 'true', 'false', 'on', 'off', 'none'];
    private const KEY_FORBIDDEN = '?{}|&~!()^"$;[]=';

    protected static function flagEnum(): string
    {
        return IniFlag::class;
    }

    /**
     * @return array{global: array<string, string|array<int|string, string>>, sections: array<string, array<string, string|array<int|string, string>>>}
     */
    public function parse(string $input): array
    {
        /** @var array{global: array<string, string|array<int|string, string>>, sections: array<string, array<string, string|array<int|string, string>>>} */
        return parent::parse($input);
    }

    protected function analyse(string $input): array
    {
        $nul = strpos($input, "\0");
        if ($nul !== false) {
            throw FormatException::of(ViolationCode::CommonUnexpectedCharacter, 'NUL byte', $nul);
        }

        $global = [];
        $sections = [];
        $section = null;
        $items = 0;

        $length = strlen($input);
        for ($offset = 0; $offset <= $length; $offset = $next) {
            $end = $offset + strcspn($input, "\r\n", $offset);
            $next = $end + (substr($input, $end, 2) === "\r\n" ? 2 : 1);
            $line = substr($input, $offset, $end - $offset);

            $lead = strspn($line, " \t");
            $content = substr($line, $lead);
            $at = $offset + $lead;

            if ($content === '' || $content[0] === ';') {
                continue;
            }
            if ($content[0] === '#') {
                throw FormatException::of(ViolationCode::CommonUnexpectedCharacter, '"#" does not start a comment, use ";"', $at);
            }

            if ($content[0] === '[') {
                $section = $this->section($content, $at);
                if (array_key_exists($section, $sections) && !$this->config->has(IniFlag::AllowDuplicateSections)) {
                    throw FormatException::of(ViolationCode::IniDuplicateSection, sprintf('section "%s" is repeated', $section), $at);
                }
                $sections[$section] = [];
                continue;
            }

            if (++$items > $this->config->maxItems()) {
                throw FormatException::of(ViolationCode::CommonTooManyItems, sprintf('more than %d directives', $this->config->maxItems()), $at);
            }

            [$key, $index, $value] = $this->directive($content, $at);
            if ($section === null) {
                $this->store($global, $key, $index, $value, $at);
            } else {
                $this->store($sections[$section], $key, $index, $value, $at);
            }
        }

        return ['global' => $global, 'sections' => $sections];
    }

    public function build(array $parts): string
    {
        $unknown = array_diff(array_keys($parts), ['global', 'sections']);
        if ($unknown !== []) {
            throw new BuildException(sprintf('unknown key "%s"', (string) reset($unknown)));
        }
        $global = $parts['global'] ?? [];
        $sections = $parts['sections'] ?? [];
        if (!is_array($global) || !is_array($sections)) {
            throw new BuildException('global and sections must be arrays');
        }

        $lines = $this->directiveLines($global);
        foreach ($sections as $name => $directives) {
            $name = (string) $name;
            if (self::sectionNameError($name) !== null) {
                throw new BuildException(sprintf('invalid section name "%s": %s', $name, self::sectionNameError($name)));
            }
            if (!is_array($directives)) {
                throw new BuildException(sprintf('section "%s" must be an array', $name));
            }
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '[' . $name . ']';
            array_push($lines, ...$this->directiveLines($directives));
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private function section(string $content, int $at): string
    {
        $close = strpos($content, ']');
        if ($close === false) {
            throw FormatException::of(ViolationCode::IniUnterminatedSection, 'section without closing "]"', $at);
        }

        $name = substr($content, 1, $close - 1);
        $error = self::sectionNameError($name);
        if ($error !== null) {
            throw FormatException::of(ViolationCode::IniInvalidSectionName, $error, $at + 1);
        }

        self::expectLineEnd($content, $close + 1, $at);

        return $name;
    }

    private static function sectionNameError(string $name): ?string
    {
        if (trim($name) === '') {
            return 'section name is empty';
        }
        if (strpbrk($name, "[]\"\n\r\0") !== false) {
            return 'section name contains "[", "]" or a quote';
        }

        return null;
    }

    /**
     * @return array{string, string|null, string} key, array index (null for a scalar, '' for []), value
     */
    private function directive(string $content, int $at): array
    {
        $equal = strpos($content, '=');
        if ($equal === false) {
            throw FormatException::of(ViolationCode::IniMissingEquals, 'directive without "="', $at);
        }

        $left = rtrim(substr($content, 0, $equal), " \t");
        $index = null;
        $bracket = strpos($left, '[');
        if ($bracket !== false) {
            if (!str_ends_with($left, ']')) {
                throw FormatException::of(ViolationCode::IniInvalidArrayKey, 'array key must end with "]" before "="', $at + $bracket);
            }
            $index = substr($left, $bracket + 1, -1);
            $error = self::indexError($index);
            if ($error !== null) {
                throw FormatException::of(ViolationCode::IniInvalidArrayKey, $error, $at + $bracket);
            }
            $left = rtrim(substr($left, 0, $bracket), " \t");
        }

        [$code, $error] = self::keyError($left) ?? [null, null];
        if ($code !== null) {
            throw FormatException::of($code, (string) $error, $at);
        }

        return [$left, $index, $this->value($content, $equal + 1, $at)];
    }

    /**
     * @return array{ViolationCode, string}|null
     */
    private static function keyError(string $key): ?array
    {
        if ($key === '') {
            return [ViolationCode::IniInvalidKey, 'key is empty'];
        }
        $bad = strpbrk($key, self::KEY_FORBIDDEN . "\n\r\0");
        if ($bad !== false) {
            return [ViolationCode::IniInvalidKey, sprintf('key contains "%s"', $bad[0])];
        }
        if (in_array(strtolower($key), self::RESERVED, true)) {
            return [ViolationCode::IniReservedKey, sprintf('"%s" is a reserved word', $key)];
        }

        return null;
    }

    private static function indexError(string $index): ?string
    {
        if (strpbrk($index, "[]\"=;\n\r\0") !== false) {
            return 'array index contains a forbidden character';
        }
        if ($index !== trim($index, " \t")) {
            return 'array index has leading or trailing whitespace';
        }

        return null;
    }

    private function value(string $content, int $start, int $at): string
    {
        $pos = $start + strspn($content, " \t", $start);
        if ($pos >= strlen($content)) {
            return '';
        }

        if ($content[$pos] !== '"') {
            $semicolon = strpos($content, ';', $pos);
            $raw = $semicolon === false ? substr($content, $pos) : substr($content, $pos, $semicolon - $pos);

            return rtrim($raw, " \t");
        }

        for ($i = $pos + 1, $n = strlen($content); $i < $n; $i++) {
            if ($content[$i] === '\\') {
                $i++;
            } elseif ($content[$i] === '"') {
                self::expectLineEnd($content, $i + 1, $at);

                return substr($content, $pos + 1, $i - $pos - 1);
            }
        }

        throw FormatException::of(ViolationCode::IniUnterminatedQuote, 'quoted value without closing quote on the same line', $at + $pos);
    }

    private static function expectLineEnd(string $content, int $pos, int $at): void
    {
        $pos += strspn($content, " \t", $pos);
        if ($pos < strlen($content) && $content[$pos] !== ';') {
            throw FormatException::of(ViolationCode::CommonUnexpectedCharacter, sprintf('unexpected "%s"', $content[$pos]), $at + $pos);
        }
    }

    /**
     * @param array<string, string|array<int|string, string>> $target
     */
    private function store(array &$target, string $key, ?string $index, string $value, int $at): void
    {
        $allowDuplicates = $this->config->has(IniFlag::AllowDuplicateKeys);

        if ($index === null) {
            if (isset($target[$key]) && is_array($target[$key])) {
                throw FormatException::of(ViolationCode::IniMixedValueTypes, sprintf('"%s" is used both as an array and as a value', $key), $at);
            }
            if (isset($target[$key]) && !$allowDuplicates) {
                throw FormatException::of(ViolationCode::IniDuplicateKey, sprintf('"%s" is repeated', $key), $at);
            }
            $target[$key] = $value;

            return;
        }

        if (isset($target[$key]) && !is_array($target[$key])) {
            throw FormatException::of(ViolationCode::IniMixedValueTypes, sprintf('"%s" is used both as a value and as an array', $key), $at);
        }
        $target[$key] ??= [];
        if ($index === '') {
            $target[$key][] = $value;
        } elseif (isset($target[$key][$index]) && !$allowDuplicates) {
            throw FormatException::of(ViolationCode::IniDuplicateKey, sprintf('"%s[%s]" is repeated', $key, $index), $at);
        } else {
            $target[$key][$index] = $value;
        }
    }

    /**
     * @param array<mixed> $directives
     *
     * @return list<string>
     */
    private function directiveLines(array $directives): array
    {
        $lines = [];
        foreach ($directives as $key => $value) {
            $key = (string) $key;
            $error = self::keyError($key);
            if ($error !== null) {
                throw new BuildException(sprintf('invalid key "%s": %s', $key, $error[1]));
            }

            if (!is_array($value)) {
                $lines[] = $this->line($key, $value);
                continue;
            }

            if ($value === []) {
                throw new BuildException(sprintf('empty array "%s" cannot be written', $key));
            }
            $append = array_is_list($value)
                && $this->config->choice([IniFlag::ArrayAppend, IniFlag::ArrayIndexed]) === IniFlag::ArrayAppend;
            foreach ($value as $index => $item) {
                $index = (string) $index;
                if (self::indexError($index) !== null) {
                    throw new BuildException(sprintf('invalid index "%s" in "%s"', $index, $key));
                }
                if (is_array($item)) {
                    throw new BuildException(sprintf('"%s" is nested deeper than one level', $key));
                }
                $lines[] = $this->line($key . '[' . ($append ? '' : $index) . ']', $item);
            }
        }

        return $lines;
    }

    private function line(string $key, mixed $value): string
    {
        if ($value === null && $this->config->choice([IniFlag::NullEmpty, IniFlag::NullWord]) === IniFlag::NullEmpty) {
            return $key . ' =';
        }

        return $key . ' = ' . $this->format($key, $value);
    }

    private function format(string $key, mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return match ($this->config->choice([IniFlag::BoolTrueFalse, IniFlag::BoolOnOff, IniFlag::BoolYesNo, IniFlag::BoolOneZero])) {
                IniFlag::BoolOnOff => $value ? 'on' : 'off',
                IniFlag::BoolYesNo => $value ? 'yes' : 'no',
                IniFlag::BoolOneZero => $value ? '1' : '0',
                default => $value ? 'true' : 'false',
            };
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new BuildException(sprintf('"%s": NAN and INF cannot be written', $key));
            }

            return (string) $value;
        }
        if (!is_string($value)) {
            throw new BuildException(sprintf('"%s": %s values cannot be written', $key, get_debug_type($value)));
        }

        $unquoted = $value !== ''
            && strpbrk($value, ";\n\r\0") === false
            && $value === trim($value, " \t")
            && $value[0] !== '"';
        $quoted = strpbrk($value, "\"\n\r\0") === false
            && strspn(strrev($value), '\\') % 2 === 0;

        $preferQuotes = $this->config->choice([IniFlag::QuoteWhenNeeded, IniFlag::QuoteAlways]) === IniFlag::QuoteAlways;
        if ($quoted && ($preferQuotes || !$unquoted)) {
            return '"' . $value . '"';
        }
        if ($unquoted) {
            return $value;
        }

        throw new BuildException(sprintf('"%s": value cannot be written in INI syntax', $key));
    }
}
