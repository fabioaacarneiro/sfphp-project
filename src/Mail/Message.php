<?php

namespace SfphpProject\src\Mail;

use InvalidArgumentException;

/**
 * An e-mail, built up and then handed to a driver.
 *
 *     (new Message())
 *         ->to('ana@example.com', 'Ana')
 *         ->subject('Seu pedido')
 *         ->text('Obrigado pela compra.')
 *         ->html('<p>Obrigado pela compra.</p>');
 *
 * It knows nothing about why it is being sent. A framework that shipped a
 * "welcome e-mail" would be deciding what applications are for; this decides
 * only how bytes reach a mail server.
 *
 * Two things here are not conveniences and are worth reading before changing.
 *
 * **Header injection is refused, not escaped.** A newline in a name, an address
 * or a subject lets whoever supplied it append headers of their own — a Bcc to
 * an address you never intended is the classic one, and the value usually comes
 * from a form. Anything carrying CR or LF in a header is rejected outright.
 *
 * **Everything is UTF-8 all the way out.** A subject with an accent is encoded
 * per RFC 2047 and a body per RFC 2045, because a mail header is ASCII on the
 * wire and a message that arrives as "Pedido confirmado" with mojibake is a
 * message that did not arrive.
 */
final class Message
{
    /** @var array{address: string, name: string}|null */
    private ?array $from = null;

    /** @var list<array{address: string, name: string}> */
    private array $to = [];

    /** @var list<array{address: string, name: string}> */
    private array $cc = [];

    /** @var list<array{address: string, name: string}> */
    private array $bcc = [];

    /** @var list<array{address: string, name: string}> */
    private array $replyTo = [];

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var list<array{name: string, content: string, type: string}> */
    private array $attachments = [];

    /** @var array<string, string> */
    private array $headers = [];

    /**
     * Set the sender.
     *
     * Usually left alone: the manager fills it from MAIL_FROM_ADDRESS so every
     * message in an application comes from the same place.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return self
     */
    public function from(string $address, string $name = ''): self
    {
        $this->from = $this->address($address, $name);

        return $this;
    }

    /**
     * Add a recipient.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return self
     */
    public function to(string $address, string $name = ''): self
    {
        $this->to[] = $this->address($address, $name);

        return $this;
    }

    /**
     * Add a carbon copy recipient.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return self
     */
    public function cc(string $address, string $name = ''): self
    {
        $this->cc[] = $this->address($address, $name);

        return $this;
    }

    /**
     * Add a blind carbon copy recipient.
     *
     * The address is sent to the server and never written into a header, which
     * is what makes it blind.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return self
     */
    public function bcc(string $address, string $name = ''): self
    {
        $this->bcc[] = $this->address($address, $name);

        return $this;
    }

    /**
     * Add a reply-to address.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return self
     */
    public function replyTo(string $address, string $name = ''): self
    {
        $this->replyTo[] = $this->address($address, $name);

        return $this;
    }

    /**
     * Set the subject.
     *
     * @param string $subject The subject
     * @return self
     * @throws InvalidArgumentException When it carries a line break
     */
    public function subject(string $subject): self
    {
        $this->subject = $this->rejectLineBreaks($subject, 'subject');

        return $this;
    }

    /**
     * Set the plain text body.
     *
     * @param string $text The body
     * @return self
     */
    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Set the HTML body.
     *
     * When both are set the message goes out as multipart/alternative, and the
     * reader's client picks. Sending HTML with no text alternative is what gets
     * a message scored as spam, so text() is worth filling in.
     *
     * @param string $html The body
     * @return self
     */
    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    /**
     * Attach a file already in memory.
     *
     * @param string $name The file name the recipient sees
     * @param string $content The raw bytes
     * @param string $type The media type
     * @return self
     * @throws InvalidArgumentException When the name carries a line break
     */
    public function attach(string $name, string $content, string $type = 'application/octet-stream'): self
    {
        $this->attachments[] = [
            'name' => $this->rejectLineBreaks($name, 'attachment name'),
            'content' => $content,
            'type' => $this->rejectLineBreaks($type, 'attachment type'),
        ];

        return $this;
    }

