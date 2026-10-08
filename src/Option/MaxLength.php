<?php

declare(strict_types=1);

namespace Vitals\Option;

use Vitals\Exception\ConfigurationException;
use Vitals\Flags;

final readonly class MaxLength implements Flags
{
    public function __construct(public int $bytes)
    {
        if ($bytes < 1) {
            throw new ConfigurationException('MaxLength must be at least 1, ' . $bytes . ' given');
        }
    }
}
