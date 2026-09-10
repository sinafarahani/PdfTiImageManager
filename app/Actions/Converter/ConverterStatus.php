<?php

namespace App\Actions\Converter;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The shared state of the converters, stored in the cache so that every browser (and every queue worker) sees the
 * same thing. Each change increments the version, which lets the panel tell a real change apart from a poll, and lets
 * a queued start job see whether the Start that dispatched it is still the current one.
 */
class ConverterStatus
{
    public const string STOPPED = 'stopped';

    public const string RUNNING = 'running';

    private const string STATE_KEY = 'converter.state';

    private const string LOCK_KEY = 'converter.lock';

    /**
     * @return array{status: string, threads: int, version: int}
     */
    public function current(): array
    {
        $state = Cache::get(self::STATE_KEY);

        if (is_array($state)) {
            return $state;
        }

        // State written by earlier versions of the panel.
        return [
            'status' => Cache::get('action_started') ? self::RUNNING : self::STOPPED,
            'threads' => (int) Cache::get('action_threads', 4),
            'version' => 0,
        ];
    }

    /**
     * Stores a new state and returns its version.
     */
    public function set(string $status, ?int $threads = null): int
    {
        $current = $this->current();
        $version = $current['version'] + 1;

        Cache::forever(self::STATE_KEY, [
            'status' => $status,
            'threads' => $threads ?? $current['threads'],
            'version' => $version,
        ]);

        return $version;
    }

    /**
     * Serializes starting and stopping, so that two browsers cannot do it at the same time.
     */
    public function lock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, 120);
    }
}
