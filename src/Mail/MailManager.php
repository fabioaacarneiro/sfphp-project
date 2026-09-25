<?php

namespace SfphpProject\src\Mail;

/**
 * The mailer an application talks to.
 *
 *     mailer()->send(
 *         (new Message())
 *             ->to($user->email)
 *             ->subject(__('app.order_confirmed'))
 *             ->text($plain)
 *             ->html($html)
 *     );
 *
 * It adds three things to a driver: the sender every message gets unless it
 * says otherwise, an "always send here instead" switch for a staging
 * environment, and a record of what was sent.
 *
 * What it does not add is any idea of why. There is no welcome e-mail and no
 * password reset here, because those are decisions about what an application is
 * for. The framework knows how to put bytes on a mail server; the rest belongs
 * to whoever is writing the product.
 */
final class MailManager
{
    private Mailer $driver;

    /** @var array{address: string, name: string}|null */
    private ?array $from = null;

    private ?string $alwaysTo = null;

    /**
     * Create the manager.
     *
     * @param Mailer|null $driver Where messages go, or null for the log driver
     * @param string|null $fromAddress The default sender address
     * @param string $fromName The default sender name
     */
    public function __construct(
        ?Mailer $driver = null,
        ?string $fromAddress = null,
        string $fromName = ''
    ) {
        /*
         * The log driver by default, not mail(). A framework whose out-of-the-
         * box behaviour is to hand messages to an unconfigured local MTA sends
         * nothing and says nothing; writing them to the log at least tells the
         * developer what would have gone out, and cannot reach a real person by
         * accident.
         */
        $this->driver = $driver ?? new LogDriver();

        if ($fromAddress !== null && $fromAddress !== '') {
            $this->from = ['address' => $fromAddress, 'name' => $fromName];
        }
    }

    /**
     * Send messages somewhere else from now on.
     *
     * @param Mailer $driver The destination
     * @return static This manager
     */
    public function driver(Mailer $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * The driver messages currently go to.
     *
     * @return Mailer The driver
     */
    public function using(): Mailer
    {
        return $this->driver;
    }

    /**
     * Set the sender used when a message names none.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return static This manager
     */
    public function from(string $address, string $name = ''): static
    {
        $this->from = ['address' => $address, 'name' => $name];

        return $this;
    }

    /**
     * Redirect every message to one address.
     *
     * For a staging environment working from a copy of production data, where
     * the addresses in the database belong to real people. The original
     * recipients are kept in a header so the message still says who it was for.
     *
     * @param string|null $address The address, or null to stop redirecting
     * @return static This manager
     */
    public function alwaysTo(?string $address): static
    {
        $this->alwaysTo = $address;

        return $this;
    }

    /**
     * Send a message.
     *
     * @param Message $message The message
     * @return void
     * @throws MailException When the driver cannot deliver it
     */
    public function send(Message $message): void
    {
        /*
         * The default sender is set on a copy. Setting it on the caller's
         * message changed an object the caller still holds, so reusing one
         * Message with two managers sent both with the first one's sender.
         */
        if ($message->sender() === null && $this->from !== null) {
            $message = (clone $message)->from($this->from['address'], $this->from['name']);
        }

        if ($this->alwaysTo !== null) {
            $message = $message->redirectedTo((string) $this->alwaysTo);
        }

        $this->driver->send($message);
    }
}
