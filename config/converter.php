<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Converter pipeline
    |--------------------------------------------------------------------------
    |
    | The panel converts the archive's PDFs into page images: it claims a content
    | from its own queue, downloads the PDF from the archive's FTP site, renders
    | it with pdf2img, writes one page row per image into the archive database,
    | uploads the images, and marks the content converted.
    |
    */

    'archive' => [

        // The database connection of the archive (config/database.php).
        'connection' => env('ARCHIVE_CONNECTION', 'archive'),

        // "off" makes every write against the archive throw instead of running. Use it for a dry run
        // against production: the whole pipeline works, nothing is changed.
        'write_mode' => env('CONVERTER_WRITE_MODE', 'off'),

        // Format written to MVDContent.Format for a page image, and for the source PDF row. The
        // letter case is the archive's own (the FTP file extension is taken from the part after "/").
        'image_format' => 'Image/jpg',
        'pdf_format' => 'Application/pdf',

        // GeneralContent.Reserved values the archive uses as markers.
        'reserved' => [
            'free' => '00000000-0000-0000-0000-000000000000',
            'converted' => 'DD18D1A0-C693-4379-B350-8F37E7612998',
            'failed' => '11111111-1111-1111-1111-111111111111',
        ],

        // Seconds a query may run before it is cancelled.
        'query_timeout' => (int) env('CONVERTER_QUERY_TIMEOUT', 120),
    ],

    'queue' => [

        // Laravel queue connection and queue name of the conversion jobs.
        'connection' => env('CONVERTER_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'name' => env('CONVERTER_QUEUE', 'conversions'),

        // Contents converted at the same time, i.e. the number of queue workers to run.
        'workers' => (int) env('CONVERTER_WORKERS', 4),

        // The dispatcher keeps at most this many contents queued per worker, so that a Stop does not
        // leave a long backlog of claimed work behind.
        'queued_per_worker' => (int) env('CONVERTER_QUEUED_PER_WORKER', 2),

        // Seconds to wait before asking for work again when the queue is empty, and the longest wait
        // after repeated empty answers.
        'idle_seconds' => (int) env('CONVERTER_IDLE_SECONDS', 5),
        'max_idle_seconds' => (int) env('CONVERTER_MAX_IDLE_SECONDS', 60),
    ],

    'discovery' => [

        // Contents to pull into the local queue per pass, and how often a pass runs.
        'batch' => (int) env('CONVERTER_DISCOVERY_BATCH', 5000),
        'interval_minutes' => (int) env('CONVERTER_DISCOVERY_INTERVAL', 15),

        // The archive is scanned by GeneralContent.ProcessDate; the watermark is kept in the
        // conversion_watermarks table and rewound by this much on every pass, so that a content
        // inserted while a pass was running is not missed.
        'overlap_minutes' => (int) env('CONVERTER_DISCOVERY_OVERLAP', 60),

        // Where a first, empty queue starts from. The old pipeline's PdfConvert table already holds
        // the contents waiting at the time it was built; seeding from it is far cheaper than
        // scanning GeneralContent, which has ~93 million rows.
        'seed_from_pdfconvert' => (bool) env('CONVERTER_SEED_FROM_PDFCONVERT', true),
    ],

    'ftp' => [

        // Seconds for the connect, and for every later operation. The previous pipeline set neither,
        // inherited a 100 second default, and lost 4382 contents to listing timeouts.
        'connect_timeout' => (int) env('CONVERTER_FTP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('CONVERTER_FTP_TIMEOUT', 30),

        // Attempts per FTP operation, and the seconds waited between them.
        'attempts' => (int) env('CONVERTER_FTP_ATTEMPTS', 3),
        'retry_seconds' => (int) env('CONVERTER_FTP_RETRY_SECONDS', 5),
    ],

    'render' => [

        // pdf2img and the arguments in front of -i/-o. "-c lzw" is a TIFF flag and does nothing for
        // JPEG output; the resolution is what matters.
        'binary' => env('CONVERTER_PDF2IMG', 'C:\\pdfToImg\\bin\\pdf2img.exe'),
        'arguments' => env('CONVERTER_PDF2IMG_ARGS', '-r 300'),

        // Seconds a single PDF may take. The production log has conversions of 205 seconds, so this
        // is generous; a worker that exceeds it is killed and the content is retried.
        'timeout' => (int) env('CONVERTER_RENDER_TIMEOUT', 1800),
    ],

    'thumbnail' => [
        'width' => 120,
        'height' => 160,
        'quality' => (int) env('CONVERTER_THUMBNAIL_QUALITY', 82),
    ],

    'workspace' => [

        // Where a content's PDF and rendered pages live while it is being converted. Every content
        // gets its own folder, which is deleted once its pages are stored: nothing accumulates.
        'root' => env('CONVERTER_WORKSPACE', 'C:\\pdfToImg\\work'),

        // Gigabytes that must stay free on that drive; below this the pipeline pauses instead of
        // filling the disk (the previous pipeline lost 206 contents that way).
        'free_space_floor_gb' => (int) env('CONVERTER_FREE_SPACE_FLOOR_GB', 20),
    ],

    'failure' => [

        // Attempts per content before it is left failed for a person to look at.
        'max_attempts' => (int) env('CONVERTER_MAX_ATTEMPTS', 3),

        // A content whose worker stopped sending a heartbeat for this long is cleaned up and queued
        // again. This replaces the scripts that used to rebuild interrupted conversions by hand.
        'stale_after_minutes' => (int) env('CONVERTER_STALE_AFTER_MINUTES', 60),
    ],

];
