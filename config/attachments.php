<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | The size bound lives here rather than in a rules array so that changing it
    | is a configuration change and so that the same number can be shown to the
    | person doing the uploading. It is bounded by PHP's own upload_max_filesize
    | and post_max_size, which refuse earlier and less politely.
    |
    */

    'max_kilobytes' => (int) env('ATTACHMENTS_MAX_KILOBYTES', 25_600),

    /*
    |--------------------------------------------------------------------------
    | Accepted types
    |--------------------------------------------------------------------------
    |
    | An allow-list, not a deny-list: a deny-list is a promise to have thought of
    | every dangerous type in advance, and the list of those grows without asking.
    | Values are MIME types, which Laravel checks by reading the file rather than
    | by trusting the extension or the client's Content-Type.
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
    | Removing an attachment soft-deletes the file and leaves the object alone;
    | `files:sweep` deletes the bytes this many days later. The window exists so
    | that the one irreversible step in this application happens on a schedule,
    | where a mistake is noticed before it is permanent (TASK-120-007).
    |
    */

    'sweep_after_days' => (int) env('ATTACHMENTS_SWEEP_AFTER_DAYS', 30),

];
