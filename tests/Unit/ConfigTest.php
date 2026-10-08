<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vitals\Exception\ConfigurationException;
use Vitals\Exception\FormatException;
use Vitals\Exception\VitalsException;
use Vitals\Flag\IniFlag;
use Vitals\Flag\UrlFlag;
use Vitals\Format\ByteSize;
use Vitals\Format\Ini;
use Vitals\Format\QueryString;
use Vitals\Format\Url;
use Vitals\Option\AllowedClasses;
use Vitals\Option\MaxDepth;
use Vitals\Option\MaxLength;

final class ConfigTest extends TestCase
{
    public function testForeignFlagIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Ini(UrlFlag::Idn);
    }

    public function testUnsupportedOptionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Url(new AllowedClasses('A'));
    }

    public function testExclusiveFlagsAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Ini(IniFlag::BoolOnOff, IniFlag::BoolYesNo);
    }

    public function testRepeatedOptionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Ini(new MaxDepth(2), new MaxDepth(3));
    }

    public function testRepeatedFlagIsAccepted(): void
    {
        self::assertTrue((new Ini(IniFlag::AllowDuplicateKeys, IniFlag::AllowDuplicateKeys))->validate("a=1\na=2"));
    }

    public function testOptionValuesAreChecked(): void
    {
        $this->expectException(ConfigurationException::class);
        new MaxDepth(0);
    }

    public function testMaxLength(): void
    {
        $format = new ByteSize(new MaxLength(3));
        self::assertTrue($format->validate('12M'));
        $violation = $format->check('123M');
        self::assertSame('common.input_too_long', $violation?->code->value);
        self::assertSame(3, $violation?->offset);
    }

    public function testExceptionsShareTheInterface(): void
    {
        try {
            (new QueryString())->parse('a=%');
            self::fail('parse() should throw');
        } catch (VitalsException $exception) {
            self::assertInstanceOf(FormatException::class, $exception);
            self::assertInstanceOf(\UnexpectedValueException::class, $exception);
            self::assertSame('"%" must be followed by two hexadecimal digits, at offset 2', $exception->getMessage());
        }
    }
}
