<?php

declare(strict_types=1);

namespace Vitals\Exception;

use Vitals\Violation;
use Vitals\ViolationCode;

/**
 * Thrown by parse() on malformed input. Carries the same Violation as check().
 */
final class FormatException extends \UnexpectedValueException implements VitalsException
{
    public function __construct(
        public readonly Violation $violation,
        ?\Throwable $previous = null,
    ) {
        $message = $violation->offset === null
            ? $violation->message
            : sprintf('%s, at offset %d', $violation->message, $violation->offset);

        parent::__construct($message, 0, $previous);
    }

    public static function of(ViolationCode $code, string $message, ?int $offset = null): self
    {
        return new self(new Violation($code, $message, $offset));
    }
}
