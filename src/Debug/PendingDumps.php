<?php

namespace SfphpProject\src\Debug;

/**
 * The dumps made while a request is being handled, waiting for its response.
 *
 * dump() used to echo straight away. The action had not returned yet, so the
 * dump was the first byte of output; the Emitter then found the headers
 * already sent and refused to emit, and the browser got the dump and nothing
 * else — no status, no headers, no page. The dumps wait here instead, and the
 * Emitter puts them into the page it sends.
 */
final class PendingDumps
{
    /** @var list<string> */
    private static array $fragments = [];

    /**
     * Keep a rendered dump for the response.
     *
     * @param string $fragment The HTML
     * @return void
     */
    public static function add(string $fragment): void
    {
        self::$fragments[] = $fragment;
    }

    /**
     * Take the dumps that are waiting, and forget them.
     *
     * @return list<string> The fragments, in the order they were made
     */
    public static function take(): array
    {
        $fragments = self::$fragments;
        self::$fragments = [];

        return $fragments;
    }

    /**
     * Put the dumps into an HTML page: before </body>, or at the end.
     *
     * @param string $html The page
     * @param list<string> $fragments The dumps
     * @return string The page with the dumps
     */
    public static function inject(string $html, array $fragments): string
    {
        $dumps = implode('', $fragments);
        $at = strripos($html, '</body>');

        return $at === false ? $html . $dumps : substr($html, 0, $at) . $dumps . substr($html, $at);
    }
}
