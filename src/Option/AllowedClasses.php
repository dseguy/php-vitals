<?php

declare(strict_types=1);

namespace Vitals\Option;

use Vitals\Exception\ConfigurationException;
use Vitals\Flags;

final readonly class AllowedClasses implements Flags
{
    /** @var list<string> */
    public array $classes;

    public function __construct(string ...$classes)
    {
        if ($classes === []) {
            throw new ConfigurationException('AllowedClasses needs at least one value');
        }
        $this->classes = array_values($classes);
    }

    /**
     * Case-insensitive membership test.
     */
    public function allows(string $name): bool
    {
        foreach ($this->classes as $allowed) {
            if (strcasecmp(ltrim($allowed, '\\'), ltrim($name, '\\')) === 0) {
                return true;
            }
        }

        return false;
    }
}
