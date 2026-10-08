<?php

declare(strict_types=1);

namespace Vitals\Exception;

/**
 * Thrown by build() when the structured representation cannot be written.
 */
final class BuildException extends \InvalidArgumentException implements VitalsException
{
}
