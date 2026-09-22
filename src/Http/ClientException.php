<?php

namespace SfphpProject\src\Http;

use RuntimeException;

/**
 * A request that produced no response at all.
 *
 * The distinction this draws is the useful one. A 404 and a 500 are answers —
 * the server was reached, it understood, and it said no — so they come back as
 * a ClientResponse to be inspected. A refused connection, a name that does not
 * resolve, a timeout or a certificate that failed to verify are not answers,
 * and there is nothing to return.
 */
final class ClientException extends RuntimeException
{
}
