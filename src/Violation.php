<?php

declare(strict_types=1);

namespace Vitals;

final readonly class Violation
{
    public function __construct(
        public ViolationCode $code,
        public string $message,
        public ?int $offset = null,   // byte offset; some violations have no position
    ) {
    }
}
