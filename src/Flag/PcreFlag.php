<?php

declare(strict_types=1);

namespace Vitals\Flag;

use Vitals\Flags;

enum PcreFlag implements Flags
{
    case BodyOnly;

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
