<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A real FTP server for the tests: it listens on 127.0.0.1, speaks enough of the protocol for
 * ext-ftp (USER, PASS, PWD, TYPE, PASV, NLST, RETR, STOR, SIZE, MKD, DELE, CWD, QUIT) and serves a
 * temporary folder. Mocking ext-ftp is not possible, and the failures this client exists to survive
 * - a listing that never answers, a transfer that stops half way - only happen on a socket.
 *
 * The server runs in its own PHP process (this same file, started with the options file as its
 * argument), because a single test process cannot both accept a connection and be the client.
 */
final class FtpStubServer
{
    public const USERNAME = 'archive';

    public const PASSWORD = 'pa55word-of-the-archive';

    private readonly string $directory;

    /** @var array{stall: list<string>, fail: array<string, array{times: int, reply: string}>, drop: array<string, int>, reject_login: bool, nlist: string, truncate_retr: int|null, truncate_retr_complete: bool, truncate_stor: int|null, trickle_retr: array{bytes: int, pause: int}|null} */
    private array $options = [
        'stall' => [],
        'fail' => [],
        'drop' => [],
        'reject_login' => false,
        'nlist' => 'bare',
        'truncate_retr' => null,
        'truncate_retr_complete' => false,
        'truncate_stor' => null,
        'trickle_retr' => null,
    ];

