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

    /*
     * Profiles that are not converted, as a comma-separated list of GeneralContent.ProfileID
     * (CONVERTER_SKIP_PROFILES=65,71).
     *
     * A profile whose documents have been removed from the file store still has all of its contents
     * in GeneralContent, each naming a PDF that is not there any more. Nothing in the database says
     * so - the MVDContent rows are not flagged deleted - so the only way to find out is to ask the
     * file store, and that costs a whole FTP session per content: connect, log in, fail to download,
     * list the folder, ask for the folder itself. Hundreds of thousands of contents that way take
     * days and convert nothing.
     *
     * Naming the profile here answers for all of them at once: discovery stops offering them, and the
     * ones already queued are retired by converters:skip without a single FTP connection.
     *
     * @see \App\Console\Commands\SkipProfiles
     */
    'skip_profiles' => array_values(array_unique(array_filter(array_map(
        static fn (string $profileId): int => (int) trim($profileId),
        explode(',', (string) env('CONVERTER_SKIP_PROFILES', '')),
    )))),

    'archive' => [

        // The database connection of the archive (config/database.php).
        'connection' => env('ARCHIVE_CONNECTION', 'archive'),

        // "off" makes every write against the archive throw instead of running. Use it for a dry run
        // against production: the whole pipeline works, nothing is changed.
        'write_mode' => env('CONVERTER_WRITE_MODE', 'off'),

        // MVDContent.Format written for a page image. The letter case is the archive's own, and the
        // FTP file extension is taken from the part after "/" - so this and CONVERTER_PDF2IMG_ARGS
        // have to agree: "Image/png" with a renderer still producing JPEG stores .png files that no
        // viewer can open.
        'image_format' => env('CONVERTER_IMAGE_FORMAT', 'Image/jpg'),

        /*
         * Below are the archive's own values rather than settings of ours, which is why they are not
         * env-backed: they are what the existing 140 million MVDContent rows and 93 million
         * GeneralContent rows already contain. Changing one would not re-label a single existing row,
         * it would only make this pipeline stop recognising them - discovery would find no work, and
         * every content this pipeline had already converted would look unconverted. SqlServerArchive
         * holds them as constants (it builds the SQL) and a test asserts the two agree.
         */

        // MVDContent.Format of a source PDF: what discovery matches a convertible content by.
        'pdf_format' => 'Application/pdf',

        // GeneralContent.Reserved markers: nobody holds it, we converted it, we failed on it.
        'reserved' => [
            'free' => '00000000-0000-0000-0000-000000000000',
            'converted' => 'DD18D1A0-C693-4379-B350-8F37E7612998',
            'failed' => '11111111-1111-1111-1111-111111111111',
        ],

        // Seconds a query on the archive connection may run before the driver cancels it, applied as
        // the connection's PDO::SQLSRV_ATTR_QUERY_TIMEOUT (config/database.php). 0 means no limit,
        // and that is the default on purpose: a discovery pass scans GeneralContent by ProcessDate
        // over 93 million rows and can legitimately take minutes, while a cancelled discovery pass
        // fails silently and leaves the watermark where it was - the queue would simply stop filling.
        'query_timeout' => (int) env('CONVERTER_QUERY_TIMEOUT', 0),
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

    'store' => [

        /*
         * How the panel reaches the archive's files: "ftp" talks to the site the archive names in
         * FtpSites, "disk" opens the same folders directly.
         *
         * Use "disk" when the panel runs on the machine that holds the archive. The FTP server is
         * then a round trip to localhost for files that are already on a local disk: the source PDF
         * is copied into the workspace to be rendered and every page image is copied back out. On
         * disk the renderer reads the archive's own file where it lies, so a conversion does one
         * fewer full copy of the original.
         */
        'driver' => env('CONVERTER_STORE', 'ftp'),

        /*
         * For "disk": the folder the FTP site serves, WITHOUT the site's own folder on the end. The
         * archive says that part itself (FtpSites.FtpServerFolder, normally "DOI") and it is appended
         * here, so both drivers derive the path the same way and a change of site folder in the
         * archive is followed by both.
         *
         * So for files that live at F:\Archive_papyrus\Data\FTP\DOI\2026\07\..., this is
         * F:\Archive_papyrus\Data\FTP.
         */
        'root' => env('CONVERTER_STORE_ROOT'),
    ],

    'ftp' => [

        // Seconds for the connect, and for every later operation. The previous pipeline set neither,
        // inherited a 100 second default, and lost 4382 contents to listing timeouts.
        'connect_timeout' => (int) env('CONVERTER_FTP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('CONVERTER_FTP_TIMEOUT', 30),

        // The longest a single upload or download may take. It is a separate setting because a 40 MB
        // PDF cannot be transferred inside the 30 seconds an ftp_nlist is allowed; ArchiveFtpClient
        // raises anything below "timeout" back up to it.
        'transfer_timeout' => (int) env('CONVERTER_FTP_TRANSFER_TIMEOUT', 10 * (int) env('CONVERTER_FTP_TIMEOUT', 30)),

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

        // The size ThumbLayer rows are written at. 120x160 is what the archive's viewer lays its grid
        // out for and what the existing ~4 KB thumbnails are, so these are settable but should not be
        // changed: a taller thumbnail is not re-generated for the rows that already exist, so the
        // viewer would show two different sizes side by side.
        'width' => (int) env('CONVERTER_THUMBNAIL_WIDTH', 120),
        'height' => (int) env('CONVERTER_THUMBNAIL_HEIGHT', 160),
        'quality' => (int) env('CONVERTER_THUMBNAIL_QUALITY', 82),
    ],

    'workspace' => [

        // Where a content's PDF and rendered pages live while it is being converted. Every content
        // gets its own folder, which is deleted once its pages are stored: nothing accumulates.
        'root' => env('CONVERTER_WORKSPACE', 'C:\\pdfToImg\\work'),

        // Gigabytes that must stay free on that drive; below this the pipeline pauses instead of
        // filling the disk (the previous pipeline lost 206 contents that way).
        'free_space_floor_gb' => (int) env('CONVERTER_FREE_SPACE_FLOOR_GB', 20),

        // Minutes a folder that belongs to no conversion being converted may sit there before
        // converters:sweep deletes it. A crash mid-conversion is what leaves one behind, and the
        // delay is what keeps the sweep off a folder a worker has only just opened.
        'sweep_after_minutes' => (int) env('CONVERTER_SWEEP_AFTER_MINUTES', 60),
    ],

    'failure' => [

        // Attempts per content before it is left failed for a person to look at.
        'max_attempts' => (int) env('CONVERTER_MAX_ATTEMPTS', 3),

        // A content whose worker stopped sending a heartbeat for this long is cleaned up and queued
        // again. This replaces the scripts that used to rebuild interrupted conversions by hand.
        //
        // It has to stay clear of the longest step a healthy conversion runs without a heartbeat -
        // rendering one PDF, up to converter.render.timeout - or the reconciler takes contents off
        // workers that are still converting them. converters:preflight checks the two against each
        // other; 90 minutes is three times the shipped render timeout.
        'stale_after_minutes' => (int) env('CONVERTER_STALE_AFTER_MINUTES', 90),
    ],

];
