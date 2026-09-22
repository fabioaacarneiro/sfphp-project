<?php

namespace SfphpProject\src\Mail;

/**
 * Keeps messages in memory instead of sending them.
 *
 * For tests. Sending is the one thing an application cannot assert on after the
 * fact, so this makes the messages a value a test can read.
 */
final class ArrayDriver implements Mailer
{
    /** @var list<Message> */
    private array $messages = [];

    /**
     * Keep the message.
     *
     * @param Message $message The message
     * @return void
     */
    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Every message sent so far.
     *
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The most recent message, or null when nothing was sent.
     *
     * @return Message|null
     */
    public function last(): ?Message
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }

    /**
     * Forget every message.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->messages = [];
    }
}
