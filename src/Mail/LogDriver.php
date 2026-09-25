<?php

namespace SfphpProject\src\Mail;

use SfphpProject\src\Log\LogManager;

/**
 * Writes messages to the log instead of sending them.
 *
 * For a development machine with no mail server, and for a staging environment
 * that must not be able to e-mail a real customer by accident. The body is
 * recorded so a reset link can be followed from the log.
 */
final class LogDriver implements Mailer
{
    private LogManager $log;

    /**
     * Create the driver.
     *
     * @param LogManager|null $log The logger, or null for the shared one
     */
    public function __construct(?LogManager $log = null)
    {
        $this->log = $log ?? logger();
    }

    /**
     * Record the message.
     *
     * @param Message $message The message
     * @return void
     */
    public function send(Message $message): void
    {
        /*
         * Outside production the body is kept, so a reset link can be followed
         * from the log. In production it is not: this driver there means mail
         * is not being sent, and bodies full of reset tokens and magic links
         * would land in a log aggregator many more people can read.
         */
        $production = \SfphpProject\src\Config::get('APP_ENV') === 'production';

        $this->log->{$production ? 'warning' : 'info'}('mail not sent, log driver', [
            'to' => $message->recipients(),
            'subject' => $message->subjectLine(),
            'body' => $production ? '[not logged in production]' : ($message->textBody() ?? $message->htmlBody()),
        ]);
    }
}
