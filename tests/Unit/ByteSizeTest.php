<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Vitals\Exception\BuildException;
use Vitals\Flag\ByteSizeFlag;
use Vitals\Format\ByteSize;
use Vitals\Parser;
use Vitals\Tests\Fixtures;
use Vitals\Validator;

final class ByteSizeTest extends FormatTestCase
{
    protected function format(): Parser&Validator
    {
        return new ByteSize();
    }

    public static function validInputs(): iterable
    {
        return Fixtures::valid('bytesize');
    }

    public static function invalidInputs(): iterable
    {
        return Fixtures::invalid('bytesize');
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
    public function testSameBytesAsIniParseQuantity(string $input): void
    {
        self::assertSame(ini_parse_quantity($input), (new ByteSize())->parse($input)['bytes']);
    }

    public function testParse(): void
    {
        self::assertSame(['bytes' => 134217728, 'value' => 128, 'unit' => 'M'], (new ByteSize())->parse('128m'));
    }

    public function testAllowWhitespace(): void
    {
        $format = new ByteSize(ByteSizeFlag::AllowWhitespace);
        self::assertSame(2048, $format->parse(" 2K\t")['bytes']);
        self::assertSame('common.empty_input', $format->check('  ')?->code->value);
    }

    public function testBuild(): void
    {
        $format = new ByteSize();
        self::assertSame('128M', $format->build(['bytes' => 134217728]));
        self::assertSame('1536K', $format->build(['bytes' => 1536 * 1024]));
        self::assertSame('1000', $format->build(['bytes' => 1000]));
        self::assertSame('0', $format->build(['bytes' => 0]));
        self::assertSame('-2G', $format->build(['bytes' => -2 * 1024 ** 3]));
        self::assertSame('3K', $format->build(['value' => 3, 'unit' => 'K']));
        self::assertSame('3K', $format->build(['value' => 3, 'unit' => 'K', 'bytes' => 3072]));
        self::assertSame('7', $format->build(['value' => 7]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function impossibleParts(): iterable
    {
        yield 'conflict' => [['value' => 3, 'unit' => 'K', 'bytes' => 3000]];
        yield 'nothing' => [[]];
        yield 'unit without value' => [['unit' => 'K', 'bytes' => 1024]];
        yield 'unknown unit' => [['value' => 1, 'unit' => 'T']];
        yield 'lowercase unit' => [['value' => 1, 'unit' => 'k']];
        yield 'overflow' => [['value' => PHP_INT_MAX, 'unit' => 'K']];
        yield 'unknown key' => [['value' => 1, 'size' => 1]];
        yield 'string value' => [['value' => '1']];
    }

    /**
     * @param array<string, mixed> $parts
     */
    #[DataProvider('impossibleParts')]
    public function testBuildRejects(array $parts): void
    {
        $this->expectException(BuildException::class);
        (new ByteSize())->build($parts);
    }
}
