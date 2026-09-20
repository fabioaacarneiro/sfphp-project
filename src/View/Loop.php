<?php

namespace SfphpProject\src\View;

use InvalidArgumentException;
use Traversable;

/**
 * The $loop variable exposed inside @foreach.
 */
final class Loop
{
    /** @var array<int|string, mixed> */
    public readonly array $items;
    public readonly int $count;
    public readonly int $depth;
    public int $index = -1;
    public int $iteration = 0;
    public int $remaining;
    public bool $first = false;
    public bool $last = false;
    public bool $even = false;
    public bool $odd = false;

    /**
     * @param iterable<int|string, mixed>|null $items The collection being iterated
     * @param Loop|null $parent The enclosing loop, when nested
     * @throws InvalidArgumentException If the value cannot be iterated
     */
    public function __construct(mixed $items, public readonly ?Loop $parent = null)
    {
        $this->items = match (true) {
            $items === null => [],
            is_array($items) => $items,
            $items instanceof Traversable => iterator_to_array($items, true),
            default => throw new InvalidArgumentException(
                '@foreach expects an array or Traversable, ' . get_debug_type($items) . ' given.'
            ),
        };

        $this->count = count($this->items);
        $this->remaining = $this->count;
        $this->depth = ($parent?->depth ?? 0) + 1;
    }

    /**
     * Advance to the next item and refresh the flags.
     */
    public function tick(): self
    {
        $this->index++;
        $this->iteration++;
        $this->remaining = $this->count - $this->iteration;
        $this->first = $this->index === 0;
        $this->last = $this->iteration === $this->count;
        $this->even = $this->index % 2 === 0;
        $this->odd = !$this->even;

        return $this;
    }
}
