<?php

namespace SfphpProject\src\Mail;

/**
 * Somewhere a message can be handed over for delivery.
 *
 * One method, like the cache and log contracts. A driver's whole job is to get
 * a message to a mail server; the sender defaults, the queueing and the
 * convenience live in MailManager, so a new transport is one short class.
 */
interface Mailer
{
    /**
     * Deliver one message.
     *
     * @param Message $message The message
     * @return void
     * @throws MailException When the message cannot be handed over
     */
    public function send(Message $message): void;
}
