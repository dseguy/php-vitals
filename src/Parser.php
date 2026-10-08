<?php

declare(strict_types=1);

namespace Vitals;

interface Parser
{
    public function __construct(Flags ...$flags);

    /**
     * @return array<string|int, mixed> structured representation
     *
     * @throws Exception\FormatException on malformed input
     */
    public function parse(string $input): array;
}
