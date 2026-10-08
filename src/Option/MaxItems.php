<?php

declare(strict_types=1);

namespace Vitals\Option;

use Vitals\Exception\ConfigurationException;
use Vitals\Flags;

final readonly class MaxItems implements Flags
{
    public function __construct(public int $items)
    {
        if ($items < 1) {
            throw new ConfigurationException('MaxItems must be at least 1, ' . $items . ' given');
        }
    }
}
