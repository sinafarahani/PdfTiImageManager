<?php

namespace App\Actions\Converter\Ftp;

use App\Actions\Converter\Archive\FtpSite;
use Closure;
use FTP\Connection;
use Illuminate\Support\Sleep;

/**
 * The archive's FTP site over ext-ftp. The session is opened when it is first needed and reopened
 * when the server has closed it, so a worker that spent ten minutes rendering does not fail on its
 * first upload.
 *
 * Every operation carries a timeout and a small number of attempts: the previous pipeline set no
 * timeouts at all, inherited ext-ftp's 90 second default for reads and none at all for the connect,
 * and lost 4382 contents to listings that never came back.
 */
class ArchiveFtpClient implements FileStore
{
    private ?Connection $connection = null;

    /**
     * The login the server refused, if it did. Every later operation fails with it rather than
     * sending the same credentials again.
     */
    private ?FileStoreException $rejected = null;

    /**
     * Folders already created in this session, so that a content's pages, which all land in the
     * same folder, cost one round trip per segment instead of one per page.
     *
     * @var array<string, true>
     */
    private array $folders = [];

    /**
     * @param  array{connect_timeout?: int, timeout?: int, transfer_timeout?: int, attempts?: int, retry_seconds?: int}  $settings  config('converter.ftp')
     */
    public function __construct(
        private readonly FtpSite $site,
        private readonly array $settings = [],
    ) {}

    /**
     * @return list<string>
     */
    public function list(string $folder): array
    {
        $path = $this->remotePath($folder);

        return $this->attempt(function (Connection $connection) use ($path): array {
            [$entries, $reply, $elapsed] = $this->silently(fn (): array|false => ftp_nlist($connection, $path));

            if ($entries === false) {
                throw $this->listingFailure($connection, $path, $reply, $elapsed);
            }

            $names = [];

            foreach ($entries as $entry) {
                // Servers answer NLST with bare names, with the full path, or with a Windows path;
                // the caller gets names in every case.
                $name = basename(str_replace('\\', '/', trim($entry)));

                if ($name !== '' && $name !== '.' && $name !== '..') {
                    $names[] = $name;
                }
            }

            return $names;
        });
    }

    public function download(string $remotePath, string $localPath): int
    {
        $path = $this->remotePath($remotePath);

        return $this->attempt(function (Connection $connection) use ($path, $localPath): int {
            // A transfer that dies half way must not leave something behind that looks like a whole
            // PDF, so the bytes land beside the file and only a finished transfer is renamed.
            $partial = $localPath.'.part';
            $handle = @fopen($partial, 'wb');

            if ($handle === false) {
                throw FileStoreException::permanent("FTP download of {$path} cannot write {$partial}");
            }

            [$downloaded, $reply, $elapsed] = $this->silently(fn (): int => $this->transfer(
                $connection,
                fn (): int => ftp_nb_fget($connection, $handle, $path, FTP_BINARY),
            ));
            fclose($handle);

            if ($downloaded !== FTP_FINISHED) {
                @unlink($partial);

                throw $this->failure('download', $path, $reply, $connection, $elapsed);
            }

            clearstatcache(true, $partial);
            $bytes = (int) filesize($partial);

            // A server can close the data connection early and still answer "226 transfer
            // complete" - a proxy in the middle, or a read error on its own disk, does exactly
            // that - and ext-ftp reports success. The size the server reports settles it; a server
            // that reports none leaves nothing to compare and the transfer has to be taken as it is.
            [$expected] = $this->silently(fn (): int => ftp_size($connection, $path));

            if ($expected >= 0 && $expected !== $bytes) {
                @unlink($partial);

                throw FileStoreException::transient("FTP download of {$path} brought {$bytes} of {$expected} bytes");
            }

            // rename() does not replace an existing file on Windows.
            @unlink($localPath);

            if (! @rename($partial, $localPath)) {
                @unlink($partial);

                throw FileStoreException::permanent("FTP download of {$path} cannot be moved to {$localPath}");
            }

            return $bytes;
        });
    }

