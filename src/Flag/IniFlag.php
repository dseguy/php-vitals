<?php

declare(strict_types=1);

namespace Vitals\Flag;

use Vitals\Flags;

enum IniFlag implements Flags
{
    case AllowDuplicateKeys;
    case AllowDuplicateSections;
    case BoolTrueFalse;
    case BoolOnOff;
    case BoolYesNo;
    case BoolOneZero;
    case NullEmpty;
    case NullWord;
    case ArrayAppend;
    case ArrayIndexed;
    case QuoteWhenNeeded;
    case QuoteAlways;

    /**
     * Mutually exclusive groups; the first case of each group is the default.
     *
     * @return list<list<self>>
     */
    public static function groups(): array
    {
        return [
            [self::BoolTrueFalse, self::BoolOnOff, self::BoolYesNo, self::BoolOneZero],
            [self::NullEmpty, self::NullWord],
            [self::ArrayAppend, self::ArrayIndexed],
            [self::QuoteWhenNeeded, self::QuoteAlways],
        ];
    }
}
