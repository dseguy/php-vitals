<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Vitals\Exception\BuildException;
use Vitals\Flag\SerializeFlag;
use Vitals\Format\Serialize;
use Vitals\Option\AllowedClasses;
use Vitals\Option\MaxDepth;
use Vitals\Parser;
use Vitals\Tests\Fixtures;
use Vitals\Tests\Unit\Stub\Child;
use Vitals\Tests\Unit\Stub\Suit;
use Vitals\Validator;

final class SerializeTest extends FormatTestCase
{
    protected function format(): Parser&Validator
    {
        return new Serialize();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validInputs(): iterable
    {
        $values = [
            'null' => null,
            'true' => true,
            'int' => -42,
            'min int' => PHP_INT_MIN,
            'float' => 0.1,
            'big float' => -1.5e300,
            'INF' => INF,
            'NAN' => NAN,
            'empty string' => '',
            'binary string' => "\xff\0\"';",
            'nested array' => [1, 'a' => [2, [3]], '' => null],
            'object' => new Child(),
            'enum' => Suit::Hearts,
            'stdClass' => (object) ['a' => 1, '0' => 2],
            'ArrayObject' => new \ArrayObject([1, 2]),
        ];
        foreach ($values as $name => $value) {
            yield $name => [serialize($value)];
        }
        yield 'custom' => ['C:3:"Foo":5:{hello}'];
    }

    public static function invalidInputs(): iterable
    {
        return Fixtures::invalid('serialize');
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
    public function testBuildIsByteEqual(string $input): void
    {
        $format = new Serialize();
        self::assertSame($input, $format->build($format->parse($input)));
    }

    public function testObjectShape(): void
    {
        $parsed = (new Serialize())->parse(serialize(new Child()));
        self::assertSame('object', $parsed['type']);
        self::assertSame(Child::class, $parsed['class']);
        /** @var list<array{name: int|string, visibility: string, value: array<string, mixed>, declaringClass: ?string}> $properties */
        $properties = $parsed['properties'];
        self::assertSame(
            [
                ['name' => 'public', 'visibility' => 'public', 'declaringClass' => null],
                ['name' => 'protected', 'visibility' => 'protected', 'declaringClass' => null],
                ['name' => 'hidden', 'visibility' => 'private', 'declaringClass' => Stub\Base::class],
                ['name' => 'hidden', 'visibility' => 'private', 'declaringClass' => Child::class],
            ],
            array_map(
                static fn (array $p): array => ['name' => $p['name'], 'visibility' => $p['visibility'], 'declaringClass' => $p['declaringClass']],
                $properties,
            ),
        );
    }

    public function testScalarShapes(): void
    {
        $format = new Serialize();
        self::assertSame(['type' => 'array', 'items' => [
            ['key' => 0, 'value' => ['type' => 'int', 'value' => 1]],
            ['key' => 'a', 'value' => ['type' => 'bool', 'value' => false]],
        ]], $format->parse(serialize([1, 'a' => false])));
        self::assertSame(['type' => 'enum', 'class' => Suit::class, 'case' => 'Hearts'], $format->parse(serialize(Suit::Hearts)));
    }

    public function testNoObjects(): void
    {
        $format = new Serialize(SerializeFlag::NoObjects);
        self::assertTrue($format->validate(serialize([1, 'a' => ['b']])));
        foreach ([new Child(), Suit::Hearts, [new \stdClass()]] as $value) {
            self::assertSame('serialize.disallowed_class', $format->check(serialize($value))?->code->value);
        }
        self::assertSame('serialize.disallowed_class', $format->check('C:3:"Foo":0:{}')?->code->value);
    }

    public function testAllowedClasses(): void
    {
        $format = new Serialize(new AllowedClasses('\\' . strtoupper(Child::class)));
        self::assertTrue($format->validate(serialize(new Child())));
        self::assertSame('serialize.disallowed_class', $format->check(serialize(new \stdClass()))?->code->value);
    }

    public function testDepthLimit(): void
    {
        $value = 1;
        for ($i = 0; $i < 3; $i++) {
            $value = [$value];
        }
        self::assertTrue((new Serialize(new MaxDepth(3)))->validate(serialize($value)));
        self::assertSame('common.too_deep', (new Serialize(new MaxDepth(2)))->check(serialize($value))?->code->value);
    }

    public function testNeverInstantiates(): void
    {
        $payload = 'O:' . strlen(Stub\Exploding::class) . ':"' . Stub\Exploding::class . '":0:{}';
        Stub\Exploding::$woken = false;
        self::assertTrue((new Serialize())->validate($payload));
        self::assertFalse(Stub\Exploding::$woken);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function impossibleParts(): iterable
    {
        yield 'unknown type' => [['type' => 'resource']];
        yield 'missing value' => [['type' => 'int']];
        yield 'wrong value type' => [['type' => 'int', 'value' => '1']];
        yield 'extra key' => [['type' => 'null', 'value' => null]];
        yield 'bad array item' => [['type' => 'array', 'items' => [['key' => 0]]]];
        yield 'bad key' => [['type' => 'array', 'items' => [['key' => 1.5, 'value' => ['type' => 'null']]]]];
        yield 'bad class' => [['type' => 'object', 'class' => '1x', 'properties' => []]];
        yield 'private without class' => [['type' => 'object', 'class' => 'A', 'properties' => [['name' => 'p', 'visibility' => 'private', 'value' => ['type' => 'null'], 'declaringClass' => null]]]];
        yield 'public with class' => [['type' => 'object', 'class' => 'A', 'properties' => [['name' => 'p', 'visibility' => 'public', 'value' => ['type' => 'null'], 'declaringClass' => 'A']]]];
    }

    /**
     * @param array<string, mixed> $parts
     */
    #[DataProvider('impossibleParts')]
    public function testBuildRejects(array $parts): void
    {
        $this->expectException(BuildException::class);
        (new Serialize())->build($parts);
    }
}
