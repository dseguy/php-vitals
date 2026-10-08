<?php

declare(strict_types=1);

namespace Vitals\Tests\Unit\Stub;

class Base
{
    public int $public = 1;
    protected array $protected = [1, 'x' => 2.5];
    private ?string $hidden = null;
}
