<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vitals\Exception\FormatException;
use Vitals\Parser;
use Vitals\Validator;

/**
 * Checks the contract shared by every format on its fixture corpus.
 */
abstract class FormatTestCase extends TestCase
{
    abstract protected function format(): Parser&Validator;

    protected function assertValid(string $input): void
    {
        $format = $this->format();
        $violation = $format->check($input);
        self::assertNull($violation, $violation === null ? '' : sprintf('%s at %s: %s', $violation->code->value, var_export($violation->offset, true), $violation->message));
        self::assertTrue($format->validate($input));
        $format->parse($input);
    }

    protected function assertInvalid(string $input, string $code): void
    {
        $format = $this->format();
        $violation = $format->check($input);
        self::assertNotNull($violation, 'input should be invalid');
        self::assertSame($code, $violation->code->value, $violation->message);
        self::assertFalse($format->validate($input));
        if ($violation->offset !== null) {
            self::assertGreaterThanOrEqual(0, $violation->offset);
            self::assertLessThanOrEqual(strlen($input), $violation->offset);
        }

        try {
            $format->parse($input);
            self::fail('parse() should throw');
        } catch (FormatException $exception) {
            self::assertEquals($violation, $exception->violation);
        }
    }
}
