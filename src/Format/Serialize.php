<?php

declare(strict_types=1);

namespace Vitals\Format;

use Vitals\Builder;
use Vitals\Exception\BuildException;
use Vitals\Exception\FormatException;
use Vitals\Flag\SerializeFlag;
use Vitals\Internal\AbstractFormat;
use Vitals\Option\AllowedClasses;
use Vitals\Parser;
use Vitals\Validator;
use Vitals\ViolationCode;

/**
 * Output of serialize(), read by a pure-PHP parser: unserialize() is never called, so no
 * object is ever instantiated and no magic method runs.
 *
 * References (r:, R:) and the legacy S: strings are not supported.
 */
final readonly class Serialize extends AbstractFormat implements Parser, Builder, Validator
{
    private const CLASS_NAME = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    protected static function flagEnum(): string
    {
        return SerializeFlag::class;
    }

    protected static function optionClasses(): array
    {
        return [AllowedClasses::class];
    }

    protected function analyse(string $input): array
    {
        if ($input === '') {
            throw FormatException::of(ViolationCode::CommonEmptyInput, 'payload is empty', 0);
        }

        $pos = 0;
        $node = $this->node($input, $pos, 0);
        if ($pos !== strlen($input)) {
            throw FormatException::of(ViolationCode::CommonTrailingData, 'unexpected data after the value', $pos);
        }

        return $node;
    }

    public function build(array $parts): string
    {
        return $this->write($parts, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $s, int &$pos, int $depth): array
    {
        $start = $pos;
        $type = $s[$pos] ?? null;
        if ($type === null) {
            throw self::end($pos);
        }

        if ($type === 'N') {
            $pos++;
            self::expect($s, $pos, ';');

            return ['type' => 'null'];
        }

        if (($s[$pos + 1] ?? null) !== ':' && in_array($type, ['b', 'i', 'd', 's', 'a', 'O', 'C', 'E', 'r', 'R'], true)) {
            throw isset($s[$pos + 1])
                ? FormatException::of(ViolationCode::SerializeMissingTerminator, 'expected ":"', $pos + 1)
                : self::end($pos + 1);
        }

        switch ($type) {
            case 'b':
                $pos += 2;
                $value = self::until($s, $pos, ';');
                if ($value !== '0' && $value !== '1') {
                    throw FormatException::of(ViolationCode::SerializeInvalidBool, 'bool must be 0 or 1', $start + 2);
                }

                return ['type' => 'bool', 'value' => $value === '1'];

            case 'i':
                $pos += 2;

                return ['type' => 'int', 'value' => self::int(self::until($s, $pos, ';'), $start + 2)];

            case 'd':
                $pos += 2;
                $value = self::until($s, $pos, ';');
                if (preg_match('/^(-?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?|-?INF|NAN)$/', $value) !== 1) {
                    throw FormatException::of(ViolationCode::SerializeInvalidFloat, sprintf('malformed float "%s"', $value), $start + 2);
                }

                return ['type' => 'float', 'value' => match ($value) {
                    'INF' => INF,
                    '-INF' => -INF,
                    'NAN' => NAN,
                    default => (float) $value,
                }];

            case 's':
                $pos += 2;
                $value = self::string($s, $pos);
                self::expect($s, $pos, ';');

                return ['type' => 'string', 'value' => $value];

            case 'a':
                $pos += 2;
                $count = $this->count($s, $pos, $depth);
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    if (($s[$pos] ?? null) === '}') {
                        throw FormatException::of(ViolationCode::SerializeCountMismatch, sprintf('array declares %d items, has %d', $count, $i), $pos);
                    }
                    $key = $this->key($s, $pos);
                    $items[] = ['key' => $key, 'value' => $this->node($s, $pos, $depth + 1)];
                }
                $this->close($s, $pos, $count);

                return ['type' => 'array', 'items' => $items];

            case 'O':
                $pos += 2;
                $class = $this->className($s, $pos);
                self::expect($s, $pos, ':');
                $count = $this->count($s, $pos, $depth);
                $properties = [];
                for ($i = 0; $i < $count; $i++) {
                    if (($s[$pos] ?? null) === '}') {
                        throw FormatException::of(ViolationCode::SerializeCountMismatch, sprintf('object declares %d properties, has %d', $count, $i), $pos);
                    }
                    $nameAt = $pos;
                    $properties[] = self::property($this->key($s, $pos), $nameAt) + ['value' => $this->node($s, $pos, $depth + 1)];
                }
                $this->close($s, $pos, $count);

                return ['type' => 'object', 'class' => $class, 'properties' => array_map(
                    static fn (array $p): array => [
                        'name' => $p['name'],
                        'visibility' => $p['visibility'],
                        'value' => $p['value'],
                        'declaringClass' => $p['declaringClass'],
                    ],
                    $properties,
                )];

            case 'C':
                $pos += 2;
                $class = $this->className($s, $pos);
                self::expect($s, $pos, ':');
                $length = self::length(self::until($s, $pos, ':'), $pos);
                self::expect($s, $pos, '{');
                if ($pos + $length > strlen($s)) {
                    throw self::end(strlen($s));
                }
                $data = substr($s, $pos, $length);
                $pos += $length;
                self::expect($s, $pos, '}');

                return ['type' => 'custom', 'class' => $class, 'data' => $data];

            case 'E':
                $pos += 2;
                $valueAt = $pos;
                $value = self::string($s, $pos);
                self::expect($s, $pos, ';');
                $colon = strpos($value, ':');
                if ($colon === false || $colon === 0 || $colon === strlen($value) - 1) {
                    throw FormatException::of(ViolationCode::SerializeInvalidEnum, 'enum must be "Class:Case"', $valueAt);
                }
                $class = substr($value, 0, $colon);
                $case = substr($value, $colon + 1);
                if (preg_match(self::CLASS_NAME, $class) !== 1 || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $case) !== 1) {
                    throw FormatException::of(ViolationCode::SerializeInvalidEnum, sprintf('invalid enum "%s"', $value), $valueAt);
                }
                $this->allowClass($class, $start);

                return ['type' => 'enum', 'class' => $class, 'case' => $case];

            case 'r':
            case 'R':
                throw FormatException::of(ViolationCode::SerializeReferenceUnsupported, 'references are not supported', $start);
        }

        throw FormatException::of(ViolationCode::SerializeUnknownType, sprintf('unknown type "%s"', $type), $start);
    }

    private function count(string $s, int &$pos, int $depth): int
    {
        $at = $pos;
        $count = self::length(self::until($s, $pos, ':'), $at);
        self::expect($s, $pos, '{');
        if ($depth + 1 > $this->config->maxDepth()) {
            throw FormatException::of(ViolationCode::CommonTooDeep, sprintf('nesting deeper than %d', $this->config->maxDepth()), $at);
        }
        if ($count > $this->config->maxItems()) {
            throw FormatException::of(ViolationCode::CommonTooManyItems, sprintf('more than %d items', $this->config->maxItems()), $at);
        }

        return $count;
    }

    private function close(string $s, int &$pos, int $count): void
    {
        if (!isset($s[$pos])) {
            throw self::end($pos);
        }
        if ($s[$pos] !== '}') {
            throw FormatException::of(ViolationCode::SerializeCountMismatch, sprintf('more than the %d declared items', $count), $pos);
        }
        $pos++;
    }

    private function key(string $s, int &$pos): int|string
    {
        $at = $pos;
        $node = $this->node($s, $pos, 0);
        if ($node['type'] !== 'int' && $node['type'] !== 'string') {
            throw FormatException::of(ViolationCode::SerializeInvalidKey, 'key must be int or string', $at);
        }

        /** @var int|string */
        return $node['value'];
    }

    /**
     * @return array{name: int|string, visibility: 'public'|'protected'|'private', declaringClass: string|null}
     */
    private static function property(int|string $name, int $at): array
    {
        if (is_int($name) || !str_starts_with($name, "\0")) {
            return ['name' => $name, 'visibility' => 'public', 'declaringClass' => null];
        }

        $second = strpos($name, "\0", 1);
        if ($second === false || $second === 1 || $second === strlen($name) - 1) {
            throw FormatException::of(ViolationCode::SerializeInvalidPropertyName, 'malformed mangled property name', $at);
        }

        $scope = substr($name, 1, $second - 1);
        $plain = substr($name, $second + 1);
        if ($scope === '*') {
            return ['name' => $plain, 'visibility' => 'protected', 'declaringClass' => null];
        }
        if (preg_match(self::CLASS_NAME, $scope) !== 1) {
            throw FormatException::of(ViolationCode::SerializeInvalidPropertyName, sprintf('invalid declaring class "%s"', $scope), $at);
        }

        return ['name' => $plain, 'visibility' => 'private', 'declaringClass' => $scope];
    }

    private function className(string $s, int &$pos): string
    {
        $at = $pos;
        $class = self::string($s, $pos);
        if (preg_match(self::CLASS_NAME, $class) !== 1) {
            throw FormatException::of(ViolationCode::SerializeInvalidClassName, sprintf('invalid class name "%s"', $class), $at);
        }
        $this->allowClass($class, $at - 2);

        return $class;
    }

    private function allowClass(string $class, int $at): void
    {
        if ($this->config->has(SerializeFlag::NoObjects)) {
            throw FormatException::of(ViolationCode::SerializeDisallowedClass, sprintf('objects are not allowed, found "%s"', $class), $at);
        }
        $allowed = $this->config->option(AllowedClasses::class);
        if ($allowed !== null && !$allowed->allows($class)) {
            throw FormatException::of(ViolationCode::SerializeDisallowedClass, sprintf('class "%s" is not allowed', $class), $at);
        }
    }

    /**
     * Reads <length>:"<bytes>" and returns the bytes.
     */
    private static function string(string $s, int &$pos): string
    {
        $at = $pos;
        $length = self::length(self::until($s, $pos, ':'), $at);
        self::expect($s, $pos, '"');
        if ($pos + $length > strlen($s)) {
            throw self::end(strlen($s));
        }
        $value = substr($s, $pos, $length);
        $pos += $length;
        if (($s[$pos] ?? null) !== '"') {
            throw isset($s[$pos])
                ? FormatException::of(ViolationCode::SerializeLengthMismatch, sprintf('string is not %d bytes long', $length), $at)
                : self::end($pos);
        }
        $pos++;

        return $value;
    }

    /**
     * Returns the text up to $char, and moves after it.
     */
    private static function until(string $s, int &$pos, string $char): string
    {
        $end = strpos($s, $char, $pos);
        if ($end === false) {
            throw self::end(strlen($s));
        }
        $value = substr($s, $pos, $end - $pos);
        $pos = $end + 1;

        return $value;
    }

    private static function expect(string $s, int &$pos, string $char): void
    {
        if (!isset($s[$pos])) {
            throw self::end($pos);
        }
        if ($s[$pos] !== $char) {
            throw FormatException::of(ViolationCode::SerializeMissingTerminator, sprintf('expected "%s"', $char), $pos);
        }
        $pos++;
    }

    private static function int(string $value, int $at): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1 || ($value !== '0' && preg_match('/^-?0/', $value) === 1)) {
            throw FormatException::of(ViolationCode::SerializeInvalidInt, sprintf('malformed integer "%s"', $value), $at);
        }
        if ((string) (int) $value !== $value) {
            throw FormatException::of(ViolationCode::SerializeInvalidInt, sprintf('integer "%s" overflows', $value), $at);
        }

        return (int) $value;
    }

    private static function length(string $value, int $at): int
    {
        if (preg_match('/^\d+$/', $value) !== 1 || (string) (int) $value !== $value) {
            throw FormatException::of(ViolationCode::SerializeInvalidInt, sprintf('malformed length "%s"', $value), $at);
        }

        return (int) $value;
    }

    private static function end(int $pos): FormatException
    {
        return FormatException::of(ViolationCode::CommonUnexpectedEnd, 'payload is truncated', $pos);
    }

    /**
     * @param array<mixed> $node
     */
    private function write(array $node, int $depth): string
    {
        if ($depth > $this->config->maxDepth()) {
            throw new BuildException(sprintf('nesting deeper than %d', $this->config->maxDepth()));
        }

        $type = $node['type'] ?? null;
        $expected = match ($type) {
            'null' => ['type'],
            'bool', 'int', 'float', 'string' => ['type', 'value'],
            'array' => ['type', 'items'],
            'object' => ['type', 'class', 'properties'],
            'custom' => ['type', 'class', 'data'],
            'enum' => ['type', 'class', 'case'],
            default => throw new BuildException(sprintf('unknown node type %s', var_export($type, true))),
        };
        $unknown = array_diff(array_keys($node), $expected);
        if ($unknown !== []) {
            throw new BuildException(sprintf('unknown key "%s" in %s node', (string) reset($unknown), $type));
        }
        foreach (array_slice($expected, 1) as $key) {
            if (!array_key_exists($key, $node)) {
                throw new BuildException(sprintf('missing key "%s" in %s node', $key, $type));
            }
        }

        $value = $node['value'] ?? null;

        return match ($type) {
            'null' => 'N;',
            'bool' => is_bool($value) ? 'b:' . (int) $value . ';' : throw new BuildException('bool node needs a bool value'),
            'int' => is_int($value) ? 'i:' . $value . ';' : throw new BuildException('int node needs an int value'),
            'float' => is_float($value) ? serialize($value) : throw new BuildException('float node needs a float value'),
            'string' => is_string($value) ? serialize($value) : throw new BuildException('string node needs a string value'),
            'array' => $this->writeArray($node['items'], $depth),
            'object' => $this->writeObject($node['class'], $node['properties'], $depth),
            'custom' => $this->writeCustom($node['class'], $node['data']),
            'enum' => $this->writeEnum($node['class'], $node['case']),
        };
    }

    private function writeArray(mixed $items, int $depth): string
    {
        if (!is_array($items)) {
            throw new BuildException('array items must be a list');
        }
        $out = '';
        foreach ($items as $item) {
            if (!is_array($item) || array_keys($item) !== ['key', 'value'] || !is_array($item['value'])) {
                throw new BuildException('array items must be {key, value} pairs');
            }
            $out .= $this->writeKey($item['key']) . $this->write($item['value'], $depth + 1);
        }

        return 'a:' . count($items) . ':{' . $out . '}';
    }

    private function writeObject(mixed $class, mixed $properties, int $depth): string
    {
        $class = self::checkClass($class);
        if (!is_array($properties)) {
            throw new BuildException('object properties must be a list');
        }
        $out = '';
        foreach ($properties as $property) {
            if (!is_array($property) || array_diff(array_keys($property), ['name', 'visibility', 'value', 'declaringClass']) !== []
                || !array_key_exists('name', $property) || !array_key_exists('value', $property) || !is_array($property['value'])) {
                throw new BuildException('properties must be {name, visibility, value, declaringClass}');
            }
            $name = $property['name'];
            $visibility = $property['visibility'] ?? 'public';
            $declaring = $property['declaringClass'] ?? null;
            if (!is_int($name) && !is_string($name)) {
                throw new BuildException('property name must be int or string');
            }
            $mangled = match ($visibility) {
                'public' => $declaring === null ? $name : throw new BuildException('declaringClass is only for private properties'),
                'protected' => $declaring === null ? "\0*\0" . $name : throw new BuildException('declaringClass is only for private properties'),
                'private' => is_string($declaring) ? "\0" . self::checkClass($declaring) . "\0" . $name : throw new BuildException('private properties need a declaringClass'),
                default => throw new BuildException(sprintf('unknown visibility %s', var_export($visibility, true))),
            };
            if ($visibility !== 'public' && is_int($name)) {
                throw new BuildException('only public properties can have an int name');
            }
            $out .= $this->writeKey($mangled) . $this->write($property['value'], $depth + 1);
        }

        return 'O:' . strlen($class) . ':"' . $class . '":' . count($properties) . ':{' . $out . '}';
    }

    private function writeCustom(mixed $class, mixed $data): string
    {
        $class = self::checkClass($class);
        if (!is_string($data)) {
            throw new BuildException('custom data must be a string');
        }

        return 'C:' . strlen($class) . ':"' . $class . '":' . strlen($data) . ':{' . $data . '}';
    }

    private function writeEnum(mixed $class, mixed $case): string
    {
        $class = self::checkClass($class);
        if (!is_string($case) || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $case) !== 1) {
            throw new BuildException('invalid enum case');
        }
        $value = $class . ':' . $case;

        return 'E:' . strlen($value) . ':"' . $value . '";';
    }

    private function writeKey(mixed $key): string
    {
        return match (true) {
            is_int($key) => 'i:' . $key . ';',
            is_string($key) => serialize($key),
            default => throw new BuildException('keys must be int or string'),
        };
    }

    private static function checkClass(mixed $class): string
    {
        if (!is_string($class) || preg_match(self::CLASS_NAME, $class) !== 1) {
            throw new BuildException(sprintf('invalid class name %s', var_export($class, true)));
        }

        return $class;
    }
}
