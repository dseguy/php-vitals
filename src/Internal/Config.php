<?php

declare(strict_types=1);

namespace Vitals\Internal;

use Vitals\Exception\ConfigurationException;
use Vitals\Flags;
use Vitals\Option\MaxDepth;
use Vitals\Option\MaxItems;
use Vitals\Option\MaxLength;

/**
 * Validated set of flags and options given to a format constructor.
 *
 * @internal
 */
final readonly class Config
{
    public const DEFAULT_MAX_DEPTH = 10;
    public const DEFAULT_MAX_LENGTH = 1024 * 1024;
    public const DEFAULT_MAX_ITEMS = 1000;

    /**
     * @param list<\UnitEnum>       $flags
     * @param array<string, object> $options option class => option
     */
    private function __construct(
        private array $flags,
        private array $options,
    ) {
    }

    /**
     * @param class-string|null   $flagEnum      enum whose cases the format accepts
     * @param list<class-string>  $optionClasses options the format accepts, besides the common ones
     * @param array<Flags>        $given
     */
    public static function resolve(string $format, ?string $flagEnum, array $optionClasses, array $given): self
    {
        $optionClasses = [...$optionClasses, MaxDepth::class, MaxLength::class, MaxItems::class];
        $flags = [];
        $options = [];

        foreach ($given as $item) {
            if ($item instanceof \UnitEnum) {
                if ($flagEnum === null || !$item instanceof $flagEnum) {
                    throw new ConfigurationException(sprintf('%s does not support the flag %s::%s', $format, $item::class, $item->name));
                }
                if (!in_array($item, $flags, true)) {
                    $flags[] = $item;
                }
                continue;
            }

            if (!in_array($item::class, $optionClasses, true)) {
                throw new ConfigurationException(sprintf('%s does not support the option %s', $format, $item::class));
            }
            if (isset($options[$item::class])) {
                throw new ConfigurationException(sprintf('%s: option %s given twice', $format, $item::class));
            }
            $options[$item::class] = $item;
        }

        if ($flagEnum !== null) {
            /** @var list<list<\UnitEnum>> $groups */
            $groups = $flagEnum::groups();
            foreach ($groups as $group) {
                $chosen = array_filter($flags, static fn (\UnitEnum $flag): bool => in_array($flag, $group, true));
                if (count($chosen) > 1) {
                    throw new ConfigurationException(sprintf(
                        '%s: flags %s are mutually exclusive',
                        $format,
                        implode(', ', array_map(static fn (\UnitEnum $flag): string => $flag->name, $chosen)),
                    ));
                }
            }
        }

        return new self($flags, $options);
    }

    public function has(\UnitEnum $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * The chosen flag of an exclusive group, or the group default (its first case).
     *
     * @template T of \UnitEnum
     *
     * @param non-empty-list<T> $group
     *
     * @return T
     */
    public function choice(array $group): \UnitEnum
    {
        foreach ($group as $flag) {
            if ($this->has($flag)) {
                return $flag;
            }
        }

        return $group[0];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function option(string $class): ?object
    {
        /** @var T|null */
        return $this->options[$class] ?? null;
    }

    public function maxDepth(int $default = self::DEFAULT_MAX_DEPTH): int
    {
        return $this->option(MaxDepth::class)?->depth ?? $default;
    }

    public function maxLength(): int
    {
        return $this->option(MaxLength::class)?->bytes ?? self::DEFAULT_MAX_LENGTH;
    }

    public function maxItems(): int
    {
        $items = $this->option(MaxItems::class)?->items;
        if ($items !== null) {
            return $items;
        }

        $native = ini_get('max_input_vars');

        return is_string($native) && ctype_digit($native) && (int) $native > 0 ? (int) $native : self::DEFAULT_MAX_ITEMS;
    }
}
