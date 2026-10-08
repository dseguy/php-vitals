<?php

declare(strict_types=1);

namespace Vitals\Internal;

/**
 * Runs a native function under a scoped error handler, instead of using @.
 *
 * @internal
 */
final class NativeCall
{
    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{T, string|null} the result, and the first warning or notice raised, if any
     */
    public static function run(callable $call): array
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }
}
