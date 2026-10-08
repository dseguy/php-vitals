<?php

declare(strict_types=1);

namespace Vitals\Tests\Property;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vitals\Builder;
use Vitals\Flag\UrlFlag;
use Vitals\Format\ByteSize;
use Vitals\Format\Ini;
use Vitals\Format\Pattern\Pcre;
use Vitals\Format\QueryString;
use Vitals\Format\Serialize;
use Vitals\Format\Url;
use Vitals\Parser;
use Vitals\Tests\Fixtures;

/**
 * parse(build(parse($s))) == parse($s), on the fixture corpus and on generated values.
 */
final class RoundTripTest extends TestCase
{
    private const SEED = 20261008;
    private const RUNS = 200;

    /**
     * @return iterable<string, array{Parser&Builder, string}>
     */
    public static function corpus(): iterable
    {
        $formats = [
            'bytesize' => new ByteSize(),
            'ini' => new Ini(),
            'pcre' => new Pcre(),
            'querystring' => new QueryString(),
            'url' => new Url(),
        ];
        foreach ($formats as $name => $format) {
            foreach (Fixtures::valid($name) as $label => [$input]) {
                yield $name . ' ' . $label => [$format, $input];
            }
        }
    }

    #[DataProvider('corpus')]
    public function testCorpus(Parser&Builder $format, string $input): void
    {
        $parsed = $format->parse($input);
        self::assertEquals($parsed, $format->parse($format->build($parsed)));
    }

    public function testGeneratedByteSizes(): void
    {
        mt_srand(self::SEED);
        $format = new ByteSize();
        for ($i = 0; $i < self::RUNS; $i++) {
            $input = (mt_rand(0, 4) === 0 ? '-' : '') . mt_rand(0, 1 << 20) . ['', 'K', 'M', 'G', 'k'][mt_rand(0, 4)];
            $parsed = $format->parse($input);
            self::assertSame($parsed, $format->parse($format->build($parsed)), $input);
            self::assertSame($parsed['bytes'], $format->parse($format->build(['bytes' => $parsed['bytes']]))['bytes'], $input);
        }
    }

    public function testGeneratedSerializePayloadsAreByteEqual(): void
    {
        mt_srand(self::SEED);
        $format = new Serialize();
        for ($i = 0; $i < self::RUNS; $i++) {
            $payload = serialize(self::randomValue(0));
            self::assertSame($payload, $format->build($format->parse($payload)));
        }
    }

    public function testGeneratedQueryStrings(): void
    {
        mt_srand(self::SEED);
        $format = new QueryString();
        for ($i = 0; $i < self::RUNS; $i++) {
            $parts = [];
            for ($j = mt_rand(1, 5); $j > 0; $j--) {
                $parts[self::randomWord()] = mt_rand(0, 2) === 0 ? [self::randomText(), self::randomText()] : self::randomText();
            }
            $parsed = $format->parse($format->build($parts));
            self::assertSame($parsed, $format->parse($format->build($parsed)));
        }
    }

    public function testGeneratedIniFiles(): void
    {
        mt_srand(self::SEED);
        $format = new Ini();
        // a value needing quotes cannot hold '"' or end with a backslash (BuildException), so the generator avoids both
        $text = static fn (): string => str_replace(['"', '\\'], '', self::randomText());
        for ($i = 0; $i < self::RUNS; $i++) {
            $parts = ['global' => [], 'sections' => []];
            for ($j = mt_rand(0, 4); $j > 0; $j--) {
                $parts['global']['g' . self::randomWord()] = $text();
            }
            for ($j = mt_rand(0, 3); $j > 0; $j--) {
                $parts['sections'][self::randomWord()] = ['k' . self::randomWord() => [$text(), $text()]];
            }
            $ini = $format->build($parts);
            self::assertSame($parts, $format->parse($ini), $ini);
        }
    }

    public function testGeneratedUrls(): void
    {
        mt_srand(self::SEED);
        $format = new Url(UrlFlag::AllowRelative);
        for ($i = 0; $i < self::RUNS; $i++) {
            $parts = [
                'scheme' => mt_rand(0, 3) === 0 ? null : 'h' . self::randomWord(),
                'host' => mt_rand(0, 3) === 0 ? null : self::randomWord() . '.example',
                'path' => '/' . rawurlencode(self::randomText()),
                'query' => mt_rand(0, 1) === 0 ? null : rawurlencode(self::randomText()),
                'fragment' => mt_rand(0, 1) === 0 ? null : rawurlencode(self::randomText()),
            ];
            if ($parts['host'] !== null) {
                $parts['port'] = mt_rand(0, 1) === 0 ? null : mt_rand(0, 65535);
            }
            $parsed = $format->parse($format->build($parts));
            self::assertSame($parsed, $format->parse($format->build($parsed)));
        }
    }

    private static function randomValue(int $depth): mixed
    {
        return match ($depth > 3 ? mt_rand(0, 4) : mt_rand(0, 6)) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            3 => mt_rand() / mt_getrandmax() * 10 ** mt_rand(-20, 20),
            4 => self::randomText(),
            5 => array_map(static fn () => self::randomValue($depth + 1), array_fill(0, mt_rand(0, 4), null)),
            6 => (object) [self::randomWord() => self::randomValue($depth + 1)],
        };
    }

    private static function randomWord(): string
    {
        $word = '';
        for ($i = mt_rand(1, 8); $i > 0; $i--) {
            $word .= chr(mt_rand(ord('a'), ord('z')));
        }

        return $word;
    }

    private static function randomText(): string
    {
        $alphabet = "abc XYZ 012 _-.~ !$'()*+,=:@/? é€ \\\"";
        $text = '';
        for ($i = mt_rand(0, 12); $i > 0; $i--) {
            $text .= mb_substr($alphabet, mt_rand(0, mb_strlen($alphabet) - 1), 1);
        }

        return $text;
    }
}
