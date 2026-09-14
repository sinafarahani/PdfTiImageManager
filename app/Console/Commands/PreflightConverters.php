<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\DiscoveredContent;
use App\Actions\Converter\Archive\FtpSite;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\ArchiveFtpClient;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Pipeline\ContentWorkspace;
use App\Actions\Converter\Pipeline\ConversionOverview;
use App\Actions\Converter\Render\Thumbnailer;
use Carbon\CarbonImmutable;
use Closure;
use FTP\Connection as FtpConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Imagick;
use PDO;
use Throwable;

/**
 * The command the operator runs on the server after editing .env and before starting anything.
 *
 * Deployment is "copy the project, edit .env": nothing else may need touching. This is what proves
 * that the one file was edited correctly, on the machine that will do the work, against the real
 * archive and the real FTP site - every check prints one line, and the exit code is 1 when a required
 * one failed, so it can gate a deployment.
 *
 * It is read-only against the archive and it calls none of the archive's stored procedures. The two
 * things it cannot prove by reading - that a multi-megabyte blob survives the driver and that
 * "INSERT ... OUTPUT inserted.ID" hands back a generated id - are proven against a temporary table in
 * its own session, so a preflight can be run against production at any time without changing a row.
 *
 * Every value it reports comes from the configuration, which is where .env arrives: a check that read
 * a hard-coded path would prove the deployment correct while the pipeline used a different one.
 */
