<?php

/**
 * Why an upload did not arrive, read by the person filling in the form.
 *
 * PHP reports these as UPLOAD_ERR_* integers, and the distinction matters to
 * whoever is uploading: "the file is too large" is something they can act on,
 * and "the server has no temporary directory" is not — which is why the two
 * are not the same message.
 */

return [
    'too_large' => 'The file is larger than the server accepts.',
    'incomplete' => 'The file was only partly uploaded. Please try again.',
    'missing' => 'No file was sent.',
    'cannot_store' => 'The file could not be stored. Please try again shortly.',
    'refused' => 'The file was refused by the server.',
    'failed' => 'The file could not be uploaded.',
];
