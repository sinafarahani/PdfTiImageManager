# PDF to Image Manager

A Laravel panel that converts the archive's PDFs into page images, and lets you start and stop that work from one
page. It replaces the .NET worker (`e{n}.exe`) that used to do this: the whole pipeline now runs inside this
application, with `pdf2img` as the only external program.

## What it does

For every content the archive still needs converted:

1. Takes the content in the archive (`GeneralContent.Reserved`), so nothing else picks it up.
2. Downloads its PDF from the archive's FTP site, into a folder of its own.
3. Renders the pages with `pdf2img`.
4. Writes one page row per image (`MVDContent` + `ThumbLayer`, and `ImageLayer` for profiles that keep images in
   the database), uploads the images, and hides the source PDF.
5. Marks the content converted and deletes its folder.

A conversion is all or nothing. Every page row and every uploaded image is recorded in the panel's own database
*before* it is created, so a conversion that breaks half way is undone exactly — no leftover pages, no orphaned
files, and nothing to rebuild by hand.

- **Start** lets the dispatcher hand contents to the converters. It is instant: nothing is copied or launched.
- **Stop** stops taking on new contents. The ones being converted finish normally and are never interrupted, so
  you can press Start again straight away.
- The dashboard shows *Starting*, *Started*, *Stopping* and *Stopped*, read from the work itself, along with how
  much is waiting, how much was converted today, and what failed and at which step.
- The work queue keeps itself current: new documents are discovered on a schedule, and a conversion whose worker
  died is cleaned up and queued again.
- Only administrators (`users.is_admin`) can start and stop; other signed-in users see the status.
- The interface works without internet access: fonts and scripts are bundled.

## Requirements

On the server that will do the converting:

- Windows Server. The converter starts `pdf2img` as a child process, so it has to be the same machine the
  renderer runs on.
- PHP 8.4.1 or newer (the live server runs 8.5.1), with `pdo_sqlsrv`, `pdo_mysql`, `imagick`, `ftp`, `gd`,
  `fileinfo`, `mbstring` and `openssl`, and `memory_limit` of at least 512M — a 300 dpi page of an A3 scan is
  held in memory while its thumbnail is made.
- Microsoft ODBC Driver 17 or 18 for SQL Server, for `pdo_sqlsrv`.
- MySQL 8.0 or newer for the panel's own database. This is a hard requirement, not a preference: the work queue
  claims contents with `SELECT ... FOR UPDATE SKIP LOCKED`, which MySQL 5.7 rejects as a syntax error and which
  is what lets several workers claim at the same moment without taking the same content twice. MariaDB 10.6 or
  newer also has it.
- `pdf2img.exe` and `pdfium.dll` in a folder of their own.
- Network access to the archive's SQL Server, and to the FTP site the archive's own `FtpSites` table names. The
  FTP host and login are not configured here; they are read from that table, so the panel always uses the site
  the archive itself marks as current.
- No internet access. Nothing in the panel reaches out: the fonts and scripts are bundled.

Once, on a machine that does have internet, to prepare the copy:

- The same PHP major version, Composer, and Node.js 22.12 or newer.

## Installation

This is a first deployment, in the order it is done. **The only file you edit is `.env`.** Nothing under
`config/`, nothing under `app/`, and no folder you have to create by hand.

**1. Build the copy, on the machine with internet.**

```bat
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

**2. Copy it to the server.** The whole folder, including `vendor` and `public\build`. Leave out `node_modules`,
`.git`, the build machine's `.env` — a copied `.env` is how the wrong database gets written to — and anything the
build machine left in `bootstrap\cache` and `storage\framework\{cache,sessions,views}`: those are caches of the
build machine's own `.env`, and step 5 rebuilds them. (If you copied them anyway, `php artisan optimize:clear`
before step 5 is enough.)

**3. Create the panel's database.** It stays empty; the migration fills it.

```sql
CREATE DATABASE pdftoimgmanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Give the account you will put in `.env` full rights on it. This is not the archive; nothing of the panel's is
created in the archive's database.

**4. Write `.env`.**

```bat
copy .env.example .env
```