    public function upload(string $localPath, string $remotePath): int
    {
        $path = $this->remotePath($remotePath);
        clearstatcache(true, $localPath);
        $size = @filesize($localPath);

        if ($size === false) {
            throw FileStoreException::permanent("FTP upload to {$path} cannot read {$localPath}");
        }

        return $this->attempt(function (Connection $connection) use ($localPath, $path, $size): int {
            $this->makeFolders($connection, $path);
            $handle = @fopen($localPath, 'rb');

            if ($handle === false) {
                throw FileStoreException::permanent("FTP upload to {$path} cannot read {$localPath}");
            }

            [$uploaded, $reply, $elapsed] = $this->silently(fn (): int => $this->transfer(
                $connection,
                fn (): int => ftp_nb_fput($connection, $path, $handle, FTP_BINARY),
            ));
            fclose($handle);

            if ($uploaded !== FTP_FINISHED) {
                throw $this->failure('upload', $path, $reply, $connection, $elapsed);
            }

            [$stored] = $this->silently(fn (): int => ftp_size($connection, $path));

            if ($stored !== $size) {
                // The old pipeline trusted the transfer and left truncated images in the archive
                // for people to find years later. A short file means the transfer was cut, which
                // the next attempt overwrites. A server that will not answer SIZE at all cannot be
                // used unverified either, and says so in its own words so it is seen at once.
                throw FileStoreException::transient($stored < 0
                    ? "FTP upload to {$path} cannot be verified: the server reports no size for it"
                    : "FTP upload to {$path} stored {$stored} of {$size} bytes");
            }

            return $stored;
        });
    }

    public function size(string $remotePath): ?int
    {
        $path = $this->remotePath($remotePath);

        return $this->attempt(function (Connection $connection) use ($path): ?int {
            [$size, $reply, $elapsed] = $this->silently(fn (): int => ftp_size($connection, $path));

            if ($size >= 0) {
                return $size;
            }

            // ext-ftp answers -1 both for a file that is not there and for a session that has died.
            // A server that is still answering refused the command, which for SIZE means the file
            // is not there; a session that stopped answering has to be reported.
            if ($this->answered($connection, $elapsed)) {
                return null;
            }

            throw FileStoreException::transient($this->describe('size', $path, $reply));
        });
    }

    public function delete(string $remotePath): void
    {
        $path = $this->remotePath($remotePath);

        $this->attempt(function (Connection $connection) use ($path): null {
            [$deleted, $reply, $elapsed] = $this->silently(fn (): bool => ftp_delete($connection, $path));

            // A server that answers and still refuses is saying the file is not there, and a file
            // that is already gone is what the caller wanted.
            if ($deleted === true || $this->answered($connection, $elapsed)) {
                return null;
            }

            throw FileStoreException::transient($this->describe('delete', $path, $reply));
        });
    }

