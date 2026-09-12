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

- Windows Server (the converter runs `pdf2img` as a child process)
- PHP 8.4.1 or newer with `pdo_sqlsrv` (Microsoft Drivers for PHP for SQL Server), `imagick`, `ftp`, `gd`,
  `fileinfo`, `mbstring`, `openssl` and `pdo_mysql`, and `memory_limit` of at least 512M
- Microsoft ODBC Driver 17 or 18 for SQL Server
- MySQL (the panel's own database: users, the work queue and the record of every page written)
- SQL Server access to the archive
- `pdf2img` and `pdfium.dll` in a folder of their own
- To build: Composer and Node.js 22.12 or newer, on a machine with internet access

## Installation

The server has no internet access, so dependencies are installed and the frontend built elsewhere.

1. On a machine with internet access and the same PHP version:

   ```bat
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```

2. Copy the project to the server, including `vendor` and `public\build` (`node_modules` is not needed).
3. On the server:

   ```bat
   copy .env.example .env
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   ```

   Edit `.env` first: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` values for MySQL, the
   `ARCHIVE_DB_*` values for the archive, `CONVERTER_PDF2IMG`, `CONVERTER_WORKSPACE`, and optionally
   `ADMIN_EMAIL` / `ADMIN_PASSWORD`. `db:seed` creates the administrator; without `ADMIN_PASSWORD` it prints a
   random password once.

   Leave `CONVERTER_WRITE_MODE=off` for the first run: everything works, but every write to the archive is
   refused, so you can watch a few contents go through before anything is changed.

## Running

- Web server: `php artisan serve --host=0.0.0.0 --port=8000` (set `PHP_CLI_SERVER_WORKERS=4` first, or it serves
  one request at a time), or nginx with `public` as the document root.
- The converter: **one** Task Scheduler task, triggered *at startup*, running `php artisan converters:supervise`
  with the project folder as "Start in", as a user account that can reach the archive and the FTP site.
  The supervisor keeps the dispatcher and the converter workers alive; the panel's Start and Stop control them.
- The scheduler: a task running `php artisan schedule:run` every minute. It discovers new work and puts
  interrupted conversions back.

Everything can also be run by hand, which is useful while setting up:

```bat
php artisan converters:discover      :: fill the queue from the archive
php artisan converters:dispatch      :: hand contents out (runs until stopped)
php artisan queue:work --queue=conversions
php artisan converters:reconcile     :: put interrupted conversions back
```

For development, `composer run dev` starts the web server, a queue worker and Vite together.

## Configuration

| `.env` key | Default | Meaning |
|---|---|---|
| `ARCHIVE_DB_*` | | Connection to the archive database (see `config/database.php`) |
| `CONVERTER_WRITE_MODE` | `off` | `on` allows writes to the archive; anything else is a dry run |
| `CONVERTER_WORKERS` | `4` | Contents converted at the same time (the number the panel's Start uses) |
| `CONVERTER_PDF2IMG` | `C:\pdfToImg\bin\pdf2img.exe` | The renderer |
| `CONVERTER_PDF2IMG_ARGS` | `-r 300` | Arguments in front of the input and output paths |
| `CONVERTER_WORKSPACE` | `C:\pdfToImg\work` | Where a content is converted; each folder is deleted when its pages are stored |
| `CONVERTER_FREE_SPACE_FLOOR_GB` | `20` | Conversion pauses while the drive has less free than this |
| `CONVERTER_MAX_ATTEMPTS` | `3` | Attempts per content before it is left failed |
| `CONVERTER_STALE_AFTER_MINUTES` | `60` | A conversion silent for this long is cleaned up and queued again |
| `CONVERTER_DISCOVERY_INTERVAL` | `15` | Minutes between passes that look for new work |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@example.com` / random | Administrator created by `php artisan db:seed` |

`config/converter.php` holds the rest (FTP timeouts and retries, render timeout, thumbnail size, queue names).

## Replacing the old .NET workers

1. Stop the converters in the old panel and let the running workers finish.
2. Stop the old queue workers and the scheduler, and remove the `e{n}.exe` startup entries.
3. Deploy this version, run `php artisan migrate --force`, and keep `CONVERTER_WRITE_MODE=off`.
4. `php artisan converters:discover` fills the queue: the first pass copies the contents the old pipeline had
   already found, after which new documents are discovered on their own.
5. Convert a handful of contents with `CONVERTER_WRITE_MODE=on` and check them in the archive viewer: page order,
   thumbnails, and the images present on FTP.
6. Then start the supervisor task and hand over fully. The old worker folders (`0`, `1`, …) and their
   `status.txt` / `share.txt` files are no longer used by anything.

## Tests

The tests need the development dependencies (`composer install` without `--no-dev`):

```bat
php artisan test
```

They cover the whole pipeline without a SQL Server or an FTP server: the archive queries are pinned by tests that
assert the exact SQL, and the FTP client is tested against a small FTP server that runs inside the test suite.
