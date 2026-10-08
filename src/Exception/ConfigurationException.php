<?php

declare(strict_types=1);

namespace Vitals\Exception;

/**
 * Thrown by a constructor on invalid flags or options.
 */
final class ConfigurationException extends \LogicException implements VitalsException
{
}
