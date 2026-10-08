<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit\Stub;

final class Exploding
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
