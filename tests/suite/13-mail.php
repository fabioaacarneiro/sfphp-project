<?php

/*
 * Mail.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Mail\ArrayDriver as MailArrayDriver;
use SfphpProject\src\Mail\MailManager;
use SfphpProject\src\Mail\Message;

$tests->run('a message renders the headers and body a mail server expects', function () use ($tests): void {
    $rendered = (new Message())
        ->from('nao-responda@exemplo.com', 'Loja')
        ->to('ana@exemplo.com', 'Ana')
        ->subject('Pedido confirmado')
        ->text('Obrigado.')
        ->toString();

    $tests->assertTrue(str_contains($rendered, 'From: Loja <nao-responda@exemplo.com>'));
    $tests->assertTrue(str_contains($rendered, 'To: Ana <ana@exemplo.com>'));
    $tests->assertTrue(str_contains($rendered, 'Subject: Pedido confirmado'));
    $tests->assertTrue(str_contains($rendered, 'Content-Type: text/plain; charset=UTF-8'));

    // Every line ends CRLF, which is what the protocol requires.
    $tests->assertSame(0, preg_match('/(?<!\r)\n/', $rendered));

    $body = substr($rendered, strpos($rendered, "\r\n\r\n") + 4);
    $tests->assertSame('Obrigado.', base64_decode(trim($body)));
});

$tests->run('headers that are not ASCII are encoded, not sent raw', function () use ($tests): void {
    $rendered = (new Message())
        ->from('a@exemplo.com', 'Ação Imediata')
        ->to('b@exemplo.com', 'João Gonçalves')
        ->subject('Confirmação de inscrição')
        ->text('Olá João. 日本語')
        ->toString();

    /*
     * A mail header is ASCII on the wire. Sending UTF-8 raw is what produces a
     * subject line of mojibake in half the clients, so anything outside ASCII
     * travels as an RFC 2047 encoded word.
     */
    $tests->assertTrue(str_contains($rendered, 'Subject: =?UTF-8?B?' . base64_encode('Confirmação de inscrição') . '?='));
    $tests->assertTrue(str_contains($rendered, '=?UTF-8?B?' . base64_encode('Ação Imediata') . '?='));

    // And no bare non-ASCII byte survives in the header block.
    $headers = substr($rendered, 0, strpos($rendered, "\r\n\r\n"));
    $tests->assertSame(1, preg_match('/^[\x00-\x7F]*$/', $headers));

    // Pure ASCII is left alone, so a raw message stays readable.
    $plain = (new Message())->from('a@exemplo.com')->to('b@exemplo.com')->subject('Order')->text('x')->toString();
    $tests->assertTrue(str_contains($plain, 'Subject: Order'));
});

$tests->run('a line break in a header is refused, not escaped', function () use ($tests): void {
    /*
     * This is the injection. A newline in a value the caller supplied — a name
     * typed into a form — lets them append headers of their own, and "Bcc: a
     * third party" is the one that matters. Refused rather than stripped:
     * quietly sending a different message than the one asked for is the wrong
     * answer to both an attack and a mistake.
     */
    foreach ([
        fn () => (new Message())->subject("Oi\r\nBcc: vitima@exemplo.com"),
        fn () => (new Message())->to('a@exemplo.com', "Ana\nBcc: vitima@exemplo.com"),
        fn () => (new Message())->header('X-Custom', "a\r\nBcc: vitima@exemplo.com"),
        fn () => (new Message())->attach("nota\r\n.txt", 'x'),
    ] as $attempt) {
        $tests->assertThrows($attempt, InvalidArgumentException::class);
    }

    // A malformed address is refused too, before it reaches a server.
    $tests->assertThrows(fn () => (new Message())->to('não é um endereço'), InvalidArgumentException::class);
});

$tests->run('bcc reaches the server and never reaches a header', function () use ($tests): void {
    $message = (new Message())
        ->from('a@exemplo.com')
        ->to('ana@exemplo.com')
        ->cc('copia@exemplo.com')
        ->bcc('oculto@exemplo.com')
        ->subject('x')
        ->text('y');

    // Delivery uses this list, and it carries the blind copy.
    $tests->assertSame(
        ['ana@exemplo.com', 'copia@exemplo.com', 'oculto@exemplo.com'],
        $message->recipients()
    );

    /*
     * The rendered message must not mention it. Writing a Bcc header would show
     * every blind recipient to everyone else, which is the one thing Bcc
     * promises not to do.
     */
    $rendered = $message->toString();
    $tests->assertTrue(str_contains($rendered, 'Cc: copia@exemplo.com'));
    $tests->assertSame(false, str_contains($rendered, 'oculto@exemplo.com'));
});

$tests->run('text and html travel as alternatives, attachments as parts', function () use ($tests): void {
    $both = (new Message())
        ->from('a@exemplo.com')->to('b@exemplo.com')->subject('x')
        ->text('versão texto')->html('<p>versão HTML</p>')
        ->toString();

    $tests->assertTrue(str_contains($both, 'Content-Type: multipart/alternative'));
    $tests->assertTrue(str_contains($both, 'Content-Type: text/plain; charset=UTF-8'));
    $tests->assertTrue(str_contains($both, 'Content-Type: text/html; charset=UTF-8'));

    $withFile = (new Message())
        ->from('a@exemplo.com')->to('b@exemplo.com')->subject('x')
        ->text('corpo')
        ->attach('nota.txt', 'conteúdo', 'text/plain')
        ->toString();

    $tests->assertTrue(str_contains($withFile, 'Content-Type: multipart/mixed'));
    $tests->assertTrue(str_contains($withFile, 'Content-Disposition: attachment; filename="nota.txt"'));
    $tests->assertTrue(str_contains($withFile, base64_encode('conteúdo')));

    // A message with no sender or no recipient says so instead of going out broken.
    $tests->assertThrows(
        fn () => (new Message())->to('b@exemplo.com')->toString(),
        InvalidArgumentException::class
    );
    $tests->assertThrows(
        fn () => (new Message())->from('a@exemplo.com')->toString(),
        InvalidArgumentException::class
    );
});

