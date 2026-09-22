<?php

namespace SfphpProject\src\Http;

use RuntimeException;

/**
 * One uploaded file, and the checks that have to happen before it is kept.
 *
 * `$_FILES` was exposed raw before this existed, which left every application
 * to write the same security-critical code from scratch. File upload is a
 * classic way onto a server, and the mistakes are specific and repeatable:
 *
 * **The reported type is the client's claim.** `$_FILES['x']['type']` is a
 * header the browser sent, so a PHP script announced as `image/png` arrives as
 * `image/png`. Checking it proves nothing. `mimeType()` reads the file's own
 * bytes instead.
 *
 * **The reported name is the client's claim too.** Using it to build a path is
 * how `../../public/shell.php` gets written, and `store()` therefore never uses
 * it: the stored name is generated, and the client's name is kept only as
 * something to show a person.
 *
 * **A file that was not uploaded is not a file.** `$_FILES` can be forged when
 * a script is reachable in a way the author did not expect, pointing
 * `tmp_name` at `/etc/passwd`. `is_uploaded_file()` is what distinguishes the
 * two, and it is checked before anything is read or moved.
 *
 *     $file = $request->file('avatar');
 *
 *     if ($file !== null && $file->isValid()) {
 *         $file->assertType(['image/png', 'image/jpeg'])
 *              ->assertSmallerThan(2 * 1024 * 1024);
 *
 *         $path = $file->store('/var/app/storage/avatars');
 *     }
 */
final class UploadedFile
{
    /**
     * Create the file from one `$_FILES` entry.
     *
     * @param string $clientName The name the client sent
     * @param string $temporaryPath Where PHP put the upload
     * @param int $size The size in bytes as PHP measured it
     * @param int $error One of the UPLOAD_ERR_* codes
     * @param bool $trustPath Skip the is_uploaded_file() check — tests only
     */
    public function __construct(
        private string $clientName,
        private string $temporaryPath,
        private int $size,
        private int $error = UPLOAD_ERR_OK,
        private bool $trustPath = false
    ) {
    }

    /**
     * Build a file from one entry of the `$_FILES` array.
     *
     * @param array<string, mixed> $entry The entry
     * @param bool $trustPath Skip the is_uploaded_file() check — tests only
     * @return self The file
     */
    public static function fromArray(array $entry, bool $trustPath = false): self
    {
        return new self(
            (string) ($entry['name'] ?? ''),
            (string) ($entry['tmp_name'] ?? ''),
            (int) ($entry['size'] ?? 0),
            (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            $trustPath
        );
    }

    /**
     * Whether the upload arrived intact and really is an upload.
     *
     * @return bool True when the file can be used
     */
    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->trustPath
            ? is_file($this->temporaryPath)
            : is_uploaded_file($this->temporaryPath);
    }

    /**
     * Why the upload failed, in the visitor's language.
     *
     * PHP reports these as integers and the distinction matters to whoever is
     * filling in the form: "the file is too large" is something they can act
     * on, and "the server has no temporary directory" is not.
     *
     * @return string|null The message, or null when nothing went wrong
     */
    public function errorMessage(): ?string
    {
        if ($this->error === UPLOAD_ERR_OK) {
            return null;
        }

        $key = match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'upload.too_large',
            UPLOAD_ERR_PARTIAL => 'upload.incomplete',
            UPLOAD_ERR_NO_FILE => 'upload.missing',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'upload.cannot_store',
            UPLOAD_ERR_EXTENSION => 'upload.refused',
            default => 'upload.failed',
        };

