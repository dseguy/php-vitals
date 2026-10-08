<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Vitals\Exception\BuildException;
use Vitals\Exception\ConfigurationException;
use Vitals\Flag\UrlFlag;
use Vitals\Format\Url;
use Vitals\Option\AllowedSchemes;
use Vitals\Parser;
use Vitals\Tests\Fixtures;
use Vitals\Validator;

final class UrlTest extends FormatTestCase
{
    protected function format(): Parser&Validator
    {
        return new Url();
    }

    public static function validInputs(): iterable
    {
        return Fixtures::valid('url');
    }

    public static function invalidInputs(): iterable
    {
        return Fixtures::invalid('url');
    }

    #[DataProvider('validInputs')]
    public function testValid(string $input): void
    {
        $this->assertValid($input);
    }

    #[DataProvider('invalidInputs')]
    public function testInvalid(string $input, string $code): void
    {
        $this->assertInvalid($input, $code);
    }

    public function testParse(): void
    {
        self::assertSame(
            [
                'scheme' => 'https',
                'user' => 'user',
                'pass' => 'pw',
                'host' => 'Example.com',
                'port' => 8080,
                'path' => '/a/b%20c',
                'query' => 'x=1&y',
                'fragment' => 'frag',
            ],
            (new Url())->parse('HTTPS://user:pw@Example.com:8080/a/b%20c?x=1&y#frag'),
        );
        self::assertSame(
            ['scheme' => 'mailto', 'user' => null, 'pass' => null, 'host' => null, 'port' => null, 'path' => 'a@b.c', 'query' => null, 'fragment' => null],
            (new Url())->parse('mailto:a@b.c'),
        );
        self::assertSame('', (new Url())->parse('file:///etc')['host']);
        self::assertSame('', (new Url())->parse('http://h?')['query']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function parseUrlAgreement(): iterable
    {
        foreach (['http://example.com', 'https://user:pw@example.com:8080/a/b?x=1#f', 'mailto:a@b.c', 'http://[::1]:80/', 'urn:isbn:1'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('parseUrlAgreement')]
    public function testAgreesWithParseUrl(string $input): void
    {
        $native = parse_url($input);
        self::assertIsArray($native);
        $parsed = (new Url())->parse($input);
        foreach ($native as $key => $value) {
            self::assertSame($value, $parsed[$key], $key);
        }
        self::assertSame($parsed, (new Url(UrlFlag::ParseUrl))->parse($input));
    }

    public function testRelative(): void
    {
        $format = new Url(UrlFlag::AllowRelative);
        self::assertSame('h', $format->parse('//h/p?q')['host']);
        self::assertSame('../a', $format->parse('../a')['path']);
        self::assertTrue($format->validate(''));
    }

    #[RequiresPhpExtension('intl')]
    public function testIdn(): void
    {
        self::assertSame('xn--bcher-kva.de', (new Url(UrlFlag::Idn))->parse('http://bücher.de/')['host']);
        self::assertSame('url.invalid_host', (new Url())->check('http://bücher.de/')?->code->value);
    }

    public function testAllowedSchemesOnlyAppliesToValidation(): void
    {
        $format = new Url(new AllowedSchemes('HTTPS'));
        self::assertTrue($format->validate('https://h'));
        self::assertSame('url.disallowed_scheme', $format->check('http://h')?->code->value);
        self::assertSame('http', $format->parse('http://h')['scheme']);
    }

    public function testParseUrlMode(): void
    {
        $format = new Url(UrlFlag::ParseUrl);
        self::assertSame('h', $format->parse('http://h/a b')['host']);
        self::assertSame('common.native_error', $format->check('http:///x')?->code->value);
    }

    #[RequiresPhp('< 8.5')]
    public function testWhatWgNeedsPhp85(): void
    {
        $this->expectException(ConfigurationException::class);
        new Url(UrlFlag::WhatWg);
    }

    #[RequiresPhp('>= 8.5')]
    public function testWhatWg(): void
    {
        $format = new Url(UrlFlag::WhatWg);
        $parsed = $format->parse('HTTP://Example.COM:80/a/../b');
        self::assertSame('http', $parsed['scheme']);
        self::assertSame('example.com', $parsed['host']);
        self::assertSame('/b', $parsed['path']);
        self::assertFalse($format->validate('http://h:99999/'));
    }

    public function testBuild(): void
    {
        $format = new Url();
        $url = 'https://user:pw@example.com:8080/a?b#c';
        self::assertSame($url, $format->build($format->parse($url)));
        self::assertSame('http://h/p', $format->build(['scheme' => 'http', 'host' => 'h', 'path' => '/p']));
        self::assertSame('mailto:a@b.c', $format->build(['scheme' => 'mailto', 'path' => 'a@b.c']));
        self::assertSame('//h', (new Url(UrlFlag::AllowRelative))->build(['host' => 'h']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function impossibleParts(): iterable
    {
        yield 'port out of range' => [['scheme' => 'http', 'host' => 'h', 'port' => 65536]];
        yield 'port without host' => [['scheme' => 'http', 'port' => 80]];
        yield 'pass without user' => [['scheme' => 'http', 'host' => 'h', 'pass' => 'x']];
        yield 'relative path with host' => [['scheme' => 'http', 'host' => 'h', 'path' => 'p']];
        yield 'double slash without host' => [['scheme' => 'http', 'path' => '//p']];
        yield 'colon in first segment' => [['path' => 'a:b']];
        yield 'invalid character' => [['scheme' => 'http', 'host' => 'h', 'path' => '/a b']];
        yield 'unknown key' => [['scheme' => 'http', 'hostname' => 'h']];
        yield 'int path' => [['scheme' => 'http', 'path' => 1]];
    }

    /**
     * @param array<string, mixed> $parts
     */
    #[DataProvider('impossibleParts')]
    public function testBuildRejects(array $parts): void
    {
        $this->expectException(BuildException::class);
        (new Url())->build($parts);
    }
}
