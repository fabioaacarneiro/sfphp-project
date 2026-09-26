<?php

namespace SfphpProject\src;

/**
 * Facts about the framework itself.
 */
final class Sfphp
{
    /**
     * The framework's version.
     *
     * Written here, inside src/, because src/ is what `./sfphp upgrade`
     * replaces: after an upgrade this is the new version. It used to be read
     * from the root package's composer.json, which in a project made with
     * create-project is the application's — upgrade leaves it alone, so the
     * console kept reporting the version the project started from, and a
     * project that renamed itself reported "dev". The test suite checks that
     * it matches composer.json, so a release cannot bump one and forget the
     * other.
     */
    public const VERSION = '0.34.0';
}
