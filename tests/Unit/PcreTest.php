<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Vitals\Exception\BuildException;
use Vitals\Flag\PcreFlag;
use Vitals\Format\Pattern\Pcre;
use Vitals\Parser;
use Vitals\Tests\Fixtures;
use Vitals\Validator;

final class PcreTest extends FormatTestCase
{
    /** Intentional divergences: PHP skips whitespace before the delimiter and among modifiers. */
    private const STRICTER_THAN_PHP = [' /a/', '/a/ i'];

    protected function format(): Parser&Validator
    {
        return new Pcre();
    }

    public static function validInputs(): iterable
    {
        return Fixtures::valid('pcre');
    }

    public static function invalidInputs(): iterable
    {
        return Fixtures::invalid('pcre');
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function allInputs(): iterable
    {
        yield from Fixtures::valid('pcre');
        foreach (Fixtures::invalid('pcre') as $name => [$input]) {
            yield $name => [$input];
        }
    }

    #[DataProvider('allInputs')]
    public function testAgreesWithPregMatch(string $input): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $native = preg_match($input, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (in_array($input, self::STRICTER_THAN_PHP, true)) {
            self::assertTrue($native);
            self::assertFalse((new Pcre())->validate($input));

            return;
        }

        self::assertSame($native, (new Pcre())->validate($input));
    }

    public function testParse(): void
    {
        self::assertSame(['delimiter' => '(', 'pattern' => 'a(b)c', 'modifiers' => 'x'], (new Pcre())->parse('(a(b)c)x'));
        self::assertSame(['delimiter' => '/', 'pattern' => 'a\/b', 'modifiers' => 'iu'], (new Pcre())->parse('/a\/b/iu'));
    }

    public function testCompileErrorOffsetAndMessage(): void
    {
        $violation = (new Pcre())->check('/ab(c/');
        self::assertNotNull($violation);
        self::assertSame('missing closing parenthesis', $violation->message);
        self::assertSame(5, $violation->offset);
    }

    public function testBodyOnly(): void
    {
        $format = new Pcre(PcreFlag::BodyOnly);
        self::assertSame(['delimiter' => '', 'pattern' => 'a/b#c~d', 'modifiers' => ''], $format->parse('a/b#c~d'));
        self::assertTrue($format->validate('/#~%!@;,=|`x'));
        self::assertSame('pcre.compile_error', $format->check('a(')?->code->value);
        self::assertSame(2, $format->check('a(')?->offset);
        self::assertSame('a(b)', $format->build(['pattern' => 'a(b)']));
    }

    public function testBuild(): void
    {
        $format = new Pcre();
        self::assertSame('/a+/i', $format->build(['delimiter' => '/', 'pattern' => 'a+', 'modifiers' => 'i']));
        self::assertSame('{a}', $format->build(['delimiter' => '{', 'pattern' => 'a']));
        self::assertSame('{a{2}}', $format->build(['delimiter' => '{', 'pattern' => 'a{2}']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function impossibleParts(): iterable
    {
        yield 'unescaped delimiter' => [['delimiter' => '/', 'pattern' => 'a/b']];
        yield 'trailing backslash' => [['delimiter' => '/', 'pattern' => 'a\\']];
        yield 'alphanumeric delimiter' => [['delimiter' => 'a', 'pattern' => 'b']];
        yield 'two-character delimiter' => [['delimiter' => '//', 'pattern' => 'b']];
        yield 'unknown modifier' => [['delimiter' => '/', 'pattern' => 'b', 'modifiers' => 'e']];
        yield 'unknown key' => [['delimiter' => '/', 'body' => 'b']];
    }

    /**
     * @param array<string, mixed> $parts
     */
    #[DataProvider('impossibleParts')]
    public function testBuildRejects(array $parts): void
    {
        $this->expectException(BuildException::class);
        (new Pcre())->build($parts);
    }
}
