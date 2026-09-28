<?php

/*
 * Uploads.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\UploadException;
use SfphpProject\src\Http\UploadedFile;

$tests->run('an upload is refused unless it really is an upload', function () use ($tests): void {
    /*
     * The check that matters most and is easiest to leave out. $_FILES can be
     * forged when a script is reachable in a way its author did not expect, and
     * a tmp_name pointing at /etc/passwd would otherwise be read and stored as
     * though the visitor had uploaded it. is_uploaded_file() is what tells the
     * two apart.
     */
    $forged = new UploadedFile('passwd', '/etc/passwd', 100);

    $tests->assertSame(false, $forged->isValid());
    $tests->assertThrows(fn () => $forged->contents(), UploadException::class);
    $tests->assertThrows(fn () => $forged->store(sys_get_temp_dir()), UploadException::class);

    // A failed upload says why, in the visitor's language.
    $tooBig = new UploadedFile('photo.jpg', '', 0, UPLOAD_ERR_INI_SIZE);
    $tests->assertSame(false, $tooBig->isValid());
    $tests->assertSame(__('upload.too_large'), $tooBig->errorMessage());

    $absent = new UploadedFile('', '', 0, UPLOAD_ERR_NO_FILE);
    $tests->assertSame(__('upload.missing'), $absent->errorMessage());

    // Nothing wrong means no message rather than an empty one.
    $tests->assertSame(null, (new UploadedFile('a', '/tmp', 1))->errorMessage());
});

$tests->run('the name a client sends cannot become a path', function () use ($tests): void {
    /*
     * "../../public/shell.php" is how an upload lands somewhere PHP is
     * executed, and "shell.php\0.png" is how it passes an extension check on
     * the way: a null byte truncates the string for anything that reaches C.
     */
    $traversal = new UploadedFile('../../public/shell.php', '/tmp/x', 1);
    $tests->assertSame('shell.php', $traversal->clientName());

    $nullByte = new UploadedFile("shell.php\x00.png", '/tmp/x', 1);
    $tests->assertSame('shell.php.png', $nullByte->clientName());
    $tests->assertSame('png', $nullByte->clientExtension());

    $windows = new UploadedFile('C:\\\\Users\\\\ana\\\\avatar.PNG', '/tmp/x', 1);
    $tests->assertSame('avatar.PNG', $windows->clientName());
    $tests->assertSame('png', $windows->clientExtension());
});

$tests->run('a stored file gets a name the framework chose', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-upload-' . bin2hex(random_bytes(6));
    $source = $directory . '/incoming';
    mkdir($directory, 0777, true);
    file_put_contents($source, 'conteúdo');

    $file = new UploadedFile('../../evil.php', $source, 8, UPLOAD_ERR_OK, trustPath: true);

    $stored = $file->store($directory . '/kept');

    /*
     * The client's name is nowhere in the path. It is random, and the
     * extension comes from what the file contains — text here — never the
     * ".php" the client sent, which a server might run.
     */
    $tests->assertSame(true, str_starts_with($stored, $directory . '/kept/'));
    $tests->assertSame(false, str_contains($stored, 'evil'));
    $tests->assertSame(1, preg_match('#/[0-9a-f]{32}\.txt$#', $stored));
    $tests->assertSame('conteúdo', file_get_contents($stored));

    // A caller-supplied name is still reduced to something that is not a path.
    file_put_contents($source, 'x');
    $named = (new UploadedFile('a.txt', $source, 1, UPLOAD_ERR_OK, trustPath: true))
        ->store($directory . '/kept', '../../../etc/passwd');

    $tests->assertSame($directory . '/kept/passwd', $named);

    // And a name that reduces to nothing is refused rather than guessed at.
    file_put_contents($source, 'x');
    $tests->assertThrows(
        fn () => (new UploadedFile('a.txt', $source, 1, UPLOAD_ERR_OK, trustPath: true))
            ->store($directory . '/kept', '...'),
        UploadException::class
    );

    array_map('unlink', glob($directory . '/kept/*') ?: []);
    @unlink($source);
    @rmdir($directory . '/kept');
    @rmdir($directory);
});