Every key in `.env.example` has a comment above it. These have to be filled in: `APP_URL`, `APP_TIMEZONE`, the
`DB_*` values for the database from step 3, the `ARCHIVE_DB_*` values for the archive, `CONVERTER_PDF2IMG`,
`CONVERTER_WORKSPACE`, and `ADMIN_EMAIL`. Leave `APP_ENV=production`, `APP_DEBUG=false`,
`CONVERTER_WRITE_MODE=off`, and `CACHE_STORE` / `SESSION_DRIVER` / `QUEUE_CONNECTION` on `database`.

Three things that are easy to get wrong and do not announce themselves:

- **`APP_TIMEZONE` is not a display setting.** The converter stamps `MVDContent.CreateDateTime` from it, and that
  same timestamp is the FTP folder the page images are stored in (`<site folder>/yyyy/MM/dd/HH/mm/ss/<id>.jpg`).
  Set it to the archive server's own timezone — the one `SELECT GETDATE()` returns there. Check it: the time
  `php artisan converters:preflight` prints in its first line must match the archive's `GETDATE()`. With the wrong
  zone the pages still convert, and then sit in a folder tree the archive's other tools never look in.
- **`CACHE_STORE` must stay `database`.** The cache is where Start and Stop live, and the panel, the dispatcher,
  the supervisor and every queue worker are separate processes that have to agree on it. The `file` store puts
  that state in `storage\framework\cache\data`, where it belongs to whichever Windows account wrote it — usually
  a different account from the one the scheduled task runs as — and then Start is written, never seen, and
  nothing is logged.
- **Windows paths.** Either unquoted (`C:\pdfToImg\work`) or quoted with every backslash doubled
  (`"C:\\pdfToImg\\work"`). A quoted path with single backslashes is an invalid escape sequence, and then *every*
  artisan command stops with `The environment file is invalid!` before it does anything.

**5. Prepare the application.**

