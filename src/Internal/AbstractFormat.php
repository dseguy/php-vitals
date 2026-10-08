<?php

declare(strict_types=1);

namespace Vitals\Internal;

use Vitals\Exception\FormatException;
use Vitals\Flags;
use Vitals\Violation;
use Vitals\ViolationCode;

/**
 * Shared constructor, limits and parse/validate/check plumbing.
 *
 * @internal
 */
abstract readonly class AbstractFormat
{
    protected Config $config;

    final public function __construct(Flags ...$flags)
    {
        $this->config = Config::resolve(static::class, static::flagEnum(), static::optionClasses(), $flags);
        $this->checkRequirements();
    }

    /**
     * @return array<string|int, mixed>
     */
    public function parse(string $input): array
    {
        if (strlen($input) > $this->config->maxLength()) {
            throw FormatException::of(
                ViolationCode::CommonInputTooLong,
                sprintf('input is longer than %d bytes', $this->config->maxLength()),
                $this->config->maxLength(),
            );
        }

        return $this->analyse($input);
    }

    public function validate(string $input): bool
    {
        return $this->check($input) === null;
    }

    public function check(string $input): ?Violation
    {
        try {
            $this->checkParsed($this->parse($input));
        } catch (FormatException $exception) {
            return $exception->violation;
        }

        return null;
    }

    /**
     * @return class-string|null
     */
    abstract protected static function flagEnum(): ?string;

    /**
     * @return list<class-string>
     */
    protected static function optionClasses(): array
    {
        return [];
    }

    /**
     * Throws ConfigurationException when a flag needs something unavailable.
     */
    protected function checkRequirements(): void
    {
    }

    /**
     * @return array<string|int, mixed>
     *
     * @throws FormatException
     */
    abstract protected function analyse(string $input): array;

    /**
     * Validation-only rules, applied by validate() and check() after parsing.
     *
     * @param array<string|int, mixed> $parsed
     *
     * @throws FormatException
     */
    protected function checkParsed(array $parsed): void
    {
    }
}