$tests->run('the type is read from the bytes, not from what the client said', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-upload-' . bin2hex(random_bytes(6));
    mkdir($directory, 0777, true);

    // A PHP script announcing itself as a PNG, which is the whole attack.
    $script = $directory . '/upload';
    file_put_contents($script, "<?php echo 'owned';");
    $lying = new UploadedFile('avatar.png', $script, 19, UPLOAD_ERR_OK, trustPath: true);

    $tests->assertSame('png', $lying->clientExtension());
    $tests->assertTrue($lying->mimeType() !== 'image/png');
    $tests->assertThrows(fn () => $lying->assertType(['image/png']), UploadException::class);

    // It is not an image either, which getimagesize() decides by decoding.
    $tests->assertThrows(fn () => $lying->assertImage(), UploadException::class);

    // A real PNG passes both, and reports its size.
    $png = $directory . '/real';
    file_put_contents($png, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8DAwMDEwMDAwMAAAA8QAQFqKQ0kAAAAAElFTkSuQmCC'
    ));
    $real = new UploadedFile('avatar.png', $png, filesize($png), UPLOAD_ERR_OK, trustPath: true);

    $tests->assertSame('image/png', $real->mimeType());
    $real->assertType(['image/png', 'image/jpeg'])->assertImage()->assertExtension(['png', 'jpg']);
    $tests->assertSame(['width' => 2, 'height' => 2], $real->dimensions());

    // Size and extension are separate refusals with their own messages.
    $tests->assertThrows(fn () => $real->assertSmallerThan(1), UploadException::class);
    $tests->assertThrows(fn () => $real->assertExtension(['gif']), UploadException::class);

    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);
});

$tests->run('a field with several files is turned the right way round', function () use ($tests): void {
    /*
     * The shape that catches people out: $_FILES['photos'] for name="photos[]"
     * is not a list of files, it is one file whose every property is a list.
     */
    $request = Request::create('POST', '/upload', ['files' => [
        'photos' => [
            'name' => ['a.png', 'b.png'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'size' => [10, 20],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        ],
        'avatar' => ['name' => 'c.png', 'tmp_name' => '/tmp/c', 'size' => 30, 'error' => UPLOAD_ERR_OK],
    ]]);

    $photos = $request->files('photos');
    $tests->assertSame(2, count($photos));
    $tests->assertSame(['a.png', 'b.png'], array_map(fn (UploadedFile $f): string => $f->clientName(), $photos));
    $tests->assertSame([10, 20], array_map(fn (UploadedFile $f): int => $f->size(), $photos));

    // A single file answers file(), and a multiple one does not.
    $tests->assertSame('c.png', $request->file('avatar')?->clientName());
    $tests->assertSame(null, $request->file('photos'));
    $tests->assertSame(1, count($request->files('avatar')));

    // An absent field is empty rather than an error.
    $tests->assertSame([], $request->files('nada'));
    $tests->assertSame(null, $request->file('nada'));

    // hasFile() is about a usable file, not a present field.
    $tests->assertSame(false, $request->hasFile('nada'));
    $tests->assertSame(false, Request::create('POST', '/', ['files' => [
        'doc' => ['name' => 'x', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE],
    ]])->hasFile('doc'));
});

$tests->run('an SSE field cannot write fields of its own, and an upload never keeps an extension a server would run', function () use ($tests): void {
    $out = new class implements \SfphpProject\src\Http\StreamWriter {
        public string $sent = '';
        public function write(string $chunk): bool { $this->sent .= $chunk; return true; }
        public function aborted(): bool { return false; }
        public function flush(): void {}
    };
    $sse = new \SfphpProject\src\Http\ServerSentEvent($out);

    $tests->assertThrows(fn () => $sse->send('x', event: "x\nid: 99"), InvalidArgumentException::class);
    $sse->send("a\rb\r\nc");
    $tests->assertSame("data: a\ndata: b\ndata: c\n\n", $out->sent);

    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64) . '<?php echo 1;');
    $file = UploadedFile::fromArray(['name' => '../../evil.PHP', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK]);
    $generated = (new ReflectionMethod($file, 'generatedName'))->invoke($file);
    $tests->assertSame(false, str_ends_with($generated, '.php'));

    $safe = new ReflectionMethod($file, 'safeName');
    $tests->assertSame('relatório.pdf', $safe->invoke($file, 'relatório.pdf'));
    $tests->assertSame('shell.php.txt', $safe->invoke($file, 'shell.php'));
    unlink($tmp);

    $latin1 = UploadedFile::fromArray(['name' => "caf\xE9.txt", 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]);
    $tests->assertTrue($latin1->clientName() !== '');

    $empty = Request::create('POST', '/', ['files' => ['photos' => ['name' => [''], 'tmp_name' => [''], 'size' => [0], 'error' => [UPLOAD_ERR_NO_FILE]]]]);
    $tests->assertSame([], $empty->files('photos'));
});
