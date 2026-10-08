<?php

declare(strict_types=1);

namespace Vitals\Format\Pattern;

use Vitals\Builder;
use Vitals\Exception\BuildException;
use Vitals\Exception\FormatException;
use Vitals\Flag\PcreFlag;
use Vitals\Internal\AbstractFormat;
use Vitals\Internal\NativeCall;
use Vitals\Parser;
use Vitals\Validator;
use Vitals\ViolationCode;

/**
 * PCRE patterns as used by preg_*(): delimiter, body, modifiers.
 *
 * Validation compiles the pattern with preg_match($pattern, '') under a scoped error handler.
 */
final readonly class Pcre extends AbstractFormat implements Parser, Builder, Validator
{
    private const BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /** Delimiters tried, in order, to compile a body given with PcreFlag::BodyOnly. */
    private const BODY_DELIMITERS = '/#~%!@;,=|`';

    protected static function flagEnum(): string
    {
        return PcreFlag::class;
    }

    /**
     * @return array{delimiter: string, pattern: string, modifiers: string}
     */
    public function parse(string $input): array
    {
        /** @var array{delimiter: string, pattern: string, modifiers: string} */
        return parent::parse($input);
    }

    /**
     * @return array{delimiter: string, pattern: string, modifiers: string}
     */
    protected function analyse(string $input): array
    {
        if ($this->config->has(PcreFlag::BodyOnly)) {
            $this->compile($this->wrapBody($input), 0);

            return ['delimiter' => '', 'pattern' => $input, 'modifiers' => ''];
        }

        if ($input === '') {
            throw FormatException::of(ViolationCode::CommonEmptyInput, 'pattern is empty', 0);
        }

        $delimiter = $input[0];
        if (!self::isValidDelimiter($delimiter)) {
            throw FormatException::of(ViolationCode::PcreInvalidDelimiter, 'delimiter must not be alphanumeric, backslash, whitespace or NUL', 0);
        }

        $end = self::findEndDelimiter($input);
        if ($end === null) {
            throw FormatException::of(
                ViolationCode::PcreMissingEndDelimiter,
                sprintf('no ending delimiter "%s" found', self::BRACKETS[$delimiter] ?? $delimiter),
                0,
            );
        }

        $modifiers = substr($input, $end + 1);
        $allowed = self::allowedModifiers();
        for ($i = 0, $n = strlen($modifiers); $i < $n; $i++) {
            if (!str_contains($allowed, $modifiers[$i])) {
                throw FormatException::of(
                    ViolationCode::PcreUnknownModifier,
                    sprintf('unknown modifier "%s"', $modifiers[$i]),
                    $end + 1 + $i,
                );
            }
        }

        $this->compile($input, 1);

        return ['delimiter' => $delimiter, 'pattern' => substr($input, 1, $end - 1), 'modifiers' => $modifiers];
    }

    public function build(array $parts): string
    {
        $unknown = array_diff(array_keys($parts), ['delimiter', 'pattern', 'modifiers']);
        if ($unknown !== []) {
            throw new BuildException(sprintf('unknown key "%s"', (string) reset($unknown)));
        }

        $delimiter = $parts['delimiter'] ?? '';
        $pattern = $parts['pattern'] ?? '';
        $modifiers = $parts['modifiers'] ?? '';
        if (!is_string($delimiter) || !is_string($pattern) || !is_string($modifiers)) {
            throw new BuildException('delimiter, pattern and modifiers must be strings');
        }

        if ($this->config->has(PcreFlag::BodyOnly)) {
            if ($delimiter !== '' || $modifiers !== '') {
                throw new BuildException('with BodyOnly, delimiter and modifiers must be empty');
            }

            return $pattern;
        }

        if (strlen($delimiter) !== 1 || !self::isValidDelimiter($delimiter)) {
            throw new BuildException('delimiter must be one character, not alphanumeric, backslash, whitespace or NUL');
        }
        $allowed = self::allowedModifiers();
        if (strspn($modifiers, $allowed) !== strlen($modifiers)) {
            throw new BuildException(sprintf('unknown modifier in "%s"', $modifiers));
        }

        $result = $delimiter . $pattern . (self::BRACKETS[$delimiter] ?? $delimiter) . $modifiers;
        if (self::findEndDelimiter($result) !== strlen($delimiter . $pattern)) {
            throw new BuildException('pattern contains an unescaped closing delimiter, or ends with a backslash');
        }

        return $result;
    }

    private static function isValidDelimiter(string $char): bool
    {
        return !ctype_alnum($char) && !str_contains("\\ \t\n\r\v\f\0", $char);
    }

    /**
     * Position of the closing delimiter, as PHP finds it: backslash escapes the next byte,
     * bracket delimiters nest.
     */
    private static function findEndDelimiter(string $input): ?int
    {
        $open = $input[0];
        $close = self::BRACKETS[$open] ?? $open;
        $depth = 0;

        for ($i = 1, $n = strlen($input); $i < $n; $i++) {
            $char = $input[$i];
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === $close) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($char === $open) {
                $depth++;
            }
        }

        return null;
    }

    private static function allowedModifiers(): string
    {
        return 'imsxADSUXJun' . (PHP_VERSION_ID >= 80400 ? 'r' : '');
    }

    /**
     * Wraps a bare body with a delimiter it does not contain, escaping '/' as a last resort.
     */
    private function wrapBody(string $body): string
    {
        foreach (str_split(self::BODY_DELIMITERS) as $delimiter) {
            if (!str_contains($body, $delimiter)) {
                return $delimiter . $body . $delimiter;
            }
        }

        $escaped = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            if ($body[$i] === '\\' && $i + 1 < $n) {
                $escaped .= $body[$i] . $body[++$i];
            } else {
                $escaped .= $body[$i] === '/' ? '\\/' : $body[$i];
            }
        }

        return '/' . $escaped . '/';
    }

    /**
     * @param int $bodyOffset offset of the pattern body in the input, added to PCRE's offset
     */
    private function compile(string $pattern, int $bodyOffset): void
    {
        [$result, $warning] = NativeCall::run(static fn () => preg_match($pattern, ''));
        if ($result !== false) {
            return;
        }

        if ($warning === null) {
            throw FormatException::of(ViolationCode::CommonNativeError, preg_last_error_msg());
        }

        $message = (string) preg_replace('/^preg_match\(\): (Compilation failed: )?/', '', $warning);
        $offset = null;
        if (preg_match('/ at offset (\d+)$/', $message, $match) === 1) {
            $offset = $bodyOffset + (int) $match[1];
            $message = substr($message, 0, -strlen($match[0]));
        }

        throw FormatException::of(ViolationCode::PcreCompileError, $message, $offset);
    }
}
