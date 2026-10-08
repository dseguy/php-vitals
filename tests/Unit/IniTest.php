<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Vitals\Exception\BuildException;
use Vitals\Flag\IniFlag;
use Vitals\Format\Ini;
use Vitals\Parser;
use Vitals\Tests\Fixtures;
use Vitals\Validator;

final class IniTest extends FormatTestCase
{
    protected function format(): Parser&Validator
    {
        return new Ini();
    }

    public static function validInputs(): iterable
    {
        return Fixtures::valid('ini');
    }

    public static function invalidInputs(): iterable
    {
        return Fixtures::invalid('ini');
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

    #[DataProvider('validInputs')]
    public function testSameValuesAsParseIniString(string $input): void
    {
        $parsed = (new Ini())->parse($input);
        $native = parse_ini_string($input, true, INI_SCANNER_RAW);

        // the native array merges global directives and sections
        self::assertSame($native, array_merge($parsed['global'], $parsed['sections']));
    }

    public function testParse(): void
    {
        self::assertSame(
            ['global' => ['a' => '1'], 'sections' => ['s' => ['a' => '2', 'k' => ['x', 'y' => 'z']]]],
            (new Ini())->parse("a = 1\n[s]\na = 2\nk[] = x\nk[y] = z\n"),
        );
    }

    public function testDuplicatesAllowed(): void
    {
        $format = new Ini(IniFlag::AllowDuplicateKeys, IniFlag::AllowDuplicateSections);
        self::assertSame(
            ['global' => ['a' => '2'], 'sections' => ['s' => ['c' => '4']]],
            $format->parse("a = 1\na = 2\n[s]\nb = 3\n[s]\nc = 4\n"),
        );
    }

    public function testBuild(): void
    {
        $parts = [
            'global' => ['t' => true, 'f' => false, 'n' => null, 'i' => 3, 'x' => 1.5, 's' => 'plain', 'e' => '', 'w' => ' padded ', 'c' => 'a;b'],
            'sections' => ['db' => ['hosts' => ['h1', 'h2'], 'map' => ['k' => 'v']]],
        ];
        $expected = <<<'INI'
            t = true
            f = false
            n =
            i = 3
            x = 1.5
            s = plain
            e = ""
            w = " padded "
            c = "a;b"

            [db]
            hosts[] = h1
            hosts[] = h2
            map[k] = v

            INI;
        self::assertSame($expected, (new Ini())->build($parts));
    }

    public function testBuildFlags(): void
    {
        $format = new Ini(IniFlag::BoolOnOff, IniFlag::NullWord, IniFlag::ArrayIndexed, IniFlag::QuoteAlways);
        self::assertSame(
            "t = on\nn = null\nl[0] = \"x\"\ns = \"y\"\n",
            $format->build(['global' => ['t' => true, 'n' => null, 'l' => ['x'], 's' => 'y']]),
        );
        self::assertSame("t = yes\nf = no\n", (new Ini(IniFlag::BoolYesNo))->build(['global' => ['t' => true, 'f' => false]]));
        self::assertSame("t = 1\nf = 0\n", (new Ini(IniFlag::BoolOneZero))->build(['global' => ['t' => true, 'f' => false]]));
    }

    public function testBuildOutputIsReadByPhp(): void
    {
        $parts = ['global' => ['a' => 'x"y', 'b' => 'semi;colon', 'c' => ' sp ', 'd' => 'back\\slash'], 'sections' => ['s' => ['k' => ['1', '2']]]];
        $ini = (new Ini())->build($parts);
        self::assertSame(
            ['a' => 'x"y', 'b' => 'semi;colon', 'c' => ' sp ', 'd' => 'back\\slash', 's' => ['k' => ['1', '2']]],
            parse_ini_string($ini, true, INI_SCANNER_RAW),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function impossibleParts(): iterable
    {
        yield 'reserved key' => [['global' => ['yes' => '1']]];
        yield 'invalid key' => [['global' => ['a=b' => '1']]];
        yield 'empty section name' => [['sections' => ['' => []]]];
        yield 'nested array' => [['global' => ['a' => [['x']]]]];
        yield 'empty array' => [['global' => ['a' => []]]];
        yield 'newline in value' => [['global' => ['a' => "x\ny"]]];
        yield 'quote and semicolon' => [['global' => ['a' => 'x";y']]];
        yield 'NAN' => [['global' => ['a' => NAN]]];
        yield 'object' => [['global' => ['a' => new \stdClass()]]];
        yield 'unknown key' => [['globals' => []]];
    }

    /**
     * @param array<string, mixed> $parts
     */
    #[DataProvider('impossibleParts')]
    public function testBuildRejects(array $parts): void
    {
        $this->expectException(BuildException::class);
        (new Ini())->build($parts);
    }
}
