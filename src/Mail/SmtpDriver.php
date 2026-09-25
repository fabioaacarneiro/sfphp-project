<?php

namespace SfphpProject\src\Mail;

/**
 * Speaks SMTP to a mail server.
 *
 * This is the only transport the framework needs, and that is not a compromise:
 * every service anyone contracts — SES, Postmark, SendGrid, Mailgun, Resend,
 * Brevo — accepts SMTP. Changing provider is four values in the environment,
 * not a new driver. Writing an HTTP client per vendor would be more code for
 * less reach.
 *
 *     MAIL_HOST=smtp.provider.com
 *     MAIL_PORT=587
 *     MAIL_USERNAME=...
 *     MAIL_PASSWORD=...
 *     MAIL_ENCRYPTION=tls
 *
 * Both ways of getting to TLS are supported, because providers are split
 * roughly evenly between them and supporting one would break the promise that
 * configuration is all it takes: `tls` opens a plain connection on 587 and
 * upgrades it with STARTTLS, `ssl` opens an encrypted connection on 465. So are
 * both common authentication mechanisms, LOGIN and PLAIN.
 *
 * What this does not do is make mail arrive. Delivery depends on SPF, DKIM and
 * DMARC records on the sending domain and on the sender being verified at the
 * provider — DNS and a control panel, which no library can do on an
 * application's behalf.
 */
final class SmtpDriver implements Mailer
{
    /** @var resource|null */
    private $socket = null;

    /** @var list<string> Extensions the server announced in its EHLO reply. */
    private array $extensions = [];

    /**
     * Create the driver.
     *
     * @param string $host The server's host name
     * @param int $port The port
     * @param string|null $username The user name, or null for a server that wants no login
     * @param string|null $password The password
     * @param string $encryption "tls" for STARTTLS, "ssl" for implicit TLS, "none" for neither
     * @param int $timeout Seconds to wait for a connection and for each reply
     * @param string|null $ehloDomain The name announced in EHLO, or null to derive one
     * @param bool $verifyPeer Whether the server's certificate must be valid
     * @param bool $allowPlaintextAuth Whether a password may be sent over an unencrypted connection to another machine
     * @throws \InvalidArgumentException When the encryption is not tls, ssl or none
     */
    public function __construct(
        private string $host = 'localhost',
        private int $port = 25,
        private ?string $username = null,
        private ?string $password = null,
        private string $encryption = 'none',
        private int $timeout = 30,
        private ?string $ehloDomain = null,
        private bool $verifyPeer = true,
        private bool $allowPlaintextAuth = false
    ) {
        /*
         * Read case-insensitively, with the spellings people use. "TLS" or
         * "starttls" used to fall through every comparison and mean "none",
         * so the password went out in the clear while the configuration said
         * otherwise. A value that is none of these is refused.
         */
        $normalised = strtolower(trim($encryption));

        $this->encryption = match ($normalised) {
            'tls', 'starttls' => 'tls',
            'ssl', 'smtps', 'implicit' => 'ssl',
            'none', '', 'null', 'false' => 'none',
            default => throw new \InvalidArgumentException(sprintf(
                'MAIL_ENCRYPTION "%s" is not one of tls (STARTTLS, usually port 587), ssl (usually 465) or none.',
                $encryption
            )),
        };
    }

    /**
     * Deliver one message.
     *
     * The connection is opened and closed per message. A pooled connection
     * would be faster for a batch and would also mean a socket kept open across
     * a request boundary, which is the kind of state a persistent runtime turns
     * into a bug. Sending in bulk belongs on the queue instead.
     *
     * @param Message $message The message
     * @return void
     * @throws MailException When the server refuses the message at any step
     */
    public function send(Message $message): void
    {
        $sender = $message->sender();

        if ($sender === null) {
            throw new MailException('The message has no sender. Set MAIL_FROM_ADDRESS or call from().');
        }

        $body = $message->toString();

        $this->connect();

        try {
            $this->greet();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $sender['address'] . '>', [250]);

            foreach ($message->recipients() as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', [354]);
            $this->write($this->stuffDots($body) . "\r\n.\r\n");
            $this->expect([250]);

            /*
             * QUIT failing after the server accepted the message is not a
             * failed delivery — the message is already its responsibility.
             */
            try {
                $this->command('QUIT', [221]);
            } catch (MailException) {
            }
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Open the connection.
     *
     * @return void
     * @throws MailException When the server cannot be reached
     */
    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);

        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            /*
             * A refused certificate on an ssl:// connection arrives as
             * "error 0" with no message, which read like the server being
             * down.
             */
            $reason = $errorMessage !== ''
                ? $errorMessage
                : ($this->encryption === 'ssl'
                    ? 'the TLS handshake failed — check the server certificate, and that the port speaks implicit TLS'
                    : 'error ' . $errorCode);

            throw new MailException(sprintf('Cannot reach the mail server at %s:%d (%s).', $this->host, $this->port, $reason));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->expect([220]);
    }

    /**
     * Introduce ourselves, upgrading to TLS when asked to.
     *
     * @return void
     * @throws MailException When the server refuses the greeting or the upgrade
     */
    private function greet(): void
    {
        $this->ehlo();

        if ($this->encryption !== 'tls') {
            return;
        }

        $this->command('STARTTLS', [220]);

        $enabled = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($enabled !== true) {
            throw new MailException('The TLS handshake with ' . $this->host . ' failed.');
        }

        /*
         * Again, because everything the server said before the upgrade was said
         * on a connection nobody could vouch for. Its capabilities after
         * STARTTLS are the ones that count, and they routinely differ — several
         * providers only advertise AUTH once the connection is encrypted.
         */
        $this->ehlo();
    }

