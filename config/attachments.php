<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | Maximum size of one file. PHP's upload_max_filesize and post_max_size must allow it.
    |
    */

    'max_kilobytes' => (int) env('ATTACHMENTS_MAX_KILOBYTES', 25_600),

    /*
    |--------------------------------------------------------------------------
    | Files per upload
    |--------------------------------------------------------------------------
    |
    | Maximum files in one request, which keeps a batch under post_max_size.
    |
    */

    'max_files' => (int) env('ATTACHMENTS_MAX_FILES', 10),

    /*
    |--------------------------------------------------------------------------
    | Accepted types
    |--------------------------------------------------------------------------
    |
    | Allowed MIME types, detected from the file contents rather than the extension.
    |
    */

    'mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention after removal
    |--------------------------------------------------------------------------
    |
    | Days after removal before `files:sweep` permanently deletes the stored file.
    |
    */

    'sweep_after_days' => (int) env('ATTACHMENTS_SWEEP_AFTER_DAYS', 30),

];
