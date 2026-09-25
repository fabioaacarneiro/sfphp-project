<?php

namespace SfphpProject\src\Console\Generators;

use RuntimeException;

/**
 * A generator was asked for a file that is already there.
 */
final class GeneratorFileExists extends RuntimeException
{
}
