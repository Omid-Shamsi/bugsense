<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bug Evidence Attachments
    |--------------------------------------------------------------------------
    |
    | The spec (research.md §8, quickstart.md §F) does not state an exact
    | byte figure, only "configured size validation" against an allowlisted
    | set of evidence types. 1 MiB is a deliberately conservative MVP
    | default for screenshots/PDFs/logs, comfortably under PHP's own
    | upload_max_filesize so an oversized upload is rejected by this
    | application's own 413 logic rather than a raw PHP ini failure.
    |
    */

    'max_bytes' => (int) env('ATTACHMENT_MAX_BYTES', 1048576),

    /*
    |--------------------------------------------------------------------------
    | Allowed Evidence Types
    |--------------------------------------------------------------------------
    |
    | research.md §8: "Start with PNG, JPEG, WebP, PDF, plain text, and log
    | files; reject HTML, SVG, archives, executables, and active documents."
    | Matched against the server-DETECTED MIME type, never the client's
    | declared type or the filename extension alone.
    |
    */

    'allowed_mime_types' => [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/pdf',
        'text/plain',
    ],

];