    private ?int $port = null;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ftp-stub-'.bin2hex(random_bytes(6));
        mkdir($this->root(), recursive: true);
    }

    /** The folder the server serves; the site folder of the tests lives inside it. */
    public function root(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'root';
    }

    public function port(): int
    {
        return $this->port ?? throw new RuntimeException('The stub FTP server has not been started.');
    }

    /** Never answer $command, so that a client without a timeout waits forever. */
    public function stall(string $command): self
    {
        $this->options['stall'][] = strtoupper($command);

        return $this;
    }

    /**
     * Answer $command with $reply the next $times it arrives, then serve it normally. The count
     * survives a reconnect, so a client that retries on a new session still sees the failure.
     */
    public function failTimes(string $command, int $times, string $reply): self
    {
        $this->options['fail'][strtoupper($command)] = ['times' => $times, 'reply' => $reply];

        return $this;
    }

    /** Drop the control connection on $command the next $times: the session a server closes under a client. */
    public function dropTimes(string $command, int $times): self
    {
        $this->options['drop'][strtoupper($command)] = $times;

        return $this;
    }

    /**
     * Refuse every login, quoting the password back the way a chatty server does, so that a client
     * which puts the reply in its message would leak it.
     */
    public function rejectLogins(): self
    {
        $this->options['reject_login'] = true;

        return $this;
    }

    /** How NLST names its entries - "bare", "full" or "windows"; servers do all three. */
    public function nlistStyle(string $style): self
    {
        $this->options['nlist'] = $style;

        return $this;
    }

    /**
     * Send only $bytes of a download. Without $announceComplete the server then stops answering,
     * which is a transfer that dies half way; with it the server closes the data connection and
     * says "226 transfer complete" anyway, which is what a proxy or a read error on the server's
     * own disk looks like and what ext-ftp reports as a successful download.
     */
    public function truncateDownloads(int $bytes, bool $announceComplete = false): self
    {
        $this->options['truncate_retr'] = $bytes;
        $this->options['truncate_retr_complete'] = $announceComplete;

        return $this;
    }

    /**
     * Send a download $bytes at a time with $pause seconds in between: the server that never runs
     * into a read timeout because it always answers, just not with the file.
     */
    public function trickleDownloads(int $bytes, int $pause): self
    {
        $this->options['trickle_retr'] = ['bytes' => $bytes, 'pause' => $pause];

        return $this;
    }

    /** Keep only $bytes of an upload, so that the size the server reports afterwards is wrong. */
    public function truncateUploads(int $bytes): self
    {
        $this->options['truncate_stor'] = $bytes;

        return $this;
    }

    public function write(string $path, string $contents): string
    {
        $file = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $folder = dirname($file);
        is_dir($folder) || mkdir($folder, recursive: true);
        file_put_contents($file, $contents);

        return $file;
    }

    public function contents(string $path): string|false
    {
        return @file_get_contents($this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
    }

    /**
     * The command lines the server received, so a test can check the exact paths the client sent.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        $log = @file_get_contents($this->directory.DIRECTORY_SEPARATOR.'commands.log');

        return $log === false ? [] : array_values(array_filter(explode("\n", trim($log))));
    }

    public function start(): self
    {
        $options = $this->options + ['root' => $this->root(), 'log' => $this->directory.DIRECTORY_SEPARATOR.'commands.log'];
        $file = $this->directory.DIRECTORY_SEPARATOR.'options.json';
        file_put_contents($file, json_encode($options, JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=0', __FILE__, $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The stub FTP server could not be started.');
        }

        $this->process = $process;
        $announcement = (string) fgets($this->pipes[1]);

        if (preg_match('/^PORT (\d+)/', $announcement, $matches) !== 1) {
            throw new RuntimeException('The stub FTP server did not announce a port: '.$announcement.stream_get_contents($this->pipes[2]));
        }

        $this->port = (int) $matches[1];

        return $this;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($this->process);
            $this->process = null;
        }

        File::deleteDirectory($this->directory);
    }

    /**
     * The server process: announces its port on stdout and then serves one control connection at a
     * time until the test stops it.
     */
    public static function main(string $optionsFile): void
    {
        /** @var array{root: string, log: string, stall: list<string>, fail: array<string, array{times: int, reply: string}>, nlist: string, truncate_retr: int|null, truncate_stor: int|null} $options */
        $options = json_decode((string) file_get_contents($optionsFile), true, flags: JSON_THROW_ON_ERROR);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($listener === false) {
            fwrite(STDERR, "listen failed: {$error}\n");
            exit(1);
        }

        fwrite(STDOUT, 'PORT '.self::portOf($listener)."\n");

        // A test that crashes must not leave a server behind; nothing here runs for minutes.
        $deadline = time() + 120;

        while (time() < $deadline) {
            $control = @stream_socket_accept($listener, 5);

            if (is_resource($control)) {
                self::session($control, $options);
            }
        }
    }

    /**
     * @param  resource  $control
     * @param  array<string, mixed>  $options
     */
    private static function session($control, array &$options): void
    {
        self::send($control, '220 stub FTP ready');
        $data = null;
        $user = '';
        $loggedIn = false;

        // The RFC default, so that a client which forgets to ask for binary mode mangles nothing
        // here but is refused outright.
        $type = 'A';

        while (($line = fgets($control)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$command, $argument] = array_pad(explode(' ', $line, 2), 2, '');
            $command = strtoupper($command);
            $path = $options['root'].DIRECTORY_SEPARATOR.trim(str_replace('/', DIRECTORY_SEPARATOR, $argument), DIRECTORY_SEPARATOR);
            file_put_contents($options['log'], ($command === 'PASS' ? 'PASS ***' : $line)."\n", FILE_APPEND);

            if (in_array($command, $options['stall'], true)) {
                sleep(20);

                break;
            }

            if (($options['drop'][$command] ?? 0) > 0) {
                $options['drop'][$command]--;

                break;
            }

            if (($options['fail'][$command]['times'] ?? 0) > 0) {
                $options['fail'][$command]['times']--;
                self::send($control, $options['fail'][$command]['reply']);

                continue;
            }

            // Nothing but the login itself is served to a session that has not logged in, so that a
            // client which skips the login cannot pass a test.
            if (! $loggedIn && ! in_array($command, ['USER', 'PASS', 'QUIT', 'NOOP'], true)) {
                self::send($control, '530 Please login with USER and PASS');

                continue;
            }

            // A data connection is used once. A server that let a client transfer twice on one PASV
            // would hide a client that never asks for passive mode again.
            if (in_array($command, ['NLST', 'RETR', 'STOR'], true)) {
                if (! is_resource($data)) {
                    self::send($control, '425 Use PASV first');

                    continue;
                }

                $expected = $command === 'NLST' ? 'A' : 'I';

                if ($type !== $expected) {
                    self::send($control, "451 {$command} needs TYPE {$expected}, the session is in {$type}");

                    continue;
                }
            }

            switch ($command) {
                case 'USER':
                    $user = $argument;
                    self::send($control, '331 Password required');
                    break;
                case 'PASS':
                    $loggedIn = $options['reject_login'] !== true && $user === self::USERNAME && $argument === self::PASSWORD;
                    self::send($control, match (true) {
                        $options['reject_login'] === true => '530 Login incorrect for PASS '.$argument,
                        $loggedIn => '230 Logged in',
                        default => '530 Login incorrect',
                    });
                    break;
                case 'TYPE':
                    $type = strtoupper(trim($argument));
                    self::send($control, in_array($type, ['A', 'I'], true) ? '200 Type set' : '504 Type not supported');
                    break;
                case 'PORT':
                case 'EPRT':
                case 'EPSV':
                    // The archive's site is reached through a firewall that allows passive mode
                    // only; a client that falls back to active mode gets no transfer at all.
                    self::send($control, "500 {$command} is not supported, use PASV");
                    break;
                case 'NOOP':
                    self::send($control, '200 Still here');
                    break;
                case 'PWD':
                    self::send($control, '257 "/" is the current directory');
                    break;
                case 'CWD':
                    self::send($control, is_dir($path) ? '250 Directory changed' : '550 No such directory');
                    break;
                case 'PASV':
                    is_resource($data) && fclose($data);
                    $data = stream_socket_server('tcp://127.0.0.1:0');
                    $port = self::portOf($data);
                    self::send($control, sprintf('227 Entering Passive Mode (127,0,0,1,%d,%d)', intdiv($port, 256), $port % 256));
                    break;
                case 'SIZE':
                    self::send($control, is_file($path) ? '213 '.filesize($path) : '550 Not found');
                    break;
                case 'MKD':
                    self::send($control, match (true) {
                        is_dir($path) => '550 Directory exists',
                        ! is_dir(dirname($path)) => '550 No such parent directory',
                        default => mkdir($path) ? '257 "'.$argument.'" created' : '550 Cannot create',
                    });
                    break;
                case 'DELE':
                    self::send($control, is_file($path) && unlink($path) ? '250 Deleted' : '550 Not found');
                    break;
                case 'NLST':
                    self::listing($control, self::take($data), $path, $argument, $options);
                    break;
                case 'RETR':
                    if (! is_file($path)) {
                        self::send($control, '550 Not found');
                        break;
                    }
                    $transfer = self::accept($control, self::take($data));
                    $contents = (string) file_get_contents($path);

                    if ($options['trickle_retr'] !== null) {
                        foreach (str_split($contents, max(1, $options['trickle_retr']['bytes'])) as $chunk) {
                            if (fwrite($transfer, $chunk) === false) {
                                break;
                            }

                            sleep($options['trickle_retr']['pause']);
                        }

                        fclose($transfer);
                        self::send($control, '226 Transfer complete');
                        break;
                    }

                    if ($options['truncate_retr'] !== null) {
                        fwrite($transfer, substr($contents, 0, $options['truncate_retr']));
                        fclose($transfer);

                        if ($options['truncate_retr_complete'] !== true) {
                            sleep(20);

                            break 2;
                        }

                        self::send($control, '226 Transfer complete');
                        break;
                    }

                    fwrite($transfer, $contents);
                    fclose($transfer);
                    self::send($control, '226 Transfer complete');
                    break;
                case 'STOR':
                    if (! is_dir(dirname($path))) {
                        self::send($control, '550 No such directory');
                        break;
                    }
                    $transfer = self::accept($control, self::take($data));
                    $contents = (string) stream_get_contents($transfer);
                    fclose($transfer);
                    file_put_contents($path, $options['truncate_stor'] === null ? $contents : substr($contents, 0, $options['truncate_stor']));
                    self::send($control, '226 Transfer complete');
                    break;
                case 'QUIT':
                    self::send($control, '221 Bye');
                    break 2;
                default:
                    self::send($control, '500 Unknown command');
            }
        }

        is_resource($data) && fclose($data);
        fclose($control);
    }

    /**
     * Hands out the data listener PASV opened and forgets it, so the next transfer has to ask for
     * its own.
     *
     * @param  resource|null  $data
     * @return resource
     */
    private static function take(&$data)
    {
        $listener = $data;
        $data = null;

        return $listener;
    }

    /**
     * @param  resource  $control
     * @param  resource|null  $listener
     * @param  array<string, mixed>  $options
     */
    private static function listing($control, $listener, string $path, string $argument, array $options): void
    {
        if (! is_dir($path)) {
            is_resource($listener) && fclose($listener);
            self::send($control, '550 No such directory');

            return;
        }

        $folder = trim(str_replace('\\', '/', $argument), '/');
        $entries = array_map(fn (string $entry): string => match ($options['nlist']) {
            'full' => '/'.$folder.'/'.$entry,
            'windows' => '\\'.str_replace('/', '\\', $folder).'\\'.$entry,
            default => $entry,
        }, array_values(array_diff((array) scandir($path), ['.', '..'])));

        // Servers that list "." and ".." exist; the client has to drop them.
        $entries[] = '.';
        $entries[] = '..';

        $transfer = self::accept($control, $listener);
        fwrite($transfer, implode("\r\n", $entries)."\r\n");
        fclose($transfer);
        self::send($control, '226 Transfer complete');
    }

    /**
     * @param  resource  $control
     * @param  resource|null  $listener
     * @return resource
     */
    private static function accept($control, $listener)
    {
        self::send($control, '150 Opening data connection');
        $transfer = stream_socket_accept($listener, 10);
        fclose($listener);

        return $transfer ?: throw new RuntimeException('no data connection');
    }

    /**
     * @param  resource  $control
     */
    private static function send($control, string $reply): void
    {
        fwrite($control, $reply."\r\n");
    }

    /**
     * @param  resource  $socket
     */
    private static function portOf($socket): int
    {
        $name = (string) stream_socket_get_name($socket, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}

if (PHP_SAPI === 'cli' && isset($GLOBALS['argv'][1]) && realpath($GLOBALS['argv'][0]) === __FILE__) {
    FtpStubServer::main($GLOBALS['argv'][1]);
}
