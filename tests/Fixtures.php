<?php

declare(strict_types=1);

namespace Vitals\Tests;

final class Fixtures
{
    /**
     * @return array{valid?: list<string>, invalid?: array<string, string>}
     */
    public static function load(string $name): array
    {
        /** @var array{valid?: list<string>, invalid?: array<string, string>} $fixtures */
        $fixtures = require __DIR__ . '/fixtures/' . $name . '.php';

        return $fixtures;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(string $name): iterable
    {
        foreach (self::load($name)['valid'] ?? [] as $input) {
            yield json_encode($input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) => [$input];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(string $name): iterable
    {
        foreach (self::load($name)['invalid'] ?? [] as $input => $code) {
            yield json_encode((string) $input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) => [(string) $input, $code];
        }
    }
}
