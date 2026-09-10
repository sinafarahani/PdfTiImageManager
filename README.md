# PDF to Image Manager

A small control panel (Laravel 13, Livewire 4, Jetstream) that starts and stops the PDF-to-image conversion workers
on a Windows server.

- **Start** prepares the worker folders `0 .. n-1` in `PDF_TO_IMG_DIR` (folder `0` is copied for missing ones:
  `e0.exe`, `0.exe` and `e0.exe.config` are renamed, `config.txt` and the shared folder in the .NET configuration are
  set to the worker's number), writes each worker's `share.txt` (share folder and its part of the disk space), and
  starts `e{n}.exe` and the runner through queued jobs.
- **Stop** writes `terminate` into every worker's `status.txt`; the bridge then ends the worker at a safe point, never
  in the middle of a conversion. The panel shows *Stopped* at once, so Start can be clicked right away: a worker whose
  previous run is still finishing starts again once that run has exited, and a Start followed by another Stop before
  its workers began is dropped. A worker normally finishes within a minute; one that is still running 10 minutes
  (`FORCE_STOP_AFTER_MINUTES`) after the Stop is frozen and is ended by force together with its child processes.
- The panel shows where the workers are: *Starting* (not every worker process is up yet), *Started*, *Stopping*
  (workers still finishing their current file) and *Stopped*, read from the running `e{n}.exe` processes. These never
  block the buttons: Stop is available while starting, Start while stopping.
- Every open browser shows the same state. The panel checks for changes every 2 seconds and only updates when something
  changed, so a number you are typing is never reset.
- Only administrators (`users.is_admin`) can start and stop; other signed-in users see the status.
- The interface works without internet access: fonts and scripts are bundled.

## Requirements

- Windows (workers are started with `start` and watched with `tasklist`)
- PHP 8.3 or newer with `pdo_sqlite` (or `pdo_mysql` for MySQL); running the tests needs PHP 8.4
- Composer, and Node.js 20+ to build the frontend

## Installation

```bat
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate --force
php artisan db:seed --force
npm ci
npm run build
```

Edit `.env` first: `PDF_TO_IMG_DIR`, `RUNNER_DIR`, `MAX_AVAILABLE_SPACE`, `SHARE_ROOT`, and optionally
`ADMIN_EMAIL` / `ADMIN_PASSWORD`. `db:seed` creates the administrator; without `ADMIN_PASSWORD` it prints a random
password once.

The default database is SQLite (`database/database.sqlite`), which needs no database server. To use MySQL instead, set
`DB_CONNECTION=mysql` and the `DB_*` values in `.env`.

## Running

- Web server: `php artisan serve --host=0.0.0.0 --port=8000` (or IIS / nginx pointing at `public`).
- Queue workers: `php artisan queue:work --timeout=0`. Every running process keeps one queue worker busy while its
  runner runs, so run at least as many queue workers as the number of processes you start. After a quick Stop and
  Start, the new start jobs wait until the old runs have finished.
- Scheduler: `php artisan schedule:work` (or Task Scheduler running `php artisan schedule:run` every minute). It runs
  `converters:end-frozen`, which ends workers still running `FORCE_STOP_AFTER_MINUTES` after a Stop. A Start that is
  waiting for a frozen previous run also ends it after that time, so a quick Stop and Start never hangs.

For development, `composer run dev` starts the web server, a queue worker and Vite together.

## Configuration

| `.env` key | Default | Meaning |
|---|---|---|
| `PDF_TO_IMG_DIR` | | Folder with one folder per worker; folder `0` is the template |
| `RUNNER_DIR` | | Runner executable started for every worker |
| `MAX_AVAILABLE_SPACE` | `200` | Space in GB shared by the workers |
| `SHARE_ROOT` | `d:` | Drive or folder of the workers' share folders (`<SHARE_ROOT>\<n>\`) |
| `FORCE_STOP_AFTER_MINUTES` | `10` | A worker still running this long after Stop is frozen and is ended by force |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@example.com` / random | Administrator created by `php artisan db:seed` |
| `DB_QUEUE_RETRY_AFTER` | `86400` | Seconds before an unfinished queued job may be handed to another worker |

## Tests

```bat
php artisan test
```
