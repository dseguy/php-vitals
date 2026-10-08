<?php

declare(strict_types=1);

namespace Vitals;

interface Builder
{
    public function __construct(Flags ...$flags);

    /**
     * @param array<string|int, mixed> $parts structured representation
     *
     * @throws Exception\BuildException when the representation cannot be written
     */
    public function build(array $parts): string;
}
