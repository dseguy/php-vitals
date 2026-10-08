<?php

declare(strict_types=1);

namespace Vitals\Flag;

use Vitals\Flags;

enum QueryStringFlag implements Flags
{
    case Rfc3986;
    case Rfc1738;
    case PreserveKeyNames;

    /**
     * Mutually exclusive groups; the first case of each group is the default.
     *
     * @return list<list<self>>
     */
    public static function groups(): array
    {
        return [
            [self::Rfc3986, self::Rfc1738],
        ];
    }
}
