<?php

declare(strict_types=1);

namespace Vitals\Option;

use Vitals\Exception\ConfigurationException;
use Vitals\Flags;

final readonly class AllowedSchemes implements Flags
{
    /** @var list<string> */
    public array $schemes;

    public function __construct(string ...$schemes)
    {
        if ($schemes === []) {
            throw new ConfigurationException('AllowedSchemes needs at least one value');
        }
        $this->schemes = array_values($schemes);
    }

    /**
     * Case-insensitive membership test.
     */
    public function allows(string $name): bool
    {
        foreach ($this->schemes as $allowed) {
            if (strcasecmp(ltrim($allowed, '\\'), ltrim($name, '\\')) === 0) {
                return true;
            }
        }

        return false;
    }
}
