<?php

declare(strict_types=1);

namespace Vitals\Format;

use Vitals\Builder;
use Vitals\Exception\BuildException;
use Vitals\Exception\FormatException;
use Vitals\Flag\QueryStringFlag;
use Vitals\Internal\AbstractFormat;
use Vitals\Parser;
use Vitals\Validator;
use Vitals\ViolationCode;

/**
 * URL query strings, with parse_str() semantics and strict errors.
 *
 * Differences with parse_str(): malformed percent-encoding, unbalanced brackets, empty names,
 * and a name used both as a scalar and as an array are errors instead of being silently fixed.
 */
final readonly class QueryString extends AbstractFormat implements Parser, Builder, Validator
{
    protected static function flagEnum(): string
    {
        return QueryStringFlag::class;
    }

    protected function analyse(string $input): array
    {
        $result = [];
        if ($input === '') {
            return $result;
        }

        $items = 0;
        $offset = 0;
        foreach (explode('&', $input) as $segment) {
            $segmentOffset = $offset;
            $offset += strlen($segment) + 1;
            if ($segment === '') {
                continue;
            }

            if (++$items > $this->config->maxItems()) {
                throw FormatException::of(ViolationCode::CommonTooManyItems, sprintf('more than %d parameters', $this->config->maxItems()), $segmentOffset);
            }

            $equal = strpos($segment, '=');
            $rawName = $equal === false ? $segment : substr($segment, 0, $equal);
            $rawValue = $equal === false ? '' : substr($segment, $equal + 1);

            $name = self::decode($rawName, $segmentOffset);
            $value = self::decode($rawValue, $segmentOffset + (int) $equal + 1);

            $path = $this->splitName($name, $segmentOffset);
            $result = $this->assign($result, $path, $value, $segmentOffset, $path[0]);
        }

        return $result;
    }

    public function build(array $parts): string
    {
        $this->checkShape($parts, 0);
        $encoding = $this->config->choice([QueryStringFlag::Rfc3986, QueryStringFlag::Rfc1738]) === QueryStringFlag::Rfc3986
            ? PHP_QUERY_RFC3986
            : PHP_QUERY_RFC1738;

        return http_build_query($parts, '', '&', $encoding);
    }

    private static function decode(string $raw, int $offset): string
    {
        $percent = -1;
        while (($percent = strpos($raw, '%', $percent + 1)) !== false) {
            if (!ctype_xdigit(substr($raw, $percent + 1, 2)) || $percent + 2 >= strlen($raw)) {
                throw FormatException::of(ViolationCode::QsInvalidPercentEncoding, '"%" must be followed by two hexadecimal digits', $offset + $percent);
            }
        }

        return urldecode($raw);
    }

    /**
     * @return non-empty-list<string> the base name, then each bracket index ('' for [])
     */
    private function splitName(string $name, int $offset): array
    {
        if (!$this->config->has(QueryStringFlag::PreserveKeyNames)) {
            $name = ltrim($name, ' ');
        }

        $bracket = strpos($name, '[');
        $base = $bracket === false ? $name : substr($name, 0, $bracket);
        if ($base === '') {
            throw FormatException::of(ViolationCode::QsEmptyKey, 'parameter name is empty', $offset);
        }
        if (str_contains($base, ']')) {
            throw FormatException::of(ViolationCode::QsUnbalancedBrackets, '"]" without "["', $offset);
        }
        if (!$this->config->has(QueryStringFlag::PreserveKeyNames)) {
            $base = strtr($base, '. ', '__');
        }

        $path = [$base];
        $pos = $bracket === false ? strlen($name) : $bracket;
        while ($pos < strlen($name)) {
            if ($name[$pos] !== '[') {
                throw FormatException::of(ViolationCode::CommonUnexpectedCharacter, sprintf('unexpected "%s" after "]"', $name[$pos]), $offset);
            }
            $close = strpos($name, ']', $pos + 1);
            if ($close === false) {
                throw FormatException::of(ViolationCode::QsUnbalancedBrackets, '"[" without "]"', $offset);
            }
            $index = substr($name, $pos + 1, $close - $pos - 1);
            if (str_contains($index, '[')) {
                throw FormatException::of(ViolationCode::QsUnbalancedBrackets, 'nested "[" in an index', $offset);
            }
            $path[] = $index;
            $pos = $close + 1;
        }

        if (count($path) - 1 > $this->config->maxDepth()) {
            throw FormatException::of(ViolationCode::CommonTooDeep, sprintf('more than %d nested indexes', $this->config->maxDepth()), $offset);
        }

        return $path;
    }

    /**
     * Stores $value at $path in $node, as parse_str() does.
     *
     * @param array<int|string, mixed> $node
     * @param list<string>             $path
     *
     * @return array<int|string, mixed>
     */
    private function assign(array $node, array $path, string $value, int $offset, string $name): array
    {
        $key = (string) array_shift($path);

        if ($path === []) {
            if ($key === '') {
                $node[] = $value;
            } elseif (isset($node[$key]) && is_array($node[$key])) {
                throw FormatException::of(ViolationCode::QsMixedValueTypes, sprintf('"%s" is used both as an array and as a value', $name), $offset);
            } else {
                $node[$key] = $value;
            }

            return $node;
        }

        if ($key === '') {
            $node[] = $this->assign([], $path, $value, $offset, $name);

            return $node;
        }

        $child = $node[$key] ?? [];
        if (!is_array($child)) {
            throw FormatException::of(ViolationCode::QsMixedValueTypes, sprintf('"%s" is used both as a value and as an array', $name), $offset);
        }
        $node[$key] = $this->assign($child, $path, $value, $offset, $name);

        return $node;
    }

    /**
     * @param array<mixed> $parts
     */
    private function checkShape(array $parts, int $depth): void
    {
        foreach ($parts as $key => $value) {
            $key = (string) $key;
            if ($depth === 0 && $key === '') {
                throw new BuildException('parameter name is empty');
            }
            if (str_contains($key, '[') || str_contains($key, ']')) {
                throw new BuildException(sprintf('key "%s" contains a bracket', $key));
            }
            if (is_array($value)) {
                if ($value === []) {
                    throw new BuildException(sprintf('empty array at key "%s" cannot be written', $key));
                }
                $this->checkShape($value, $depth + 1);
            } elseif (!is_string($value)) {
                throw new BuildException(sprintf('value at key "%s" must be a string or an array, %s given', $key, get_debug_type($value)));
            }
        }
    }
}
