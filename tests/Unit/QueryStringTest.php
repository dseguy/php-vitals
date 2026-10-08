<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Vitals\Exception\BuildException;
use Vitals\Flag\QueryStringFlag;
use Vitals\Format\QueryString;
use Vitals\Option\MaxItems;
use Vitals\Parser;
use Vitals\Tests\Fixtures;
use Vitals\Validator;

final class QueryStringTest extends FormatTestCase
{
    protected function format(): Parser&Validator
    {
        return new QueryString();
    }

    public static function validInputs(): iterable
    {
        return Fixtures::valid('querystring');
    }

    public static function invalidInputs(): iterable
    {
        return Fixtures::invalid('querystring');
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
    public function testSameResultAsParseStr(string $input): void
    {
        parse_str($input, $native);
        self::assertSame($native, (new QueryString())->parse($input));
    }

    public function testParse(): void
    {
        self::assertSame(
            ['a' => '1', 'b' => ['2', '3'], 'c' => ['x' => ['y' => '4']], 'd e' => 'f g'],
            (new QueryString(QueryStringFlag::PreserveKeyNames))->parse('a=1&b[]=2&b[]=3&c[x][y]=4&d+e=f%20g'),
        );
        self::assertSame(['a_b' => '1'], (new QueryString())->parse('a.b=1'));
    }

    public function testMaxItems(): void
    {
        $format = new QueryString(new MaxItems(2));
        self::assertTrue($format->validate('a=1&b=2'));
        self::assertSame('common.too_many_items', $format->check('a=1&b=2&c=3')?->code->value);
    }

    public function testBuild(): void
    {
        $parts = ['a' => 'x y', 'b' => ['1', '2'], 'c' => ['k' => ['l' => 'é']]];
        self::assertSame('a=x%20y&b%5B0%5D=1&b%5B1%5D=2&c%5Bk%5D%5Bl%5D=%C3%A9', (new QueryString())->build($parts));
        self::assertSame('a=x+y&b%5B0%5D=1&b%5B1%5D=2&c%5Bk%5D%5Bl%5D=%C3%A9', (new QueryString(QueryStringFlag::Rfc1738))->build($parts));
        self::assertSame('', (new QueryString())->build([]));
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>}>
     */
    public static function impossibleParts(): iterable
    {
        yield 'empty name' => [['' => 'x']];
        yield 'bracket in name' => [['a[' => 'x']];
        yield 'empty array' => [['a' => []]];
        yield 'int value' => [['a' => 1]];
        yield 'null value' => [['a' => null]];
    }

    /**
     * @param array<int|string, mixed> $parts
     */
    #[DataProvider('impossibleParts')]
    public function testBuildRejects(array $parts): void
    {
        $this->expectException(BuildException::class);
        (new QueryString())->build($parts);
    }
}