```bat
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

`migrate` creates 16 tables: the conversion ledger (`conversions`, `conversion_pages`,
`conversion_watermarks`) and the framework's own — users, sessions, cache, cache locks, jobs and failed jobs.
`db:seed` creates the administrator and, if `ADMIN_PASSWORD` is empty, prints a random password once.
`php artisan optimize` caches the configuration, the routes, the views and the event map — `config:cache`,
`route:cache` and `view:cache` in one command.

> Re-run `php artisan optimize` after every later change to `.env`. A cached configuration is read instead of
> `.env`, so until you do, the change has no effect at all.

> The order inside step 5 matters in one place: `key:generate` **before** `optimize`. Livewire builds the address
> of the endpoint the Start and Stop buttons post to out of `APP_KEY`, so a route cache built under a different
> key leaves a dashboard that looks perfectly normal and whose buttons do nothing, with nothing in any log. If
> `APP_KEY` is ever changed again — a new key, a restored `.env` — run `php artisan optimize` once more.
> The test: the page's `livewire-<hash>/livewire.min.js` must load, not 404.

**6. Check the deployment.**

```bat
php artisan converters:preflight
```

One line per check: PHP and its extensions, the panel's database and queue, the archive connection, the archive
reads the pipeline makes, that a multi-megabyte thumbnail survives the driver, that `INSERT ... OUTPUT
inserted.ID` hands an id back, the write mode, the FTP site, `pdf2img` and `pdfium.dll`, the thumbnailer, and the
workspace folder and its free space. It exits 1 if a required check failed, and it writes nothing to the archive,
so it can be run against production at any time. Fix `.env`, run `php artisan optimize` again, run it again.

**7. Dry run, with `CONVERTER_WRITE_MODE=off`.**

```bat
php artisan converters:try <GeneralContent.ID>
```

Take a content id from the archive. The PDF is really downloaded from the FTP site and really rendered by
`pdf2img`; the archive writes and the uploads are then printed instead of performed — every `MVDContent` row,
every remote path, every byte count. Nothing is changed anywhere. This is the answer to "what will this do to my
archive", given by the code that would do it.

`converters:try` is the only dry run there is. Pressing **Start** while `CONVERTER_WRITE_MODE=off` is not a
harmless look: taking the content in the archive is itself a write, so every content fails at its first step, the
attempt is counted, and after `CONVERTER_MAX_ATTEMPTS` the content is left failed in the panel. Leave the panel
stopped until step 8 has been done and the write mode is `on`.

**8. One real content.** Set `CONVERTER_WRITE_MODE=on`, `php artisan optimize`, then:

```bat
php artisan converters:try <GeneralContent.ID> --write
```

Open that content in the archive's viewer and check the page order, the thumbnails, and that the images are on
FTP. If it is wrong, `php artisan converters:undo <id> --confirm` takes it back out: the page rows, their images,
the flag that hid the source PDF, and the verdict on the content. Only when it is right, set up the tasks below.

## Running

Three things run on the server.

- **The panel.** `php artisan serve --host=0.0.0.0 --port=8000`, with the project folder as the working
  directory. Note that PHP's built-in server handles one request at a time on Windows — `PHP_CLI_SERVER_WORKERS`
  needs `fork()` and does nothing there — and the dashboard polls every two seconds. That is fine for one or two
  people watching it. For more, put the project behind IIS or nginx with `public` as the document root.
- **The converter.** *One* Task Scheduler task, triggered **at startup**, running `php artisan converters:supervise`
  with the project folder as "Start in", "Run whether user is logged on or not", and as an account that can reach
  the archive and the FTP site. The supervisor keeps the dispatcher and the converter workers alive; the panel's
  Start and Stop decide whether workers are started at all, so this task is left running for good.
- **The scheduler.** A task running `php artisan schedule:run` every minute, same folder and account. It
  discovers new work every `CONVERTER_DISCOVERY_INTERVAL` minutes, puts interrupted conversions back every
  minute, deletes leftover workspace folders every hour, and prunes the failed-job records daily.

All three must be running; the converter is the one that does the work, and without it Start changes the panel's
state and nothing converts.

### As Windows services, with NSSM

An alternative to the two Task Scheduler entries, and easier to see the state of. Three services, one per
process above — `artisan schedule:work` replaces the every-minute `schedule:run` task, because it stays running
and fires the scheduled commands itself.

```bat
nssm install PdfToImgConverter C:\php\php.exe artisan converters:supervise
nssm set PdfToImgConverter AppDirectory F:\PdfTOImgManager
nssm set PdfToImgConverter ObjectName .\YourUser YourPassword
nssm set PdfToImgConverter DependOnService MySQL80
nssm set PdfToImgConverter AppStdout F:\PdfTOImgManager\storage\logs\converter.out.log
nssm set PdfToImgConverter AppRotateFiles 1
nssm set PdfToImgConverter AppRotateBytes 10485760
nssm set PdfToImgConverter AppThrottle 5000
```

The same three settings decide whether this works at all:

- **`AppDirectory`** must be the project folder. Artisan run from anywhere else fails immediately.
- **`ObjectName`** must be an account that can reach the archive, the FTP site and the workspace drive.
  LocalSystem cannot see a mapped drive at all, and often cannot reach the network either.
- **`DependOnService`** on the database, or on boot the converter starts first and spends its first minutes
  failing to read its own queue.

Use the full path to `php.exe`: a service does not inherit an interactive PATH. The system PATH still applies,
which is what lets `php_imagick.dll` find the ImageMagick libraries next to `php.exe`.

Only one supervisor can run. A second one exits with "another supervisor is already running this panel", which
it knows through the database cache — so `CACHE_STORE=database` matters here too.

**Stopping:** NSSM kills the process tree, so a worker in the middle of a conversion dies with it. Nothing is
lost — `converters:reconcile` cleans that content up and queues it again — but it waits out
`CONVERTER_STALE_AFTER_MINUTES` first. The clean order is: press **Stop** in the panel, wait until it says
*Stopped*, then stop the service. Starting is the reverse.

Everything can also be run by hand, which is what you want while setting up:

```bat
php artisan converters:preflight    :: check the deployment
php artisan converters:try <id>     :: one content, reporting instead of writing
php artisan converters:discover     :: fill the queue from the archive
php artisan converters:dispatch     :: hand contents out (runs until stopped)
php artisan queue:work --queue=conversions
php artisan converters:reconcile    :: put interrupted conversions back
php artisan converters:sweep        :: delete workspace folders nothing is converting
php artisan converters:retry --all  :: put failed conversions back in the queue
php artisan converters:undo <id>    :: take one content's pages back out of the archive
```

When a batch of contents fails because of the machine rather than the documents - the FTP site down, a
full drive, the archive restarted - the dashboard lists the last failures with the step each one failed
at, and `converters:retry` puts them back: `--all`, or `--stage=upload` for one step, or
`--content=<id>` for one content. It resets the attempt count, because the fault was not the
document's. Nothing else ever re-queues a failed conversion, which is deliberate: a PDF the renderer
refuses would otherwise be retried for ever.

The first `converters:discover` is the long one: with `CONVERTER_SEED_FROM_PDFCONVERT=true` it takes the contents
the old pipeline had already found from its `PdfConvert` table, which is far cheaper than scanning the 93 million
rows of `GeneralContent`. After that, each pass only looks at what is new.

For development, `composer run dev` starts the web server, a queue worker and Vite together.

## Configuration

Every setting comes from `.env`, and `.env.example` is the reference: every key the panel reads, grouped, with a
comment. The files under `config/` read `.env` and nothing else — there is no setting that can only be changed by
editing them. The ones usually changed on a first deployment:

| `.env` key | Default | Meaning |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Tehran` | The timezone `MVDContent.CreateDateTime` and the FTP folder are written in. Must match the archive server's clock |
| `DB_*` | MySQL on `127.0.0.1` | The panel's own database: users, the work queue, the record of every page written |
| `ARCHIVE_DB_*` | | The archive database on SQL Server |
| `CONVERTER_WRITE_MODE` | `off` | `on` allows writes to the archive; anything else is a dry run |
| `CONVERTER_WORKERS` | `4` | Contents converted at the same time (what the panel's Start uses) |
| `CONVERTER_PDF2IMG` | `C:\pdfToImg\bin\pdf2img.exe` | The renderer |
| `CONVERTER_PDF2IMG_ARGS` | `-r 300` | Arguments in front of the input and output paths |
| `CONVERTER_WORKSPACE` | `C:\pdfToImg\work` | Where a content is converted; its folder is deleted when its pages are stored |
| `CONVERTER_FREE_SPACE_FLOOR_GB` | `20` | Conversion pauses while the drive has less free than this |
| `CONVERTER_RENDER_TIMEOUT` | `1800` | Seconds one PDF may take before the worker is killed and the content retried |
| `CONVERTER_MAX_ATTEMPTS` | `3` | Attempts per content before it is left failed |
| `CONVERTER_STALE_AFTER_MINUTES` | `90` | A conversion silent for this long is cleaned up and queued again |
| `ALLOW_REGISTRATION` | `false` | Whether visitors may create their own (watch-only) account |
| `CONVERTER_DISCOVERY_INTERVAL` | `15` | Minutes between passes that look for new work |
| `CONVERTER_FTP_*` | 10 / 30 / 300 s | Connect, command and transfer timeouts, and the retries |
| `CACHE_STORE` | `database` | Where Start/Stop lives. Leave it |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@example.com` / random | Administrator created by `php artisan db:seed` |

## The old .NET workers

Nothing outside this project is used any more. The worker folders (`0`, `1`, …), the `e{n}.exe` binaries and
their `status.txt` and `share.txt` files are read by nothing and written by nothing: the whole pipeline runs
inside this application. The old queue table (`PdfConvert`) is still *read* once, by the first
`converters:discover`, to pick up the contents that were waiting when this version took over; it is never
written. If the old workers or their startup entries are still on the server, remove them — two pipelines
converting the same archive would each take contents the other had already reserved.

## Tests

The tests need the development dependencies (`composer install` without `--no-dev`):

```bat
php artisan test
```

They cover the whole pipeline without a SQL Server or an FTP server: the archive queries are pinned by tests that
assert the exact SQL, and the FTP client is tested against a small FTP server that runs inside the test suite.

On a deployed server, run `php artisan config:clear` first and `php artisan optimize` afterwards. A cached
configuration is read instead of `phpunit.xml`, so the tests would otherwise run against the panel's real
database instead of the in-memory one — the first sign of it is a test stopping on a question it cannot ask
(`BadMethodCallException … askQuestion()`).
