<?php

namespace SfphpProject\src\View;

use Stringable;

/**
 * Markup that has already been made safe.
 *
 * A component returns one of these rather than a string, and the difference is
 * the whole point: `string` means "text of unknown origin, escape it", and
 * `Sfht` means "markup this framework produced, print it as it is". So `{{ }}`
 * can decide for itself, and composing components stops needing the raw-output
 * form:
 *
 *     {{ Card('Olá', $corpo) }}     the card renders
 *     {{ $corpo }}                   the text is escaped
 *
 * Both in the same expression position, with the right thing happening to each.
 * The alternative — returning a string and writing {!! Card(...) !!} — asks the
 * author to remember which values are trusted, which is the moment somebody
 * eventually writes {!! $comentario !!} and ships a cross-site scripting hole.
 *
 * > **Wrapping a string in this bypasses escaping**, which is exactly what it
 * > is for and exactly why `new Sfht($whatever)` deserves a second look. The
 * > compiler builds these from markup the author wrote; anything built from a
 * > request is a decision to trust it.
 */
final class Sfht implements Stringable
{
    /**
     * @param string $html Markup that is already safe to print
     */
    public function __construct(private string $html)
    {
    }

    /**
     * The markup.
     *
     * Implementing Stringable rather than only being one keeps it usable
     * anywhere a string is: echo, concatenation, and a Response body.
     *
     * @return string The markup
     */
    public function __toString(): string
    {
        return $this->html;
    }
}