    public function disconnect(): void
    {
        if ($this->connection instanceof Connection) {
            $this->close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Runs $work against the session. A session the server closed while it was idle is reopened
     * once for free; a transient failure is repeated up to the configured number of attempts.
     *
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $work
     * @return TResult
     */
    private function attempt(Closure $work): mixed
    {
        $attempts = max(1, $this->setting('attempts', 3));
        $attempt = 1;
        $reconnected = false;

        while (true) {
            $reused = $this->connection instanceof Connection;

            try {
                return $work($this->connection());
            } catch (FileStoreException $exception) {
                if (! $exception->transient) {
                    throw $exception;
                }

                // Whatever went wrong, the session is not to be trusted afterwards: a half read
                // reply would derail the next command.
                $this->disconnect();

                // A session that sat idle through the rendering step is routinely closed by the
                // server. Opening a new one is not a retry and does not wait.
                if ($reused && ! $reconnected) {
                    $reconnected = true;

                    continue;
                }

                if (++$attempt > $attempts) {
                    throw $exception;
                }

                Sleep::for(max(1, $this->setting('retry_seconds', 5)))->seconds();
            }
        }
    }

    private function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        if ($this->rejected instanceof FileStoreException) {
            throw $this->rejected;
        }

        $server = "{$this->site->host}:{$this->site->port}";

        // The connect timeout is never allowed to reach zero: config casts an unset environment
        // variable to 0 with (int), and ftp_connect reads that as "wait as long as it takes", which
        // is the one thing this class exists to prevent.
        $connectTimeout = max(1, $this->setting('connect_timeout', 10));
        [$connection, $reply] = $this->silently(fn (): Connection|false => ftp_connect($this->site->host, $this->site->port, $connectTimeout));

        if (! $connection instanceof Connection) {
            throw FileStoreException::transient("FTP connect to {$server} failed within {$connectTimeout}s".$this->explain($reply));
        }

        // The timeout is set before the login on purpose: a server that accepts the connection and
        // then says nothing would otherwise hold the worker for ext-ftp's own default.
        [$timeoutSet] = $this->silently(fn (): bool => ftp_set_option($connection, FTP_TIMEOUT_SEC, $this->timeoutSeconds()));

        if ($timeoutSet !== true) {
            $this->close($connection);

            // Going on without it would leave every later call on ext-ftp's own 90 seconds, and a
            // misconfigured timeout is not something another attempt fixes.
            throw FileStoreException::permanent("FTP timeout of {$this->timeoutSeconds()}s cannot be set on {$server}");
        }

        [$loggedIn, $reply, $elapsed] = $this->silently(fn (): bool => ftp_login($connection, $this->site->username, $this->site->password()));

        if ($loggedIn !== true) {
            $this->close($connection);
            $message = "FTP login as {$this->site->username} on {$server} failed".$this->explain($reply);

            // A login the server answered is a credentials problem, and repeating it only locks the
            // account out; a login that ran out of time is the network and is worth another try.
            if ($this->timedOut($elapsed)) {
                throw FileStoreException::transient($message);
            }

            // The refusal is kept, so that the pages of a content do not each send the same wrong
            // password: the archive's site locks an account out after a handful of those, and then
            // nothing converts until someone unlocks it by hand.
            throw $this->rejected = FileStoreException::permanent($message);
        }

        [$passive] = $this->silently(fn (): bool => ftp_pasv($connection, true));

        if ($passive !== true) {
            $this->close($connection);

            // Letting this through would leave ext-ftp in active mode, where the server dials back
            // to the worker and a firewalled site answers no transfer at all.
            throw FileStoreException::transient("FTP passive mode on {$server} was refused");
        }

        $this->folders = [];

        return $this->connection = $connection;
    }

    /**
     * Runs a transfer in ext-ftp's chunked mode, watching a wall clock between the chunks.
     *
     * A session's timeout bounds a single read, not a transfer: a server that answers with one
     * chunk every few seconds never runs into it and holds the worker for as long as it likes,
     * which is how the previous pipeline came to sit on transfers nobody was waiting for any more.
     *
     * @param  Closure(): int  $start  ftp_nb_fget or ftp_nb_fput
     * @return int one of ext-ftp's FTP_FINISHED, FTP_FAILED, FTP_MOREDATA
     */
    private function transfer(Connection $connection, Closure $start): int
    {
        $deadline = microtime(true) + $this->transferSeconds();
        $state = $start();

        while ($state === FTP_MOREDATA) {
            if (microtime(true) >= $deadline) {
                return FTP_FAILED;
            }

            $state = ftp_nb_continue($connection);
        }

        return $state;
    }

    /**
     * Creates the folders of $path one segment at a time. FTP has no "create if missing", so every
     * segment is attempted and the "it is already there" reply ignored; a segment that genuinely
     * cannot be created turns up as the upload failing, which names the file.
     */
    private function makeFolders(Connection $connection, string $path): void
    {
        $segments = explode('/', trim($path, '/'));
        array_pop($segments);
        $folder = '';

        foreach ($segments as $segment) {
            $folder .= '/'.$segment;

            if (isset($this->folders[$folder])) {
                continue;
            }

            [, , $elapsed] = $this->silently(fn (): string|false => ftp_mkdir($connection, $folder));

            // A reply of any kind settles the folder. One that never came does not: remembering a
            // folder that was never created would make the upload fail with "no such directory",
            // which reads like a permanent failure and would lose the page.
            if (! $this->timedOut($elapsed)) {
                $this->folders[$folder] = true;
            }
        }
    }

    /**
     * Runs an ext-ftp call with PHP's warnings captured rather than leaked into the log, and hands
     * back what the server said (ext-ftp puts the reply in the warning, with its code stripped off)
     * and how long the call took.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return array{0: TResult, 1: string, 2: float}
     */
    private function silently(Closure $call): array
    {
        $reply = '';

        set_error_handler(function (int $severity, string $message) use (&$reply): bool {
            $reply = trim((string) preg_replace('/^\w+\(\):\s*/', '', $message));

            return true;
        });

        $started = microtime(true);

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return [$result, $reply, microtime(true) - $started];
    }

    private function failure(string $operation, string $path, string $reply, Connection $connection, float $elapsed): FileStoreException
    {
        $message = $this->describe($operation, $path, $reply);

        // Before anything is read out of the words: is the file even there? A server may refuse a
        // download with no reply code and nothing recognisable to match on - the archive's own answers
        // arrive as "failed: End" - and guessing wrong in that direction is expensive, because
        // thousands of its rows name files that are long gone and each was then retried three times
        // over, and three times again as a conversion. The folder says it plainly, in one command.
        if ($operation === 'download' && $this->listed($connection, $path) === false) {
            return FileStoreException::absent($this->describe($operation, $path, 'the file is not on the server'));
        }

        return $this->answered($connection, $elapsed) && $this->isFinal($reply)
            ? FileStoreException::permanent($message)
            : FileStoreException::transient($message);
    }

    /**
     * Whether the server lists the file in its own folder: true, false, or null when the listing
     * itself could not be had, which is not an answer about the file.
     */
    private function listed(Connection $connection, string $path): ?bool
    {
        $folder = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        $folder = $folder === '' ? '/' : $folder;

        [$entries] = $this->silently(fn (): array|false => ftp_nlist($connection, $folder));

        if (! is_array($entries)) {
            // A listing that failed is not an answer about the file - unless the folder itself is gone,
            // which the archive's rows also do: a whole day's folder is missing where their files used
            // to be. Nothing can be inside a folder that is not there, so that is an answer.
            return $this->folderExists($connection, $folder) === false ? false : null;
        }

        $name = strtolower(basename($path));

        foreach ($entries as $entry) {
            if (strtolower(basename(str_replace('\\', '/', (string) $entry))) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * A listing is the one operation ext-ftp refuses with a bare false and no message whatsoever,
     * so there is no reply to read at all. A raw CWD does keep its reply code, and that says which
     * of the two it was: a folder that is not there is final, anything else - the data channel, the
     * server's own limits - is worth another attempt. Every path this client sends is absolute, so
     * the working directory a successful CWD leaves behind changes nothing.
     */
    /**
     * Whether the server has that folder: true, false, or null when its answer said neither.
     *
     * A raw CWD is the one command whose reply code survives ext-ftp, which is what makes it readable
     * on a server whose refusals arrive with no code at all. Every path this client sends is absolute,
     * so the working directory a successful CWD leaves behind changes nothing.
     */
    private function folderExists(Connection $connection, string $folder): ?bool
    {
        [$answer] = $this->silently(fn (): mixed => ftp_raw($connection, "CWD {$folder}"));
        $code = is_array($answer) ? trim((string) reset($answer)) : '';

        if (str_starts_with($code, '55')) {
            return false;
        }

        return str_starts_with($code, '2') ? true : null;
    }

    private function listingFailure(Connection $connection, string $path, string $reply, float $elapsed): FileStoreException
    {
        $message = $this->describe('list', $path, $reply);

        if (! $this->answered($connection, $elapsed)) {
            return FileStoreException::transient($message);
        }

        [$answer] = $this->silently(fn (): mixed => ftp_raw($connection, "CWD {$path}"));
        $code = is_array($answer) ? trim((string) reset($answer)) : '';

        return str_starts_with($code, '55')
            ? FileStoreException::permanent($message.($reply === '' ? ': the folder is not there' : ''))
            : FileStoreException::transient($message);
    }

    private function describe(string $operation, string $path, string $reply): string
    {
        return "FTP {$operation} of {$path} on {$this->site->host}:{$this->site->port} failed".$this->explain($reply);
    }

    /**
     * Whether the server refused the command and is still there to be asked again, as opposed to a
     * session that ran into its timeout or stopped answering altogether.
     */
    private function answered(Connection $connection, float $elapsed): bool
    {
        if ($this->timedOut($elapsed)) {
            return false;
        }

        [$answer] = $this->silently(fn (): mixed => ftp_raw($connection, 'NOOP'));

        return is_array($answer) && str_starts_with(trim((string) reset($answer)), '2');
    }

    /**
     * Whether a reply describes something that a second attempt cannot change.
     *
     * ext-ftp hands the server's reply text to its warning but drops the reply code, so "450 file
     * busy" and "550 no such file" both arrive as a bare sentence and the one thing that would
     * settle it is gone. Only a reply that names a missing file or a right we do not have is taken
     * as final; everything else gets another attempt, because a needless retry costs one round trip
     * while a wrong "permanent" costs the whole content - the archive's disk filling up answers
     * "452 insufficient storage space", which reads like nothing in particular.
     */
    private function isFinal(string $reply): bool
    {
        // The reply code says it, and says it in every language the server might phrase the rest in:
        // FTP's 5xx is "do not ask again", 4xx is "try later" (RFC 959 §4.2). The archive has thousands
        // of rows pointing at files that are no longer on the site, and every one of those answers
        // 550: reading the words alone left them looking transient, which cost three FTP attempts and
        // then three conversion attempts each before the content was finally given up on.
        if (preg_match('/(?:^|\D)([45])\d\d(?:\D|$)/', $reply, $code) === 1) {
            return $code[1] === '5';
        }

        return preg_match(
            '/\b(no such|not found|does not exist|cannot find|no file|not a (?:plain )?file|permission|access denied|not allowed|forbidden|unknown command|not implemented|not understood|unsupported)\b/i',
            $reply,
        ) === 1;
    }

    /**
     * A call that lasted as long as the timeout hit it; ext-ftp reports the socket error of the
     * host operating system, in its language, so the text itself cannot be trusted for this.
     */
    private function timedOut(float $elapsed): bool
    {
        return $elapsed >= $this->timeoutSeconds() * 0.9;
    }

    private function timeoutSeconds(): int
    {
        return max(1, $this->setting('timeout', 30));
    }

    /**
     * The longest a single transfer may take. Never shorter than the session timeout, so that a
     * transfer given up on here always reads as a timeout rather than as a refusal by the server.
     */
    private function transferSeconds(): int
    {
        return max($this->timeoutSeconds(), $this->setting('transfer_timeout', $this->timeoutSeconds() * 10));
    }

    /**
     * The server's reply for a message, with the password taken out: a talkative server quotes the
     * login back, and a message ends up in the log and in the panel.
     */
    private function explain(string $reply): string
    {
        $password = $this->site->password();
        $reply = trim($password === '' ? $reply : str_replace($password, '***', $reply));

        return $reply === '' ? '' : ": {$reply}";
    }

    /**
     * The path on the server: the site folder in front of $path with the separators collapsed. The
     * old pipeline glued these together by hand and produced "DOI/2025/01/07//8DB1E6AE.jpg", and
     * the site folder itself arrives padded out of the archive's nchar(60) column.
     */
    private function remotePath(string $path): string
    {
        $segments = [];

        foreach (preg_split('~[\\\\/]+~', $this->site->folder.'/'.$path) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // A page written above the site folder is a page nothing looks for again. The
                // archive's own ids never contain "..", so this is a caller that built a path from
                // something it should not have.
                throw FileStoreException::permanent("The path {$path} leaves the site folder");
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function close(Connection $connection): void
    {
        // ftp_close says QUIT and waits for the reply, so closing the session of a server that has
        // just stopped answering would cost a second full timeout on top of the one already spent.
        $this->silently(fn (): bool => ftp_set_option($connection, FTP_TIMEOUT_SEC, 1));
        $this->silently(fn (): bool => ftp_close($connection));
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->settings[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
