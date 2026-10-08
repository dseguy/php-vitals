<?php

declare(strict_types=1);

namespace Vitals\Option;

use Vitals\Exception\ConfigurationException;
use Vitals\Flags;

final readonly class MaxDepth implements Flags
{
    public function __construct(public int $depth)
    {
        if ($depth < 1) {
            throw new ConfigurationException('MaxDepth must be at least 1, ' . $depth . ' given');
        }
    }
}