        return function_exists('__') ? __($key) : $key;
    }

    /**
     * The name the client sent, with anything path-like taken out.
     *
     * Safe to show a person and never safe to build a path with — use
     * store(), which generates its own name.
     *
     * @return string The name
     */
    public function clientName(): string
    {
        /*
         * basename() alone is not enough: a null byte truncates the string for
         * anything that reaches C, so "shell.php\0.png" would pass an extension
         * check and land as "shell.php". Control characters go too, because a
         * name is eventually written into a log or a header.
         */
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->clientName) ?? '';
        $name = str_replace('\\', '/', $name);

        return basename($name);
    }

    /**
     * The extension of the name the client sent, lower-cased.
     *
     * The client's claim, like the name. assertExtension() compares against it
     * and assertType() checks what the file actually contains; a strict upload
     * checks both, because they are different lies.
     *
     * @return string The extension, without the dot
     */
    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName(), PATHINFO_EXTENSION));
    }

    /**
     * The size in bytes.
     *
     * @return int The size
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * The media type, read from the file's own bytes.
     *
     * Not `$_FILES['type']`, which is what the browser said. Needs
     * ext-fileinfo, which ships enabled with PHP; when it is missing this
     * returns null rather than guessing, and assertType() refuses rather than
     * passing a file it cannot identify.
     *
     * @return string|null The media type, or null when it cannot be determined
     */
    public function mimeType(): ?string
    {
        if (!$this->isValid() || !function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $type = finfo_file($finfo, $this->temporaryPath);
        finfo_close($finfo);

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Where PHP put the upload.
     *
     * @return string The temporary path
     */
    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    /**
     * The file's contents.
     *
     * @return string The bytes
     * @throws RuntimeException When the upload is not usable
     */
    public function contents(): string
    {
        $this->assertValid();

        return (string) file_get_contents($this->temporaryPath);
    }

    /**
     * Refuse the file unless its real type is one of these.
     *
     * @param list<string> $types The acceptable media types
     * @return self This file
     * @throws UploadException When the type is not acceptable or cannot be read
     */
    public function assertType(array $types): self
    {
        $this->assertValid();

        $type = $this->mimeType();

        if ($type === null) {
            throw new UploadException(
                'The type of the uploaded file could not be determined. ext-fileinfo is required to check it.'
            );
        }

        if (!in_array($type, $types, true)) {
            throw new UploadException(sprintf(
                'The uploaded file is %s; one of %s was expected.',
                $type,
                implode(', ', $types)
            ));
        }

        return $this;
    }

    /**
     * Refuse the file unless the name it came with ends in one of these.
     *
     * A weaker check than assertType() and worth having alongside it: what a
     * file contains decides how a library reads it, and what its name ends in
     * decides how a web server treats it. A PNG called `avatar.php` is a real
     * image and still a problem if it lands somewhere PHP is executed.
     *
     * @param list<string> $extensions The acceptable extensions, without dots
     * @return self This file
     * @throws UploadException When the extension is not acceptable
     */
    public function assertExtension(array $extensions): self
    {
        $extension = $this->clientExtension();
        $acceptable = array_map('strtolower', $extensions);

        if (!in_array($extension, $acceptable, true)) {
            throw new UploadException(sprintf(
                'The uploaded file is named "%s"; one of %s was expected.',
                $this->clientName(),
                implode(', ', $acceptable)
            ));
        }

        return $this;
    }

    /**
     * Refuse the file unless it is smaller than this.
     *
     * `upload_max_filesize` and `post_max_size` in php.ini are the first line
     * and cannot express "2MB for an avatar, 50MB for a video" — they apply to
     * the whole request. This is the per-field limit.
     *
     * @param int $bytes The largest acceptable size
     * @return self This file
     * @throws UploadException When the file is larger
     */
    public function assertSmallerThan(int $bytes): self
    {
        $this->assertValid();

        if ($this->size > $bytes) {
            throw new UploadException(sprintf(
                'The uploaded file is %d bytes; at most %d was expected.',
                $this->size,
                $bytes
            ));
        }

        return $this;
    }

    /**
     * Refuse the file unless it is an image the server can actually read.
     *
     * Stronger than checking the media type: this decodes the header, so a file
     * whose first bytes look like a PNG but whose contents are not an image is
     * refused. It is also what an application usually means by "an image".
     *
     * @return self This file
     * @throws UploadException When it is not a readable image
     */
    public function assertImage(): self
    {
        $this->assertValid();

        $info = @getimagesize($this->temporaryPath);

        if ($info === false) {
            throw new UploadException('The uploaded file is not an image the server can read.');
        }

        return $this;
    }

    /**
     * The image's width and height, when it is an image.
     *
     * @return array{width: int, height: int}|null The dimensions, or null
     */
    public function dimensions(): ?array
    {
        if (!$this->isValid()) {
            return null;
        }

        $info = @getimagesize($this->temporaryPath);

        return $info === false ? null : ['width' => $info[0], 'height' => $info[1]];
    }

    /**
     * Move the file into a directory, under a name this class chooses.
     *
     * The name is random, and that is the point: the client's name is the
     * client's input, and using it to build a path is how a file lands
     * somewhere it was never meant to. The extension is carried over only when
     * it is plain alphanumeric, so nothing in it can be a path or a second
     * extension.
     *
     * @param string $directory Where to put it
     * @param string|null $name A name to use instead, which is still sanitised
     * @return string The full path the file was written to
     * @throws UploadException When the file cannot be stored
     */
    public function store(string $directory, ?string $name = null): string
    {
        $this->assertValid();

        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new UploadException('The directory ' . $directory . ' cannot be created.');
        }

        $target = $directory . DIRECTORY_SEPARATOR . ($name === null
            ? $this->generatedName()
            : $this->safeName($name));

        /*
         * move_uploaded_file() rather than rename(), because it refuses a path
         * PHP did not receive as an upload. It is the same guard as
         * is_uploaded_file(), applied at the moment that matters.
         */
        $moved = $this->trustPath
            ? @rename($this->temporaryPath, $target)
            : @move_uploaded_file($this->temporaryPath, $target);

        if ($moved !== true) {
            throw new UploadException('The uploaded file could not be moved to ' . $target . '.');
        }

        return $target;
    }

    /**
     * A random name, carrying the extension when it is safe to carry.
     *
     * @return string The name
     */
    private function generatedName(): string
    {
        $extension = $this->clientExtension();
        $safe = preg_match('/^[a-z0-9]{1,16}$/', $extension) === 1;

        return bin2hex(random_bytes(16)) . ($safe ? '.' . $extension : '');
    }

    /**
     * Reduce a caller-supplied name to something that cannot be a path.
     *
     * @param string $name The name
     * @return string The name, safe to append to a directory
     * @throws UploadException When nothing usable is left
     */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = ltrim($name, '.');

        if ($name === '' || $name === '.' || $name === '..') {
            throw new UploadException('The name given for the uploaded file leaves nothing usable.');
        }

        return $name;
    }

    /**
     * Refuse to go any further with an upload that is not usable.
     *
     * @return void
     * @throws UploadException When the upload failed or is not an upload
     */
    private function assertValid(): void
    {
        if ($this->isValid()) {
            return;
        }

        throw new UploadException(
            $this->error !== UPLOAD_ERR_OK
                ? 'The upload failed: ' . ($this->errorMessage() ?? 'unknown error') . '.'
                : 'The path given is not an uploaded file.'
        );
    }
}
