<?php

namespace SfphpProject\src\Debug;

use Closure;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * Turns a value into something a person can read.
 *
 * The job splits in two, and keeping them apart is what makes this testable:
 * `describe()` walks a value and returns a plain tree, and the renderers turn a
 * tree into a screen or into terminal text. Nothing here prints.
 *
 * What a dump has to survive is the reason most of this code exists. A value
 * can point at itself, so references are tracked and a second visit is reported
 * rather than followed. A value can be enormous, so depth and string length are
 * capped and what was cut is stated — a truncated dump that admits it beats a
 * browser that stops responding. And an object can throw from a getter, so
 * nothing but declared properties is read.
 */
final class Dumper
{
    /** How deep to walk before saying so. */
    public const MAX_DEPTH = 12;

    /** How much of a string to show before saying how much was left. */
    public const MAX_STRING = 2048;

    /** How many entries of one array or object to show. */
    public const MAX_ENTRIES = 200;

    /**
     * Describe a value as a tree.
     *
     * @param mixed $value The value
     * @param int $depth How deep this value already is
     * @param array<string, true> $seen Object hashes already visited on this branch
     * @return array<string, mixed> The description
     */
    public static function describe(mixed $value, int $depth = 0, array $seen = []): array
    {
        return match (true) {
            $value === null => ['type' => 'null', 'value' => 'null'],
            is_bool($value) => ['type' => 'bool', 'value' => $value ? 'true' : 'false'],
            is_int($value) => ['type' => 'int', 'value' => (string) $value],
            is_float($value) => ['type' => 'float', 'value' => self::float($value)],
            is_string($value) => self::describeString($value),
            is_array($value) => self::describeArray($value, $depth, $seen),
            $value instanceof UnitEnum => self::describeEnum($value),
            $value instanceof Closure => self::describeClosure($value),
            is_object($value) => self::describeObject($value, $depth, $seen),
            is_resource($value) => [
                'type' => 'resource',
                'value' => get_resource_type($value),
            ],
            default => ['type' => 'unknown', 'value' => gettype($value)],
        };
    }

    /**
     * Describe a string, with its length and whether it was cut.
     *
     * @param string $value The string
     * @return array<string, mixed> The description
     */
    private static function describeString(string $value): array
    {
        /*
         * mb_strlen where it exists: a dump saying a name is 6 characters when
         * it is 6 letters and 8 bytes is the kind of small lie that sends
         * somebody looking for a bug in the wrong place.
         */
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $cut = $length > self::MAX_STRING;

        if ($cut) {
            $value = function_exists('mb_substr')
                ? mb_substr($value, 0, self::MAX_STRING, 'UTF-8')
                : substr($value, 0, self::MAX_STRING);
        }

        return [
            'type' => 'string',
            'value' => $value,
            'length' => $length,
            'truncated' => $cut,
            // A string that is not valid UTF-8 cannot go into an HTML page as
            // it is, and pretending otherwise produces a blank screen.
            'binary' => preg_match('//u', $value) !== 1,
        ];
    }

    /**
     * Describe a float so it reads as a float.
     *
     * @param float $value The number
     * @return string The rendering
     */
    private static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        $rendered = var_export($value, true);