$tests->run('the manager fills in the sender and can redirect everything', function () use ($tests): void {
    $driver = new MailArrayDriver();
    $mailer = new MailManager($driver, 'nao-responda@exemplo.com', 'Loja');

    $mailer->send((new Message())->to('ana@exemplo.com')->subject('x')->text('y'));

    $tests->assertSame(
        ['address' => 'nao-responda@exemplo.com', 'name' => 'Loja'],
        $driver->last()->sender()
    );

    // A message that names its own sender keeps it.
    $mailer->send((new Message())->from('outro@exemplo.com')->to('ana@exemplo.com')->subject('x')->text('y'));
    $tests->assertSame('outro@exemplo.com', $driver->last()->sender()['address']);

    /*
     * Redirecting is for a staging environment working from a copy of
     * production data, where the addresses in the database belong to real
     * people. The intended recipient is kept so the message still says who it
     * was for.
     */
    $driver->flush();
    $mailer->alwaysTo('equipe@exemplo.com');
    $mailer->send((new Message())->to('cliente-real@exemplo.com')->subject('x')->text('y'));

    $tests->assertSame(['equipe@exemplo.com'], $driver->last()->recipients());
    $tests->assertTrue(str_contains($driver->last()->toString(), 'X-Intended-For: cliente-real@exemplo.com'));

    $mailer->alwaysTo(null);
    $driver->flush();
    $tests->assertSame(null, $driver->last());
});

$tests->run('mail keeps blind copies blind, quotes names, folds long subjects and never sends a password in the clear', function () use ($tests): void {
    $message = (new Message())
        ->from('app@example.com', 'Support <ceo@bank.com>')
        ->to('x@example.com')
        ->bcc('secret@example.com')
        ->replyTo('visitor@example.com', 'Visitor, attacker@evil.com')
        ->subject(str_repeat('Relatório mensal — ', 40))
        ->text('hi')
        ->attach('evil".exe; x="y', 'data')
        ->attach('relatório.pdf', 'data');

    $raw = $message->toString();

    $tests->assertTrue(str_contains($raw, 'Reply-To: "Visitor, attacker@evil.com" <visitor@example.com>'));
    $tests->assertTrue(str_contains($raw, 'From: "Support <ceo@bank.com>" <app@example.com>'));
    $tests->assertTrue(str_contains($raw, 'filename="evil\".exe; x=\"y"'));
    $tests->assertTrue(str_contains($raw, "filename*=UTF-8''relat%C3%B3rio.pdf"));
    $tests->assertSame(false, str_contains($raw, 'secret@example.com'));

    foreach (explode("\r\n", substr($raw, 0, (int) strpos($raw, "\r\n\r\n"))) as $line) {
        $tests->assertTrue(strlen($line) <= 998);
    }

    // mail(): the To header names only the real recipients; the blind copy rides in Bcc for sendmail -t.
    $capture = sys_get_temp_dir() . '/sfphp-sendmail-' . bin2hex(random_bytes(4));
    $script = $capture . '.sh';
    file_put_contents($script, "#!/bin/sh\ncat > " . escapeshellarg($capture) . "\n");
    chmod($script, 0700);

    $result = shell_exec(sprintf(
        '%s -d sendmail_path=%s -r %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script . ' -t -i'),
        escapeshellarg('require ' . var_export(dirname(__DIR__) . '/../vendor/autoload.php', true) . ';'
            . '(new SfphpProject\src\Mail\MailDriver())->send((new SfphpProject\src\Mail\Message())'
            . '->from("a@example.com")->to("x@example.com")->cc("c@example.com")->bcc("secret@example.com")->subject("s")->text("t")); echo "ok";')
    ));
    $sent = (string) @file_get_contents($capture);
    @unlink($capture);
    @unlink($script);

    $tests->assertSame('ok', trim((string) $result));
    $tests->assertTrue(str_contains($sent, 'To: x@example.com'));
    $tests->assertSame(false, (bool) preg_match('/^To:.*secret/m', $sent));
    $tests->assertTrue(str_contains($sent, 'Bcc: secret@example.com'));

    // "TLS" is tls; a value that is none of the three is refused.
    $tests->assertThrows(fn () => new \SfphpProject\src\Mail\SmtpDriver(encryption: 'tsl'), InvalidArgumentException::class);
    new \SfphpProject\src\Mail\SmtpDriver(encryption: 'TLS');
    new \SfphpProject\src\Mail\SmtpDriver(encryption: 'starttls');

    // Staging redirection keeps the attachments and the Reply-To.
    $array = new MailArrayDriver();
    (new MailManager($array, 'app@example.com'))->alwaysTo('qa@example.com')->send($message);
    $delivered = $array->messages()[0];
    $tests->assertSame(['qa@example.com'], $delivered->recipients());
    $tests->assertTrue(str_contains($delivered->toString(), 'relat%C3%B3rio.pdf'));
    $tests->assertTrue(str_contains($delivered->toString(), 'X-Intended-For: x@example.com, secret@example.com'));
    $tests->assertSame(['x@example.com', 'secret@example.com'], $message->recipients());
});