    /**
     * Send EHLO and remember what the server supports.
     *
     * @return void
     * @throws MailException When the server refuses both EHLO and HELO
     */
    private function ehlo(): void
    {
        $domain = $this->ehloDomain ?? $this->localDomain();

        try {
            $reply = $this->command('EHLO ' . $domain, [250]);
        } catch (MailException) {
            // A server too old for EHLO cannot do STARTTLS or AUTH either.
            $this->command('HELO ' . $domain, [250]);
            $this->extensions = [];

            return;
        }

        $this->extensions = array_map(
            static fn (string $line): string => strtoupper(trim(substr($line, 4))),
            array_slice(explode("\r\n", trim($reply)), 1)
        );
    }

    /**
     * Log in, when there is anything to log in with.
     *
     * @return void
     * @throws MailException When the server rejects the credentials
     */
    private function authenticate(): void
    {
        if ($this->username === null || $this->username === '') {
            return;
        }

        /*
         * A password sent without encryption to another machine can be read
         * by anything on the path. Refused unless the server is this machine
         * — a local relay, a development catcher — or it was allowed on
         * purpose with MAIL_ALLOW_PLAINTEXT_AUTH.
         */
        if ($this->encryption === 'none' && !$this->allowPlaintextAuth && !self::isLoopback($this->host)) {
            throw new MailException(sprintf(
                'Refusing to send the SMTP password to %s without encryption. Set MAIL_ENCRYPTION to tls or ssl.',
                $this->host
            ));
        }

        $mechanisms = '';

        foreach ($this->extensions as $extension) {
            if (str_starts_with($extension, 'AUTH')) {
                $mechanisms = $extension;
                break;
            }
        }

        $password = (string) $this->password;

        /*
         * PLAIN is one round trip and LOGIN is three, so PLAIN goes first when
         * the server offers it. A server that announced neither still gets
         * LOGIN attempted: some announce nothing and accept it anyway, and a
         * refusal there is a clearer error than silently sending unauthenticated.
         */
        if (str_contains($mechanisms, 'PLAIN')) {
            $this->command(
                'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $password),
                [235]
            );

            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($password), [235]);
    }

    /**
     * Send a command and check the reply code.
     *
     * @param string $command The command, without its line ending
     * @param list<int> $expected The reply codes that mean success
     * @return string The server's full reply
     * @throws MailException When the reply is not one of the expected codes
     */
    private function command(string $command, array $expected): string
    {
        $this->write($command . "\r\n");

        return $this->expect($expected, $command);
    }

    /**
     * Read a reply and check its code.
     *
     * @param list<int> $expected The reply codes that mean success
     * @param string|null $command The command being answered, for the message
     * @return string The full reply
     * @throws MailException When the reply is not one of the expected codes
     */
    private function expect(array $expected, ?string $command = null): string
    {
        $reply = $this->read();
        $code = (int) substr($reply, 0, 3);

        if (in_array($code, $expected, true)) {
            return $reply;
        }

        /*
         * The server's own words are kept. "535 5.7.8 Username and Password not
         * accepted" tells whoever is configuring this what to change; "the mail
         * server refused" does not.
         */
        throw new MailException(sprintf(
            'The mail server refused %s: %s',
            $command === null ? 'the connection' : rtrim($this->redact($command)),
            trim($reply)
        ));
    }

    /**
     * Read one reply, following continuation lines.
     *
     * @return string The reply
     * @throws MailException When the connection ends or stalls
     */
    private function read(): string
    {
        $reply = '';

        while (true) {
            $line = fgets($this->socket, 1024);

            if ($line === false) {
                $info = stream_get_meta_data($this->socket);

                throw new MailException($info['timed_out']
                    ? 'The mail server stopped responding after ' . $this->timeout . ' seconds.'
                    : 'The mail server closed the connection.');
            }

            $reply .= $line;

            // A space in the fourth position ends a reply; a hyphen continues it.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return $reply;
    }

    /**
     * Write to the socket.
     *
     * @param string $data The bytes
     * @return void
     * @throws MailException When the write fails
     */
    private function write(string $data): void
    {
        if (!is_resource($this->socket) || @fwrite($this->socket, $data) === false) {
            throw new MailException('Writing to the mail server failed.');
        }
    }

    /**
     * Close the connection.
     *
     * @return void
     */
    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->extensions = [];
    }

    /**
     * Double a leading dot on every line.
     *
     * A line consisting of a single dot ends the DATA command, so a body line
     * that starts with one has to be escaped or the message is truncated there
     * — and a message body ending early is the kind of bug that only shows up
     * for the one customer whose text happened to start a line with a dot.
     *
     * @param string $body The message
     * @return string The message, safe to put inside DATA
     */
    private function stuffDots(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /**
     * Whether a host is this machine.
     */
    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_starts_with($host, '127.');
    }

    /**
     * Hide a credential that would otherwise reach an exception message.
     *
     * @param string $command The command being reported
     * @return string The command, with any credential replaced
     */
    private function redact(string $command): string
    {
        /*
         * The protocol's own verbs are not credentials: "STARTTLS" matched
         * the base64 test, and a server refusing TLS was reported as refusing
         * "the authentication step".
         */
        if (in_array(strtoupper($command), ['STARTTLS', 'QUIT', 'DATA', 'RSET', 'NOOP'], true)) {
            return $command;
        }

        if (str_starts_with($command, 'AUTH ') || preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $command) === 1) {
            return 'the authentication step';
        }

        return $command;
    }

    /**
     * The name to announce in EHLO.
     *
     * A server may reject a bare "localhost", so the machine's own name is used
     * when it looks like a host name at all.
     *
     * @return string The domain
     */
    private function localDomain(): string
    {
        $name = gethostname();

        return is_string($name) && $name !== '' && str_contains($name, '.') ? $name : '[127.0.0.1]';
    }
}
