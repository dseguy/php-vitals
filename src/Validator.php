<?php

declare(strict_types=1);

namespace Vitals;

interface Validator
{
    public function __construct(Flags ...$flags);

    public function validate(string $input): bool;

    /**
     * @return Violation|null null when the input is valid
     */
    public function check(string $input): ?Violation;
}