    /**
     * Attach a file from disk.
     *
     * @param string $path The file path
     * @param string|null $name The name the recipient sees, or null for the file's own
     * @param string $type The media type
     * @return self
     * @throws InvalidArgumentException When the file cannot be read
     */
    public function attachFile(string $path, ?string $name = null, string $type = 'application/octet-stream'): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Cannot read the attachment ' . $path . '.');
        }

        return $this->attach($name ?? basename($path), (string) file_get_contents($path), $type);
    }

    /**
     * Set an extra header.
     *
     * @param string $name The header name
     * @param string $value The header value
     * @return self
     * @throws InvalidArgumentException When either carries a line break
     */
    public function header(string $name, string $value): self
    {
        $name = $this->rejectLineBreaks($name, 'header name');
        $this->headers[$name] = $this->rejectLineBreaks($value, 'header value');

        return $this;
    }

    /**
     * A copy of the message addressed to someone else.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return self The copy
     */
    public function redirectedTo(string $address, string $name = ''): self
    {
        /*
         * A copy that differs only in who receives it: the attachments, the
         * Reply-To, the headers and both bodies travel with it. The original
         * recipients are kept in X-Intended-For. Rebuilding the message from
         * its subject and bodies, which is how staging redirection used to
         * work, dropped the rest — so staging never sent what production
         * would.
         */
        $copy = clone $this;
        $copy->headers['X-Intended-For'] = implode(', ', $this->recipients());
        $copy->to = [];
        $copy->cc = [];
        $copy->bcc = [];

        return $copy->to($address, $name);
    }

    /**
     * The sender, or null when the manager has not filled it in yet.
     *
     * @return array{address: string, name: string}|null
     */
    public function sender(): ?array
    {
        return $this->from;
    }

    /**
     * Every address the message must be delivered to, Bcc included.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $addresses = [];

        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $recipient) {
            $addresses[] = $recipient['address'];
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The subject, as it was given.
     *
     * @return string
     */
    public function subjectLine(): string
    {
        return $this->subject;
    }

    /**
     * The plain text body, if any.
     *
     * @return string|null
     */
    public function textBody(): ?string
    {
        return $this->text;
    }

    /**
     * The HTML body, if any.
     *
     * @return string|null
     */
    public function htmlBody(): ?string
    {
        return $this->html;
    }

    /**
     * The "To" addresses, for a driver that needs them apart from the rest.
     *
     * @return list<array{address: string, name: string}>
     */
    public function toAddresses(): array
    {
        return $this->to;
    }

    /**
     * The Cc recipients, with their names.
     *
     * @return list<array{address: string, name: string}>
     */
    public function ccAddresses(): array
    {
        return $this->cc;
    }

    /**
     * Render the message as the headers and body an SMTP DATA command carries.
     *
     * @return string The full message
     * @throws InvalidArgumentException When there is no sender or no recipient
     */
    public function toString(): string
    {
        if ($this->from === null) {
            throw new InvalidArgumentException('The message has no sender. Set MAIL_FROM_ADDRESS or call from().');
        }

        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new InvalidArgumentException('The message has no recipient.');
        }

        $boundary = 'sfphp-' . bin2hex(random_bytes(12));
        $headers = $this->headerLines($boundary);
        $body = $this->bodyLines($boundary);

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Build the header block.
     *
     * @param string $boundary The MIME boundary
     * @return list<string>
     */
    private function headerLines(string $boundary): array
    {
        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->sendingDomain() . '>',
            'From: ' . $this->formatAddress($this->from),
            'Subject: ' . $this->encodeHeaderText($this->subject),
            'MIME-Version: 1.0',
        ];

        if ($this->to !== []) {
            $headers[] = 'To: ' . $this->formatAddressList($this->to);
        }

        if ($this->cc !== []) {
            $headers[] = 'Cc: ' . $this->formatAddressList($this->cc);
        }

        /*
         * No Bcc header. The addresses go to the server in RCPT TO, which is
         * how the copy is delivered; writing them here would show every blind
         * recipient to everyone else, which is the one thing Bcc promises not
         * to do.
         */

        if ($this->replyTo !== []) {
            $headers[] = 'Reply-To: ' . $this->formatAddressList($this->replyTo);
        }

        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return array_merge($headers, $this->contentHeaders($boundary));
    }

    /**
     * The Content-* headers describing the body's shape.
     *
     * @param string $boundary The MIME boundary
     * @return list<string>
     */
    private function contentHeaders(string $boundary): array
    {
        if ($this->attachments !== []) {
            return ['Content-Type: multipart/mixed; boundary="' . $boundary . '"'];
        }

        if ($this->text !== null && $this->html !== null) {
            return ['Content-Type: multipart/alternative; boundary="' . $boundary . '"'];
        }

        if ($this->html !== null) {
            return ['Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        }

        return ['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
    }

    /**
     * Build the body.
     *
     * @param string $boundary The MIME boundary
     * @return string
     */
    private function bodyLines(string $boundary): string
    {
        if ($this->attachments === [] && !($this->text !== null && $this->html !== null)) {
            return $this->encodeBody((string) ($this->html ?? $this->text ?? ''));
        }

        $parts = [];

        if ($this->attachments !== [] && $this->text !== null && $this->html !== null) {
            /*
             * Both bodies and attachments: the alternatives go in a nested
             * multipart/alternative, so a client chooses between text and HTML
             * without treating the attachment as a third alternative.
             */
            $inner = 'sfphp-alt-' . bin2hex(random_bytes(8));
            $parts[] = "Content-Type: multipart/alternative; boundary=\"$inner\"\r\n\r\n"
                . $this->alternativeParts($inner);
        } else {
            if ($this->text !== null) {
                $parts[] = $this->textPart($this->text, 'text/plain');
            }

            if ($this->html !== null) {
                $parts[] = $this->textPart($this->html, 'text/html');
            }
        }

        foreach ($this->attachments as $attachment) {
            $parts[] = 'Content-Type: ' . $attachment['type'] . '; name=' . $this->quoted(self::asciiName($attachment['name'])) . "\r\n"
                . 'Content-Disposition: attachment; ' . $this->filenameParameter($attachment['name']) . "\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . $this->encodeBody($attachment['content']);
        }

        $body = '';

        foreach ($parts as $part) {
            $body .= '--' . $boundary . "\r\n" . $part . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * The text and HTML alternatives of a nested multipart/alternative.
     *
     * @param string $boundary The inner boundary
     * @return string
     */
    private function alternativeParts(string $boundary): string
    {
        $body = '';

        foreach ([[$this->text, 'text/plain'], [$this->html, 'text/html']] as [$content, $type]) {
            $body .= '--' . $boundary . "\r\n" . $this->textPart((string) $content, $type) . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * One text part, with its own content headers.
     *
     * @param string $content The body
     * @param string $type The media type
     * @return string
     */
    private function textPart(string $content, string $type): string
    {
        return 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . $this->encodeBody($content);
    }

    /**
     * Encode a body as base64 in lines a mail server accepts.
     *
     * Base64 rather than quoted-printable because it is immune to the two
     * things that corrupt a body in transit: a line longer than 998 characters,
     * and a server that rewrites whitespace. It costs a third more bytes and
     * removes a class of bug.
     *
     * @param string $content The raw body
     * @return string
     */
    private function encodeBody(string $content): string
    {
        return chunk_split(base64_encode($content), 76, "\r\n");
    }

    /**
     * Encode header text that may not be ASCII.
     *
     * A mail header is ASCII on the wire, so anything else travels as an RFC
     * 2047 encoded word. Pure ASCII is left alone, which keeps the common case
     * readable in a raw message.
     *
     * @param string $value The text
     * @return string
     */
    private function encodeHeaderText(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        /*
         * One encoded word may be at most 75 characters (RFC 2047), and a
         * header line at most 998 (RFC 5322). A long subject used to become
         * a single word of nearly two thousand characters, which servers
         * reject or truncate. It is cut into words of at most 45 bytes —
         * 60 characters of base64 plus the 12 around them — on character
         * boundaries, and the words are folded onto lines of their own.
         */
        $words = [];
        $current = '';

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [$value] as $character) {
            if ($current !== '' && strlen($current . $character) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($current) . '?=';
                $current = '';
            }

            $current .= $character;
        }

        if ($current !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($current) . '?=';
        }

        return implode("\r\n ", $words);
    }

    /**
     * A MIME parameter value as a quoted string.
     *
     * @param string $value An ASCII value
     * @return string The quoted value
     */
    private function quoted(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * The filename parameter of an attachment.
     *
     * An ASCII name is a quoted string, with its quotes escaped: a name such
     * as `evil".exe; x="y` used to end the parameter and start another. A
     * name in another script is written the way RFC 2231 defines, as
     * filename*=UTF-8''percent-encoded, with an ASCII fallback beside it —
     * an encoded word inside a quoted string, which is what this used to
     * send, is something RFC 2047 forbids and many clients show as it is.
     *
     * @param string $name The file name
     * @return string The parameter
     */
    private function filenameParameter(string $name): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            return 'filename=' . $this->quoted($name);
        }

        return 'filename=' . $this->quoted(self::asciiName($name)) . "; filename*=UTF-8''" . rawurlencode($name);
    }

    /**
     * An ASCII stand-in for a file name, for clients that read nothing else.
     */
    private static function asciiName(string $name): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $name);

        return $ascii === '' ? 'attachment' : $ascii;
    }

    /**
     * Format one address for a header.
     *
     * @param array{address: string, name: string} $address The address
     * @return string
     */
    private function formatAddress(array $address): string
    {
        if ($address['name'] === '') {
            return $address['address'];
        }

        $name = $address['name'];

        /*
         * A name with a comma, a quote, an @ or angle brackets is written as a
         * quoted string. Unquoted, "Visitor, attacker@evil.com" was read by
         * mail clients as two addresses, and a reply went to both.
         */
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            $name = preg_match('/[()<>\[\]:;@\\\\,."]/', $name) === 1 ? $this->quoted($name) : $name;
        } else {
            $name = $this->encodeHeaderText($name);
        }

        return $name . ' <' . $address['address'] . '>';
    }

    /**
     * Format a list of addresses for a header.
     *
     * @param list<array{address: string, name: string}> $addresses The addresses
     * @return string
     */
    private function formatAddressList(array $addresses): string
    {
        return implode(', ', array_map(fn (array $a): string => $this->formatAddress($a), $addresses));
    }

    /**
     * The domain the Message-ID is scoped to.
     *
     * @return string
     */
    private function sendingDomain(): string
    {
        $address = $this->from['address'] ?? '';
        $at = strrpos($address, '@');

        return $at === false ? 'localhost' : substr($address, $at + 1);
    }

    /**
     * Validate an address and its display name.
     *
     * @param string $address The address
     * @param string $name The display name
     * @return array{address: string, name: string}
     * @throws InvalidArgumentException When the address is malformed or either carries a line break
     */
    private function address(string $address, string $name): array
    {
        $address = $this->rejectLineBreaks(trim($address), 'address');

        /*
         * The same check the validator makes, so an address a form accepted
         * is one a message accepts. A domain in another script is sent in its
         * ASCII form, which every server understands; a local part outside
         * ASCII needs a server that speaks SMTPUTF8.
         */
        if (!\SfphpProject\src\Validator::isEmail($address)) {
            throw new InvalidArgumentException('"' . $address . '" is not a valid e-mail address.');
        }

        $at = strrpos($address, '@');
        $domain = substr($address, $at + 1);

        if (preg_match('/[^\x00-\x7F]/', $domain) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $address = substr($address, 0, $at + 1) . ($ascii === false ? $domain : $ascii);
        }

        return [
            'address' => $address,
            'name' => $this->rejectLineBreaks($name, 'display name'),
        ];
    }

    /**
     * Refuse a value that would let the caller write headers of their own.
     *
     * Rejected rather than stripped. A form field carrying a newline is either
     * an attack or a mistake, and silently sending a different message than the
     * one asked for is the wrong answer to both.
     *
     * @param string $value The value
     * @param string $what What it is, for the message
     * @return string The value, unchanged
     * @throws InvalidArgumentException When it carries CR or LF
     */
    private function rejectLineBreaks(string $value, string $what): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException(
                'A line break in a ' . $what . ' would let the caller add headers of their own.'
            );
        }

        return $value;
    }
}
