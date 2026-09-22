<?php

namespace SfphpProject\src\Mail;

/**
 * Hands the message to PHP's own mail() function.
 *
 * > **This is for a development machine, not for production.** mail() shells
 * > out to whatever local MTA exists, which on most servers means the message
 * > leaves with no SPF or DKIM signature and lands in spam — when it leaves at
 * > all. There is also no way to learn that it bounced. Use SmtpDriver with a
 * > contracted service for anything a person is meant to receive.
 *
 * It is here because it is the one transport that needs no configuration, which
 * makes it a reasonable thing to reach for while writing the rest of a feature.
 */
final class MailDriver implements Mailer
{
    /**
     * Deliver one message through mail().
     *
     * @param Message $message The message
     * @return void
     * @throws MailException When mail() reports failure
     */
    public function send(Message $message): void
    {
        $rendered = $message->toString();
        $separator = strpos($rendered, "\r\n\r\n");

        if ($separator === false) {
            throw new MailException('The message could not be split into headers and body.');
        }

        /*
         * mail() takes the recipients and subject as arguments and every other
         * header separately, so the rendered message is split rather than
         * rebuilt: the encoding work in Message stays the single place that
         * knows how a header is written.
         */
        $headers = explode("\r\n", substr($rendered, 0, $separator));
        $body = substr($rendered, $separator + 4);

        $to = [];
        $subject = '';
        $rest = [];

        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $name = strtolower(trim($name));

            if ($name === 'to') {
                $to[] = trim($value);
            } elseif ($name === 'subject') {
                $subject = trim($value);
            } else {
                $rest[] = $header;
            }
        }

        /*
         * Bcc recipients are in the message's recipient list and in no header,
         * so mail() would never see them. They are added here as extra "To"
         * arguments, which is the only way this transport can deliver a blind
         * copy at all.
         */
        $named = [];

        foreach ($message->toAddresses() as $address) {
            $named[] = $address['address'];
        }

        $blind = array_values(array_diff($message->recipients(), $named));

        if ($to === [] && $blind === []) {
            throw new MailException('The message has no recipient.');
        }

        $sent = @mail(
            implode(', ', array_filter([...$to, ...$blind])),
            $subject,
            $body,
            implode("\r\n", $rest)
        );

        if ($sent === false) {
            throw new MailException('mail() refused the message. Check the local MTA.');
        }
    }
}
