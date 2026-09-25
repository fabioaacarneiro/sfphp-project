<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Response;

use function SfphpProject\app\components\examples\StreamingExamples;

/**
 * Examples demonstrating SFJS directives and features
 */
final class ExamplesController
{
    /**
     * Show streaming examples (@stream and @sse)
     *
     * @return Response The examples page
     */
    public function streaming(): Response
    {
        return Response::phpx(StreamingExamples());
    }
}