        // var_export gives "1.0" for a whole float, which is what distinguishes
        // it from the integer 1 on screen. Keep that.
        return $rendered;
    }

    /**
     * Describe an array and its entries.
     *
     * @param array<array-key, mixed> $value The array
     * @param int $depth How deep it is
     * @param array<string, true> $seen Hashes already visited
     * @return array<string, mixed> The description
     */
    private static function describeArray(array $value, int $depth, array $seen): array
    {
        $count = count($value);
        $node = ['type' => 'array', 'count' => $count, 'children' => [], 'truncated' => false];

        if ($depth >= self::MAX_DEPTH) {
            $node['deep'] = true;

            return $node;
        }

        $shown = 0;

        foreach ($value as $key => $entry) {
            if ($shown >= self::MAX_ENTRIES) {
                $node['truncated'] = true;
                break;
            }

            $node['children'][] = [
                'key' => is_int($key) ? (string) $key : $key,
                'keyType' => is_int($key) ? 'int' : 'string',
                'value' => self::describe($entry, $depth + 1, $seen),
            ];
            $shown++;
        }

        return $node;
    }

    /**
     * Describe an enum case.
     *
     * @param UnitEnum $value The case
     * @return array<string, mixed> The description
     */
    private static function describeEnum(UnitEnum $value): array
    {
        return [
            'type' => 'enum',
            'class' => $value::class,
            'value' => $value->name,
            'backing' => property_exists($value, 'value') ? (string) $value->value : null,
        ];
    }

    /**
     * Describe a closure, including where it was written.
     *
     * @param Closure $value The closure
     * @return array<string, mixed> The description
     */
    private static function describeClosure(Closure $value): array
    {
        try {
            $reflection = new ReflectionFunction($value);
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();
        } catch (Throwable) {
            $file = null;
            $line = null;
        }

        return [
            'type' => 'closure',
            'class' => 'Closure',
            'file' => $file === false ? null : $file,
            'line' => $line === false ? null : $line,
        ];
    }

    /**
     * Describe an object and its declared properties.
     *
     * @param object $value The object
     * @param int $depth How deep it is
     * @param array<string, true> $seen Hashes already visited
     * @return array<string, mixed> The description
     */
    private static function describeObject(object $value, int $depth, array $seen): array
    {
        $hash = spl_object_hash($value);
        $node = ['type' => 'object', 'class' => $value::class, 'children' => [], 'truncated' => false];

        if (isset($seen[$hash])) {
            // Following this would not end. Say so instead.
            $node['circular'] = true;

            return $node;
        }

        if ($depth >= self::MAX_DEPTH) {
            $node['deep'] = true;

            return $node;
        }

        $seen[$hash] = true;
        $properties = (new ReflectionObject($value))->getProperties();
        $shown = 0;

        foreach ($properties as $property) {
            if ($shown >= self::MAX_ENTRIES) {
                $node['truncated'] = true;
                break;
            }

            $node['children'][] = [
                'key' => $property->getName(),
                'keyType' => 'property',
                'visibility' => self::visibility($property),
                'value' => self::propertyValue($property, $value, $depth, $seen),
            ];
            $shown++;
        }

        return $node;
    }

    /**
     * Read one property, without letting it break the dump.
     *
     * @param ReflectionProperty $property The property
     * @param object $object The object it belongs to
     * @param int $depth How deep the object is
     * @param array<string, true> $seen Hashes already visited
     * @return array<string, mixed> The described value
     */
    private static function propertyValue(
        ReflectionProperty $property,
        object $object,
        int $depth,
        array $seen
    ): array {
        /*
         * A typed property that was never assigned has no value at all, and
         * reading it throws. That is a state worth seeing rather than an error
         * worth propagating: "uninitialised" is often the answer being looked
         * for.
         */
        if (!$property->isInitialized($object)) {
            return ['type' => 'uninitialised', 'value' => 'uninitialised'];
        }

        try {
            return self::describe($property->getValue($object), $depth + 1, $seen);
        } catch (Throwable $throwable) {
            return ['type' => 'unreadable', 'value' => $throwable->getMessage()];
        }
    }

    /**
     * How a property is declared.
     *
     * @param ReflectionProperty $property The property
     * @return string "public", "protected" or "private", with "static" appended
     */
    private static function visibility(ReflectionProperty $property): string
    {
        $visibility = match (true) {
            $property->isPrivate() => 'private',
            $property->isProtected() => 'protected',
            default => 'public',
        };

        return $property->isStatic() ? $visibility . ' static' : $visibility;
    }

    /**
     * Where a dump was called from.
     *
     * @param int $skip How many frames of this file to step over
     * @return array{file: string, line: int}|null The caller, or null when unknown
     */
    public static function caller(int $skip = 0): ?array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $skip + 6);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            // Skip this file and the helpers, so the answer is the line the
            // person wrote rather than the line that implements dd().
            if (str_contains($file, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Debug')
                || str_ends_with($file, DIRECTORY_SEPARATOR . 'helpers.php')) {
                continue;
            }

            return ['file' => $file, 'line' => (int) ($frame['line'] ?? 0)];
        }

        return null;
    }
}
