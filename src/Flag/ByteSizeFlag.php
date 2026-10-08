<?php

declare(strict_types=1);

namespace Vitals\Flag;

use Vitals\Flags;

enum ByteSizeFlag implements Flags
{
    case AllowWhitespace;

    /**
     * Mutually exclusive groups; the first case of each group is the default.
     *
     * @return list<list<self>>
     */
    public static function groups(): array
    {
        return [];
    }
}