class PreflightConverters extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:preflight';

    /**
     * @var string
     */
    protected $description = 'Check PHP, the archive, FTP, pdf2img and the workspace before the converters are started';

    /**
     * The PHP the panel itself requires (composer.json: "php": "^8.4.1").
     */
    private const string MINIMUM_PHP = '8.4.1';

    /**
     * Bytes of memory a worker wants. A 300 dpi A4 page is about 35 MB decoded and a content is a
     * whole document of them, so a worker below this converts the big files by failing them.
     */
    private const int MEMORY_FLOOR_BYTES = 536_870_912;

    /**
     * Bytes sent through varbinary(max) to prove the driver does not re-encode them. A page image at
     * 300 dpi is one to three megabytes, so this is the real size, not a token.
     */
    private const int BLOB_PROBE_BYTES = 4_194_304;

    /**
     * The page the thumbnail is measured on: A4 at 300 dpi, which is what the default "-r 300"
     * produces. Measuring a small image would report a time and a memory figure that mean nothing.
     */
    private const int PAGE_WIDTH = 2480;

    private const int PAGE_HEIGHT = 3508;

    /**
     * Seconds pdf2img gets to answer -version. It prints one line and exits, so a binary that needs
     * longer than this is a binary that cannot be reached (a network share, a virus scanner).
     */
    private const int VERSION_TIMEOUT = 20;

    /**
     * The library pdf2img renders with, under each platform's name for it.
     *
     * @var list<string>
     */
    private const array PDFIUM_LIBRARIES = ['pdfium.dll', 'libpdfium.so', 'libpdfium.dylib'];

    /**
     * Required checks that failed, and advisory ones. Only the first decides the exit code.
     */
    private int $failures = 0;

    private int $warnings = 0;

    /**
     * Values that must never reach the output, whatever a driver or a talkative FTP server puts in
     * its message: the FTP password and the archive's database password. Scrubbing centrally, in the
     * one method that writes a line, is what makes that a property of the command rather than a
     * promise every check has to remember.
     *
     * @var list<string>
     */
    private array $secrets = [];

    /**
     * The archive's current FTP site, once it has been read, so the FTP checks do not ask again.
     */
    private ?FtpSite $site = null;

    public function handle(ArchiveGateway $archive, ConversionOverview $overview): int
    {
        $this->keepSecret((string) config('database.connections.'.$this->archiveConnectionName().'.password'));

        $this->line(sprintf(
            'Preflight of %s (%s) on %s at %s',
            (string) config('app.name'),
            (string) config('app.env'),
            gethostname() ?: 'this machine',
            now()->toDateTimeString(),
        ));

        $this->checkPhp();
        $this->checkPanelDatabase($overview);
        $this->checkQueue();

        $connection = $this->checkArchiveConnection();
        $this->checkArchiveReads($archive);
        $this->checkDriverFeatures($connection);
        $this->checkClocksAgree($connection);
        $this->checkWriteMode();

        $this->checkFileStore();
        $this->checkRenderer();
        $this->checkThumbnailer();
        $this->checkWorkspace();

        return $this->summarise();
    }

    /**
     * PHP itself: the interpreter that will run the workers, not the one on the developer's machine.
     */
    private function checkPhp(): void
    {
        $this->heading('PHP');

        $build = sprintf(
            'PHP %s, %s, %s',
            PHP_VERSION,
            PHP_INT_SIZE === 8 ? 'x64' : 'x86',
            ZEND_THREAD_SAFE ? 'thread safe (ZTS)' : 'not thread safe (NTS)',
        );

        version_compare(PHP_VERSION, self::MINIMUM_PHP, '>=')
            ? $this->passed($build)
            : $this->failed($build, 'the panel needs PHP '.self::MINIMUM_PHP.' or newer; point the web server and the artisan commands at it');

        $this->line('        binary: '.PHP_BINARY);

        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            $this->passed('memory_limit is -1 (no limit)');
        } elseif ($limit >= self::MEMORY_FLOOR_BYTES) {
            $this->passed('memory_limit is '.ini_get('memory_limit'));
        } else {
            $this->warned(
                'memory_limit is '.ini_get('memory_limit').', below the '.$this->megabytes(self::MEMORY_FLOOR_BYTES).' a worker wants',
                'raise memory_limit in the php.ini this PHP uses ('.(php_ini_loaded_file() ?: 'none loaded').'); a 300 dpi A4 page costs about 35 MB decoded',
            );
        }

        $this->checkExtensions();
    }

    /**
     * The extensions the pipeline needs. pdo_sqlsrv is required exactly when the archive connection
     * is a SQL Server one - a machine pointed at another driver (a dev box, the pipeline's tests) does
     * not need it, and reporting it as missing there would teach the operator to ignore this line.
     */
    private function checkExtensions(): void
    {
        /** @var array<string, string> $required */
        $required = [
            'ftp' => 'downloading the PDFs and uploading the page images',
            'gd' => 'the thumbnail fallback and the page checks',
            'fileinfo' => 'recognising what was downloaded',
            'mbstring' => 'the archive\'s Persian file names',
        ];

        foreach ($required as $extension => $purpose) {
            extension_loaded($extension)
                ? $this->passed("extension {$extension} is loaded ({$purpose})")
                : $this->failed("extension {$extension} is missing ({$purpose})", "enable extension={$extension} in php.ini and restart the panel");
        }

        $driver = $this->archiveDriverName();

        if (extension_loaded('pdo_sqlsrv')) {
            $this->passed('extension pdo_sqlsrv is loaded (the archive database)');
        } elseif ($driver === 'sqlsrv') {
            $this->failed(
                'extension pdo_sqlsrv is missing, and the archive connection "'.$this->archiveConnectionName().'" is a SQL Server one',
                'install pdo_sqlsrv and the Microsoft ODBC driver, then enable extension=pdo_sqlsrv in php.ini',
            );
        } else {
            $this->warned(
                'extension pdo_sqlsrv is missing; the archive connection "'.$this->archiveConnectionName().'" uses the '.($driver ?? 'unknown').' driver, so it is not needed here',
                'the live archive is SQL Server: set ARCHIVE_CONNECTION=archive and install pdo_sqlsrv before converting for real',
            );
        }

        // Not required, and deliberately not treated as a detail: without Imagick every thumbnail is
        // made by GD, which decodes the whole page instead of letting libjpeg shrink it while it
        // reads, and the archive's 140 million thumbnails were not made that way.
        extension_loaded('imagick')
            ? $this->passed('extension imagick is loaded (thumbnails)')
            : $this->warned(
                'extension imagick is missing: thumbnails will be made by GD instead',
                'install imagick for faster, lower-memory thumbnails; GD works, but it decodes every page in full',
            );
    }

    /**
     * The panel's own database: the work queue, the page ledger and the watermark live here, not in
     * the archive.
     */
    private function checkPanelDatabase(ConversionOverview $overview): void
    {
        $this->heading('The panel\'s own database');

        try {
            $connection = DB::connection();
            $connection->getPdo();

            $this->passed(sprintf(
                'connection "%s" (%s) is open: %s',
                $connection->getName() ?? 'default',
                $connection->getDriverName(),
                $this->describeTarget($connection),
            ));
        } catch (Throwable $exception) {
            $this->failed(
                'the panel\'s database cannot be reached: '.$exception->getMessage(),
                'check DB_CONNECTION and the DB_* values in .env',
            );

            return;
        }

        $tables = ['conversions', 'conversion_pages', 'conversion_watermarks'];
        $missing = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            $this->failed(
                'the conversion tables are missing: '.implode(', ', $missing),
                'run "php artisan migrate --force"',
            );

            return;
        }

        $this->passed('the tables '.implode(', ', $tables).' exist');

        $counts = $overview->counts();

        $this->passed(sprintf(
            'the queue holds %d waiting, %d being converted, %d done, %d failed',
            $counts['waiting'],
            $counts['converting'],
            $counts['done'],
            $counts['failed'],
        ));
    }

    /**
     * The transport the conversion jobs travel on. A connection or a queue name that does not resolve
     * is the failure that otherwise looks like "Start does nothing".
     */
    private function checkQueue(): void
    {
        $this->heading('The conversion queue');

        $connection = (string) config('converter.queue.connection');
        $queue = (string) config('converter.queue.name');

        try {
            $depth = Queue::connection($connection)->size($queue);

            $this->passed(sprintf('queue "%s" on the "%s" connection holds %d job(s)', $queue, $connection, $depth));
        } catch (Throwable $exception) {
            $this->failed(
                sprintf('queue "%s" on the "%s" connection cannot be read: %s', $queue, $connection, $exception->getMessage()),
                'check CONVERTER_QUEUE_CONNECTION and CONVERTER_QUEUE in .env; a database queue also needs "php artisan migrate --force"',
            );

            return;
        }

        $workers = (int) config('converter.queue.workers');
        $perWorker = (int) config('converter.queue.queued_per_worker');

        $this->passed(sprintf(
            '%d worker(s) will run, %d content(s) queued per worker (at most %d in flight)',
            $workers,
            $perWorker,
            max(1, $workers) * max(1, $perWorker),
        ));

        $this->checkStalenessWindow();
    }

    /**
     * Whether a healthy conversion can outlive the window the reconciler judges a worker by.
     *
     * The pipeline reports a heartbeat before every step that can run for minutes, so the longest
     * silence a working conversion produces is its longest single step: the download of one PDF
     * (converter.ftp.transfer_timeout, retried converter.ftp.attempts times) or the render of it
     * (converter.render.timeout). If converter.failure.stale_after_minutes is inside that, the
     * reconciler reclaims contents that are still being converted - it deletes the page rows of an
     * attempt that is mid-flight and hands the content to a second worker - which is the one failure
     * this whole ledger exists to prevent. The numbers are shipped with a 2x margin; anything less
     * is worth saying out loud, because a claimed row also waits in the queue before a worker picks
     * it up, and that wait counts towards the same window.
     */
    private function checkStalenessWindow(): void
    {
        $attempts = max(1, (int) config('converter.ftp.attempts'));
        $transfer = max((int) config('converter.ftp.timeout'), (int) config('converter.ftp.transfer_timeout'));
        $download = $attempts * $transfer + ($attempts - 1) * max(1, (int) config('converter.ftp.retry_seconds'));
        $render = (int) config('converter.render.timeout');

        $longest = max($download, $render);
        $stale = max(0, (int) config('converter.failure.stale_after_minutes')) * 60;

        $message = sprintf(
            'the longest step without a heartbeat is %d minute(s) (a download of %ds or a render of %ds) against a %d minute staleness window',
            (int) ceil($longest / 60),
            $download,
            $render,
            (int) ($stale / 60),
        );

        if ($stale <= $longest) {
            $this->failed(
                $message,
                'raise CONVERTER_STALE_AFTER_MINUTES above it, or lower CONVERTER_RENDER_TIMEOUT / CONVERTER_FTP_TRANSFER_TIMEOUT: as it stands, converters:reconcile can take a content off a worker that is still converting it',
            );

            return;
        }

        if ($stale < $longest * 2) {
            $this->warned(
                $message,
                'a claimed content also waits in the queue before a worker starts on it, and that wait counts towards the same window; twice the longest step is the margin these defaults are chosen for',
            );

            return;
        }

        $this->passed($message);
    }

    /**
     * The archive connection, and what answered on it.
     */
    private function checkArchiveConnection(): ?Connection
    {
        $this->heading('The archive database');

        $name = $this->archiveConnectionName();

        try {
            $connection = DB::connection($name);
            $connection->getPdo();
        } catch (Throwable $exception) {
            $this->failed(
                sprintf('the archive connection "%s" cannot be opened: %s', $name, $exception->getMessage()),
                'check ARCHIVE_DB_HOST, ARCHIVE_DB_DATABASE, ARCHIVE_DB_USERNAME and ARCHIVE_DB_PASSWORD in .env, and that this machine may reach the server',
            );

            return null;
        }

        $this->passed(sprintf(
            'the archive connection "%s" (%s) is open: %s',
            $name,
            $connection->getDriverName(),
            $this->describeTarget($connection),
        ));

        $this->reportServerVersion($connection);

        return $connection;
    }

    /**
     * What the server says it is. SQL Server answers @@VERSION with a banner of several lines; its
     * first line names the edition and the build, which is the line an operator needs.
     */
    private function reportServerVersion(Connection $connection): void
    {
        try {
            /** @var object{version?: string}|null $row */
            $row = $connection->selectOne('SELECT @@VERSION AS version');
            $banner = trim((string) ($row->version ?? ''));

            if ($banner !== '') {
                $this->passed('@@VERSION: '.trim((string) strtok($banner, "\r\n")));

                return;
            }

            $this->warned('@@VERSION came back empty', 'ask the archive\'s administrator which server this is');
        } catch (Throwable $exception) {
            // Not a failure on its own: a machine whose archive connection points at another driver
            // (the pipeline's tests do) still has a server that can be named, and the read checks
            // below are what really decide whether this is the archive.
            $this->warned(
                'the connection does not answer SELECT @@VERSION ('.$exception->getMessage().'); it reports itself as '.$this->serverVersion($connection),
                'point ARCHIVE_CONNECTION at the SQL Server archive before converting for real',
            );
        }
    }

    /**
     * Our own read queries, through the gateway the pipeline uses - not a hand-written SELECT that
     * would prove the network and nothing else. What comes back is printed, because the derived FTP
     * folder of a real source file is the only thing that proves the path derivation against reality.
     */
    private function checkArchiveReads(ArchiveGateway $archive): void
    {
        $this->heading('The archive, read through the pipeline\'s own queries');

        $content = $this->firstContent('discover(null, 1)', fn (): array => $archive->discover(null, 1));
        $legacy = $this->firstContent('seedFromLegacyQueue(1, 0)', fn (): array => $archive->seedFromLegacyQueue(1, 0));

        try {
            $this->site = $archive->currentFileSite();

            $this->keepSecret($this->site->password());

            // FtpSite::__toString leaves the password out by design; the folder is the archive's own
            // nchar(60) column, trimmed by the gateway.
            $this->passed(sprintf('currentFileSite(): FtpSites row %d, %s', $this->site->id, (string) $this->site));
        } catch (Throwable $exception) {
            $this->failed(
                'currentFileSite() failed: '.$exception->getMessage(),
                'the archive needs one FtpSites row with FtpType = "file" and CurrentFtp = 1',
            );
        }

        $content ??= $legacy;

        if ($content === null) {
            $this->warned(
                'neither query returned a content, so the per-content reads were not exercised',
                'this is what an archive with nothing left to convert looks like; if that is unexpected, check GeneralContent.Reserved and RenderMediaId',
            );

            return;
        }

        $this->reportContent($archive, $content);
    }

    /**
     * Runs one of the discovery reads and returns the first content it found. $call is the call as it
     * is written in the gateway, because that is what the operator will grep the code for.
     *
     * @param  Closure(): list<DiscoveredContent>  $query
     */
    private function firstContent(string $call, Closure $query): ?DiscoveredContent
    {
        try {
            $found = $query();

            $this->passed(sprintf(
                '%s returned %d content(s)%s',
                $call,
                count($found),
                $found === [] ? '' : ': '.$found[0]->contentId,
            ));

            return $found[0] ?? null;
        } catch (Throwable $exception) {
            $this->failed(
                sprintf('%s failed: %s', $call, $exception->getMessage()),
                'the account must be able to read GeneralContent, MVDContent, DMDProfile, FtpSites and PdfConvert in the archive database',
            );

            return null;
        }
    }

    /**
     * Everything the pipeline asks about one content, and the FTP path it would derive from it.
     */
    private function reportContent(ArchiveGateway $archive, DiscoveredContent $content): void
    {
        try {
            $profileId = $archive->profileIdFor($content->contentId);
            $storeMode = $profileId === null ? null : $archive->storeModeFor($profileId);

            $this->passed(sprintf(
                'content %s: profile %s, store mode %s',
                $content->contentId,
                $profileId === null ? 'none' : (string) $profileId,
                $storeMode === null ? 'unknown (no profile)' : $storeMode->value.($storeMode->storesImageInDatabase() ? ' (the image goes into ImageLayer)' : ' (the image goes to FTP)'),
            ));
        } catch (Throwable $exception) {
            $this->failed(
                'profileIdFor()/storeModeFor() failed: '.$exception->getMessage(),
                'the account must be able to read GeneralContent and DMDProfile',
            );
        }

        try {
            $sources = $archive->sourceFilesFor($content->contentId);
            $pdfs = array_values(array_filter($sources, fn (SourceFile $file): bool => $file->isPdf()));

            $this->passed(sprintf(
                'sourceFilesFor() returned %d row(s), %d of them PDF',
                count($sources),
                count($pdfs),
            ));

            if ($pdfs !== []) {
                $pdf = $pdfs[0];
                $folder = ($this->site?->folder ?? 'the site folder').'/'.$pdf->remoteFolder();

                // The whole point of printing this: the folder is derived from the row's own
                // CreateDateTime, and only a real row proves the derivation still matches the archive.
                $this->passed(sprintf(
                    'its PDF "%s" is CreateDateTime %s, so the pipeline would fetch %s/%s',
                    $pdf->pageNo,
                    $pdf->createDateTime,
                    $folder,
                    $pdf->remoteFileName(),
                ));
            }
        } catch (Throwable $exception) {
            $this->failed(
                'sourceFilesFor() failed: '.$exception->getMessage(),
                'the account must be able to read MVDContent',
            );
        }

        try {
            $pages = $archive->imagePagesFor($content->contentId);

            $this->passed(sprintf(
                'imagePagesFor() returned %d page row(s) from an earlier attempt',
                count($pages),
            ));
        } catch (Throwable $exception) {
            $this->failed(
                'imagePagesFor() failed: '.$exception->getMessage(),
                'the account must be able to read MVDContent',
            );
        }
    }

    /**
     * The two driver behaviours a page write depends on, proven without touching an archive table:
     * a blob survives varbinary(max) at its own size, and an INSERT hands back the id the database
     * generated. Both have failed silently in the past - a thumbnail bound as nvarchar is stored
     * re-encoded, and without OUTPUT there is no way to learn the MVDContent.ID a page image must be
     * uploaded under.
     */
    /**
     * Whether this machine's clock and the archive's agree.
     *
     * One now() becomes both MVDContent.CreateDateTime and the folder the page image is uploaded to,
     * and the archive's other tools read that timestamp back. A panel running in the wrong timezone
     * therefore files every page under a folder hours away from where the rest of the archive puts
     * them - nothing errors, the pages simply are not where anyone looks for them.
     */
    private function checkClocksAgree(?Connection $connection): void
    {
        $this->heading('The clock the page timestamps come from');

        $this->passed(sprintf('APP_TIMEZONE is %s, so a page written now would be filed under %s',
            (string) config('app.timezone'),
            SourceFile::folderFor(now()->format('Y-m-d H:i:s')),
        ));

        if ($connection === null) {
            $this->warned('not compared with the archive: its connection is not open', 'fix the connection above and run the preflight again');

            return;
        }

        try {
            $archiveNow = CarbonImmutable::parse((string) $connection->scalar('SELECT GETDATE()'));
        } catch (Throwable $exception) {
            $this->warned('the archive would not say what time it is: '.$exception->getMessage());

            return;
        }

        $drift = abs($archiveNow->diffInSeconds(now()));
        $message = sprintf(
            'the archive says %s, this machine says %s (%d second(s) apart)',
            $archiveNow->format('Y-m-d H:i:s'),
            now()->format('Y-m-d H:i:s'),
            $drift,
        );

        if ($drift > 3600) {
            $this->failed($message, 'set APP_TIMEZONE to the archive server\'s own timezone: an hour or more of difference means every page image is filed in the wrong folder');

            return;
        }

        if ($drift > 120) {
            $this->warned($message, 'the two clocks should agree to the minute, because the same timestamp names the row and its folder');

            return;
        }

        $this->passed($message);
    }

    private function checkDriverFeatures(?Connection $connection): void
    {
        $this->heading('The driver features the page writes depend on');

        if ($connection === null) {
            $this->warned('not checked: the archive connection is not open', 'fix the connection above and run the preflight again');

            return;
        }

        if ($connection->getDriverName() !== 'sqlsrv') {
            $this->warned(
                'not checked: the archive connection uses the '.$connection->getDriverName().' driver, and these are SQL Server behaviours',
                'run the preflight again on the server, with ARCHIVE_CONNECTION pointing at the SQL Server archive',
            );

            return;
        }

        $this->checkBlobRoundTrip($connection);
        $this->checkOutputInsertedId($connection);
    }

    /**
     * A few megabytes through varbinary(max) and back as a length. pdo_sqlsrv sends an untyped PHP
     * string as nvarchar, which stores a JPEG re-encoded and unreadable, so the size that comes back
     * is the whole answer: it either equals what was sent or the driver changed the bytes.
     */
    private function checkBlobRoundTrip(Connection $connection): void
    {
        $blob = random_bytes(self::BLOB_PROBE_BYTES);

        try {
            $statement = $connection->getPdo()->prepare('SELECT DATALENGTH(CAST(? AS varbinary(max))) AS bytes');

            if (defined('PDO::SQLSRV_ENCODING_BINARY')) {
                $statement->bindParam(1, $blob, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
            } else {
                $statement->bindParam(1, $blob, PDO::PARAM_LOB);
            }

            $statement->execute();
            $stored = (int) $statement->fetchColumn();

            $stored === self::BLOB_PROBE_BYTES
                ? $this->passed(sprintf('varbinary(max) round trip: %s arrived unchanged', $this->megabytes(self::BLOB_PROBE_BYTES)))
                : $this->failed(
                    sprintf('varbinary(max) round trip: %d bytes were sent and the server measured %d', self::BLOB_PROBE_BYTES, $stored),
                    'the driver is re-encoding the parameter; a thumbnail stored this way cannot be displayed. Check the pdo_sqlsrv and ODBC driver versions',
                );
        } catch (Throwable $exception) {
            $this->failed(
                'varbinary(max) round trip failed: '.$exception->getMessage(),
                'ThumbLayer.thumb and ImageLayer.Pic are varbinary(max); without this no page can be written',
            );
        }
    }

    /**
     * "INSERT ... OUTPUT inserted.ID", the way SqlServerArchive learns the MVDContent.ID the archive
     * generated for a page. A temporary table of our own carries it, so the 140 million row table is
     * not touched: what is being proven is the driver and the statement form, not the table.
     *
     * The table is created and written in one direct query on purpose. A temporary table created
     * inside a prepared statement lives in that statement's own scope on SQL Server and is gone
     * before the next statement runs, which would fail this check on a server where the pipeline
     * works perfectly. The table is dropped afterwards rather than in the same batch, so that the
     * OUTPUT row set is read before anything else touches it.
     */
    private function checkOutputInsertedId(Connection $connection): void
    {
        $batch = <<<'SQL'
            SET NOCOUNT ON;
            CREATE TABLE #preflight (ID uniqueidentifier NOT NULL DEFAULT newsequentialid(), SeqPageNo int NULL);
            INSERT INTO #preflight (SeqPageNo) OUTPUT inserted.ID VALUES (1);
            SQL;

        try {
            $statement = $connection->getPdo()->query($batch);

            if ($statement === false) {
                $this->failed(
                    'INSERT ... OUTPUT inserted.ID could not be sent',
                    'the driver refused the batch without saying why; check the pdo_sqlsrv installation',
                );

                return;
            }

            $id = '';

            // The CREATE produces no rows, and drivers disagree about whether that is a result set at
            // all, so every set is looked at until the OUTPUT one turns up.
            do {
                try {
                    $id = trim((string) $statement->fetchColumn());
                } catch (Throwable) {
                    $id = '';
                }
            } while ($id === '' && $statement->nextRowset());

            $id !== ''
                ? $this->passed('INSERT ... OUTPUT inserted.ID returned a generated id: '.$id)
                : $this->failed(
                    'INSERT ... OUTPUT inserted.ID returned nothing',
                    'without it a page row cannot be written, because the id the image is uploaded under is the one the database generates',
                );
        } catch (Throwable $exception) {
            $this->failed(
                'INSERT ... OUTPUT inserted.ID failed: '.$exception->getMessage(),
                'the account must be allowed to create a temporary table (#preflight), and the driver must return the OUTPUT row set',
            );
        } finally {
            try {
                // The session would drop it anyway when the command ends; done here so that a pooled
                // connection is left exactly as it was found.
                $connection->getPdo()->exec("IF OBJECT_ID('tempdb..#preflight') IS NOT NULL DROP TABLE #preflight;");
            } catch (Throwable) {
                // A temporary table that outlives its session is not a thing; nothing to report.
            }
        }
    }

    /**
     * Whether this deployment may write to the archive at all. Stated plainly either way: an operator
     * who starts the converters with the gate shut watches a pipeline that reads, renders and reports
     * and changes nothing, which looks exactly like a broken installation.
     */
    private function checkWriteMode(): void
    {
        $this->heading('Write mode');

        $mode = (string) config('converter.archive.write_mode');

        if (in_array(strtolower(trim($mode)), ['on', 'true', '1', 'yes', 'enabled'], true)) {
            $this->passed(sprintf('CONVERTER_WRITE_MODE is "%s": the archive IS writable and converted contents will be changed', $mode));

            return;
        }

        $this->warned(
            sprintf('CONVERTER_WRITE_MODE is "%s": every write to the archive will be refused (a dry run)', $mode),
            'set CONVERTER_WRITE_MODE=on in .env when you mean to convert for real',
        );
    }

    /**
     * The file store, which on the server is the archive's FTP site. The session is opened here with
     * ext-ftp directly, because the home directory and the passive-mode handshake are what an
     * operator needs to see and neither is part of the FileStore interface the pipeline uses.
     */
    /**
     * The folder the disk driver reads: CONVERTER_STORE_ROOT with the archive's own site folder on
     * the end, which is how the binding builds it.
     */
    private function diskRoot(): string
    {
        $root = rtrim(trim((string) config('converter.store.root')), '\\/');
        $folder = $this->site instanceof FtpSite ? trim($this->site->folder) : '';

        return $folder === '' ? $root : $root.DIRECTORY_SEPARATOR.$folder;
    }

    /**
     * That the disk really is the archive's, rather than a folder that merely exists.
     *
     * Getting this wrong is not a slow conversion, it is a silent one: every source resolves to a
     * path that is not there, and a source that is not there is the one verdict the pipeline never
     * retries - it marks the content beyond help and discovery never offers it again.
     */
    private function checkDiskRoot(): void
    {
        $root = $this->diskRoot();

        if (! is_dir($root)) {
            $this->failed(
                "the disk root {$root} is not a folder on this machine",
                'CONVERTER_STORE_ROOT must be the folder the FTP site serves, WITHOUT the site folder on the end - the archive supplies that',
            );

            return;
        }

        $this->passed("the disk root {$root} is there");

        if (! is_readable($root)) {
            $this->failed(
                "the disk root {$root} cannot be read by this account",
                'the service runs as a Windows account of its own; give it read access to the archive share',
            );

            return;
        }

        if (! is_writable($root)) {
            // Reading is the source PDFs; writing is every page image the conversion produces.
            $this->failed(
                "the disk root {$root} cannot be written to by this account",
                'the page images are written back into these folders, so read access alone is not enough',
            );
        }
    }

    private function checkFileStore(): void
    {
        $onDisk = strtolower(trim((string) config('converter.store.driver'))) === 'disk';

        $this->heading($onDisk ? 'The file store (disk)' : 'The file store (FTP)');

        try {
            $store = $this->laravel->make(FileStore::class);
        } catch (Throwable $exception) {
            $this->failed(
                'the file store could not be built: '.$exception->getMessage(),
                $onDisk
                    ? 'CONVERTER_STORE is "disk", so CONVERTER_STORE_ROOT plus the archive\'s site folder must be a folder on this machine'
                    : 'it is built from the archive\'s current FtpSites row, so fix the archive checks above first',
            );

            return;
        }

        // Only the real client can be asked about a session; the site it was built from is the one
        // read above, so the two cannot disagree.
        $site = $store instanceof ArchiveFtpClient ? $this->site : null;

        if ($site instanceof FtpSite) {
            $this->openFtpSession($site);
        } elseif ($onDisk) {
            $this->checkDiskRoot();
        } else {
            $this->warned(
                'the bound file store is '.$store::class.', not the archive\'s FTP site',
                'that is a development setup; set CONVERTER_STORE=disk if this machine holds the archive, or leave it as "ftp" on a server that does not',
            );
        }

        // The store's own root, which on the FTP site is the folder every path the pipeline builds
        // hangs off.
        $folder = match (true) {
            $site instanceof FtpSite => 'the site folder '.$site->folder,
            $onDisk => 'the disk root '.$this->diskRoot(),
            default => 'the root of '.$store::class,
        };

        try {
            $entries = $store->list('');

            $this->passed(sprintf(
                'listing %s returned %d entr%s',
                $folder,
                count($entries),
                count($entries) === 1 ? 'y' : 'ies',
            ));
        } catch (Throwable $exception) {
            $this->failed(
                'listing '.$folder.' failed: '.$exception->getMessage(),
                'check FtpSites.FtpServerFolder and that the login may list it',
            );
        }
    }

    /**
     * Connect, log in, passive mode, home directory. The password is only ever handed to ftp_login;
     * warnings are suppressed so that a server which quotes the login back cannot put it in the log,
     * and the line writer scrubs it as well.
     */
    private function openFtpSession(FtpSite $site): void
    {
        if (! extension_loaded('ftp')) {
            $this->failed('the FTP session cannot be opened: extension ftp is missing', 'enable extension=ftp in php.ini');

            return;
        }

        $connectTimeout = max(1, (int) config('converter.ftp.connect_timeout'));
        $timeout = max(1, (int) config('converter.ftp.timeout'));
        $connection = @ftp_connect($site->host, $site->port, $connectTimeout);

        if (! $connection instanceof FtpConnection) {
            $this->failed(
                sprintf('FTP connect to %s:%d failed within %ds', $site->host, $site->port, $connectTimeout),
                'check FtpSites.FtpServer/FtpPort, the network and the firewall',
            );

            return;
        }

        $this->passed(sprintf('connected to %s:%d within %ds', $site->host, $site->port, $connectTimeout));

        try {
            @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);

            if (@ftp_login($connection, $site->username, $site->password()) !== true) {
                $this->failed(
                    sprintf('FTP login as %s on %s failed', $site->username, $site->host),
                    'check FtpSites.FtpUsername/FtpPassword; a few more wrong attempts lock the account out',
                );

                return;
            }

            $this->passed('logged in as '.$site->username);

            @ftp_pasv($connection, true) === true
                ? $this->passed('passive mode accepted')
                : $this->failed(
                    'passive mode was refused',
                    'the pipeline only transfers passively; open the server\'s passive port range in the firewall',
                );

            $home = @ftp_pwd($connection);

            is_string($home) && $home !== ''
                ? $this->passed('home directory: '.$home)
                : $this->warned('the server did not answer PWD', 'the site folder is joined to an absolute path, so this is informational');
        } finally {
            @ftp_close($connection);
        }
    }

    /**
     * pdf2img: it is where it is configured to be, it runs, and the library it renders with is next
     * to it.
     */
    private function checkRenderer(): void
    {
        $this->heading('pdf2img');

        $binary = trim((string) config('converter.render.binary'));

        if ($binary === '') {
            $this->failed('converter.render.binary is empty', 'set CONVERTER_PDF2IMG in .env to the full path of pdf2img.exe');

            return;
        }

        if (! File::isFile($binary)) {
            $this->failed(
                'pdf2img is not at '.$binary,
                'copy the pdf2img release there, or point CONVERTER_PDF2IMG at where it is',
            );

            return;
        }

        $this->passed(sprintf('pdf2img is at %s (%s)', $binary, $this->megabytes((int) File::size($binary))));

        if (! is_executable($binary)) {
            $this->warned(
                'the file is not marked executable',
                'on Linux run "chmod +x '.$binary.'"; on Windows check that the account may run it',
            );
        }

        $this->runVersion($binary);
        $this->checkPdfium($binary);

        $this->passed(sprintf(
            'arguments "%s", %ds per PDF',
            (string) config('converter.render.arguments'),
            (int) config('converter.render.timeout'),
        ));
    }

    /**
     * Runs the binary. -version is the cheapest proof that this file is a program this machine can
     * actually execute: the wrong architecture, a missing runtime or a blocked download all fail here
     * rather than on the first content.
     */
    private function runVersion(string $binary): void
    {
        try {
            $result = Process::timeout(self::VERSION_TIMEOUT)->run([$binary, '-version']);
        } catch (Throwable $exception) {
            $this->failed(
                'pdf2img could not be run: '.$exception->getMessage(),
                'check that the account may run it and that its runtime libraries are installed',
            );

            return;
        }

        $answer = trim((string) strtok(trim($result->output()."\n".$result->errorOutput()), "\r\n"));

        if ($result->successful() && $answer !== '') {
            $this->passed('it answers -version: '.$answer);

            return;
        }

        if ($answer !== '') {
            // The old VeryPDF tool had no -version and printed its usage with exit 1. The binary
            // runs, which is what this check is for, but it is not the pdf2img this pipeline expects.
            $this->warned(
                sprintf('it ran but exited %s for -version: %s', $result->exitCode() ?? 'without a code', $answer),
                'this may be the old VeryPDF converter; install pdf2img, whose exit codes the pipeline reads',
            );

            return;
        }

        $this->failed(
            sprintf('it exited %s for -version and printed nothing', $result->exitCode() ?? 'without a code'),
            'run "'.$binary.' -version" by hand on this machine to see what it says',
        );
    }

    private function checkPdfium(string $binary): void
    {
        $directory = dirname($binary);

        foreach (self::PDFIUM_LIBRARIES as $library) {
            $path = $directory.DIRECTORY_SEPARATOR.$library;

            if (File::isFile($path)) {
                $this->passed(sprintf('%s sits next to it (%s)', $library, $this->megabytes((int) File::size($path))));

                return;
            }
        }

        // Not a hard failure: the library may be resolved from the PATH. It is still the single most
        // common reason every render fails at once, which is why it says what that looks like.
        $this->warned(
            'no pdfium library ('.implode(', ', self::PDFIUM_LIBRARIES).') next to pdf2img in '.$directory,
            'copy pdfium.dll from the pdf2img release next to the executable; without it every render ends with exit 9',
        );
    }

    /**
     * The thumbnail, measured on a page the size the renderer really produces. Every archive page has
     * a ThumbLayer row, so this step runs once per page of every content: what it costs in time and
     * memory is a capacity figure, not a detail.
     */
    private function checkThumbnailer(): void
    {
        $this->heading('Thumbnails');

        $page = null;

        try {
            $page = $this->pageImage();

            if ($page === null) {
                $this->failed(
                    'no page image could be generated: neither gd nor imagick is loaded',
                    'enable extension=gd (and preferably imagick) in php.ini',
                );

                return;
            }

            $thumbnailer = $this->laravel->make(Thumbnailer::class);

            // Reset first, so the figure is what the thumbnail costs rather than the high-water mark
            // the page we just generated left behind. Without it the line would report the same number
            // on a machine with Imagick and on one falling back to GD, which is the difference the
            // operator is being shown.
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }

            $baseline = memory_get_usage(true);
            $started = microtime(true);
            $thumbnail = $thumbnailer->make($page);
            $elapsed = (microtime(true) - $started) * 1000;
            $peak = memory_get_peak_usage(true);

            $size = @getimagesizefromstring($thumbnail);
            $width = (int) config('converter.thumbnail.width');
            $height = (int) config('converter.thumbnail.height');

            if ($thumbnail === '' || $size === false) {
                $this->failed(
                    $thumbnailer::class.' returned '.($thumbnail === '' ? 'nothing' : 'something that is not an image'),
                    'check the log; a page without a thumbnail is refused by the archive',
                );

                return;
            }

            $this->passed(sprintf(
                '%s turned a %dx%d page into a %dx%d JPEG of %d bytes in %.0f ms',
                $thumbnailer::class,
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $size[0],
                $size[1],
                strlen($thumbnail),
                $elapsed,
            ));

            $limit = $this->memoryLimitBytes();

            $this->passed(sprintf(
                'peak memory %s while it ran, of which %s was already in use; the limit is %s',
                $this->megabytes($peak),
                $this->megabytes($baseline),
                $limit === null ? 'unlimited' : $this->megabytes($limit),
            ));

            if ($size[0] !== $width || $size[1] !== $height) {
                $this->warned(
                    sprintf('the thumbnail is %dx%d, not the archive\'s %dx%d', $size[0], $size[1], $width, $height),
                    'the archive\'s 140 million thumbnails are squeezed into an exact box; check converter.thumbnail',
                );
            }
        } catch (Throwable $exception) {
            $this->failed('the thumbnail could not be made: '.$exception->getMessage(), 'check the log and the imagick/gd installation');
        } finally {
            if ($page !== null) {
                File::delete($page);
            }
        }
    }

    /**
     * A page-sized JPEG in the system's temporary folder, with some black on it so that it compresses
     * like a scan rather than like a blank sheet. Null when the machine cannot make an image at all.
     */
    private function pageImage(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'preflight-page-');

        if ($path === false) {
            return null;
        }

        if (extension_loaded('gd')) {
            $image = imagecreatetruecolor(self::PAGE_WIDTH, self::PAGE_HEIGHT);
            imagefilledrectangle($image, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, (int) imagecolorallocate($image, 255, 255, 255));
            $ink = (int) imagecolorallocate($image, 20, 20, 20);

            for ($line = 0; $line < 60; $line++) {
                $top = 200 + $line * 50;
                imagefilledrectangle($image, 200, $top, self::PAGE_WIDTH - 200, $top + 18, $ink);
            }

            imagejpeg($image, $path, 90);
            imagedestroy($image);

            return $path;
        }

        if (extension_loaded('imagick')) {
            $imagick = new Imagick;
            $imagick->newPseudoImage(self::PAGE_WIDTH, self::PAGE_HEIGHT, 'gradient:white-black');
            $imagick->setImageFormat('jpeg');
            $imagick->writeImage($path);
            $imagick->clear();

            return $path;
        }

        File::delete($path);

        return null;
    }

    /**
     * The workspace folder and the drive it is on. The old pipeline only cleaned up in a branch that
     * could not run, filled the staging drive and lost 206 contents to it, which is why the floor is
     * a setting and why it is checked before anything starts.
     */
    private function checkWorkspace(): void
    {
        $this->heading('The workspace');

        $workspace = $this->laravel->make(ContentWorkspace::class);
        $root = $workspace->root();

        try {
            File::ensureDirectoryExists($root);
        } catch (Throwable $exception) {
            $this->failed(
                'the workspace '.$root.' cannot be created: '.$exception->getMessage(),
                'point CONVERTER_WORKSPACE at a folder this account may create and write in',
            );

            return;
        }

        if (! File::isDirectory($root)) {
            $this->failed(
                'the workspace '.$root.' does not exist and cannot be created',
                'point CONVERTER_WORKSPACE at a folder this account may create and write in',
            );

            return;
        }

        $probe = $root.DIRECTORY_SEPARATOR.'.preflight';

        if (@file_put_contents($probe, 'preflight') === false) {
            $this->failed(
                'the workspace '.$root.' cannot be written to',
                'give the account that runs the workers write access to it, or point CONVERTER_WORKSPACE elsewhere',
            );

            return;
        }

        File::delete($probe);

        $this->passed('the workspace '.$root.' exists and can be written to');

        $floor = (int) config('converter.workspace.free_space_floor_gb');
        $free = $workspace->freeSpaceGb();

        if ($free === null) {
            $this->warned(
                sprintf('the free space of %s cannot be read; the floor of %d GB cannot be enforced', $root, $floor),
                'a local drive reports it; a network path does not, and the pipeline will convert without the safety net',
            );

            return;
        }

        $free >= $floor
            ? $this->passed(sprintf('%.1f GB free, above the %d GB floor', $free, $floor))
            : $this->failed(
                sprintf('%.1f GB free, below the %d GB floor', $free, $floor),
                'free some space, or lower CONVERTER_FREE_SPACE_FLOOR_GB; below the floor every conversion refuses to start',
            );
    }

    /**
     * The verdict. The exit code is the whole point of the command: it gates a deployment, so only a
     * required check counts and a warning never does.
     */
    private function summarise(): int
    {
        $this->newLine();

        if ($this->failures === 0) {
            $this->info(sprintf(
                'Every required check passed%s. The converters can be started.',
                $this->warnings === 0 ? '' : sprintf(' (%d warning(s) above)', $this->warnings),
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d required check(s) failed and %d warning(s) were printed. Fix the failures before starting the converters.',
            $this->failures,
            $this->warnings,
        ));

        return self::FAILURE;
    }

    private function heading(string $title): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$title.'</>');
    }

    private function passed(string $message): void
    {
        $this->writeCheck('<fg=green>PASS</>', $message);
    }

    private function warned(string $message, string $advice = ''): void
    {
        $this->warnings++;

        $this->writeCheck('<fg=yellow>WARN</>', $message, $advice);
    }

    private function failed(string $message, string $advice = ''): void
    {
        $this->failures++;

        $this->writeCheck('<fg=red>FAIL</>', $message, $advice);
    }

    /**
     * The one place a line is written, and therefore the one place the secrets are taken out of it.
     */
    private function writeCheck(string $marker, string $message, string $advice = ''): void
    {
        $this->line('  '.$marker.'  '.$this->scrub($message));

        if ($advice !== '') {
            $this->line('        -> '.$this->scrub($advice));
        }
    }

    private function keepSecret(string $secret): void
    {
        if (trim($secret) !== '' && ! in_array($secret, $this->secrets, true)) {
            $this->secrets[] = $secret;
        }
    }

    private function scrub(string $message): string
    {
        return $this->secrets === [] ? $message : str_replace($this->secrets, '***', $message);
    }

    private function archiveConnectionName(): string
    {
        return (string) config('converter.archive.connection');
    }

    /**
     * The archive connection's driver from the configuration, without opening it.
     */
    private function archiveDriverName(): ?string
    {
        $driver = config('database.connections.'.$this->archiveConnectionName().'.driver');

        return is_string($driver) ? $driver : null;
    }

    /**
     * Where a connection points, from its own configuration - never its password.
     */
    private function describeTarget(Connection $connection): string
    {
        $host = (string) $connection->getConfig('host');
        $database = (string) ($connection->getConfig('database') ?: $connection->getDatabaseName());

        if ($host === '') {
            return $database === '' ? '(no database configured)' : $database;
        }

        return $host.':'.(string) $connection->getConfig('port').'/'.$database;
    }

    private function serverVersion(Connection $connection): string
    {
        try {
            return (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return 'a server that will not say';
        }
    }

    /**
     * The process' memory limit in bytes, or null when it has none.
     */
    private function memoryLimitBytes(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return null;
        }

        $bytes = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private function megabytes(int $bytes): string
    {
        return sprintf('%.1f MB', $bytes / 1024 ** 2);
    }
}
