<?php

namespace SfphpProject\src\Mail;

use RuntimeException;

/**
 * A message could not be handed to a mail server.
 *
 * Distinct from InvalidArgumentException, which Message throws for something
 * wrong with the message itself. This one means the message was fine and the
 * delivery was not — a refused login, a server that closed the connection, a
 * recipient the server would not accept.
 */
final class MailException extends RuntimeException
{
}
