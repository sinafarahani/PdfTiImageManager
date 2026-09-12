@use('App\Actions\Converter\ConverterStatus')

@php
    $running = $status === ConverterStatus::RUNNING;
    $converting = $counts['converting'];
@endphp

<div wire:poll.2s="syncState" class="p-6">
    <div class="flex flex-wrap items-center gap-4">
        @if ($running && $converting > 0)
            <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
                <span class="size-2 rounded-full bg-green-500"></span>
                {{ __('Started') }} &middot; {{ trans_choice(':count content converting|:count contents converting', $converting) }}
            </span>
        @elseif ($running && $counts['waiting'] > 0)
            <span class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-800">
                <span class="size-2 animate-pulse rounded-full bg-amber-500"></span>
                {{ __('Starting') }}
            </span>
        @elseif ($running)
            <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
                <span class="size-2 rounded-full bg-green-500"></span>
                {{ __('Started') }} &middot; {{ __('nothing waiting') }}
            </span>
        @elseif ($converting > 0)
            <span class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-800">
                <span class="size-2 animate-pulse rounded-full bg-amber-500"></span>
                {{ __('Stopping') }} &middot; {{ trans_choice(':count content finishing|:count contents finishing', $converting) }}
            </span>
        @else
            <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">
                <span class="size-2 rounded-full bg-gray-400"></span>
                {{ __('Stopped') }}
            </span>
        @endif

        @can('start-action')
            @if ($running)
                <button type="button" wire:click="stop" wire:loading.attr="disabled" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus:outline-hidden focus:ring-4 focus:ring-blue-300 disabled:opacity-50">
                    {{ __('Stop') }}
                </button>
            @else
                <form wire:submit="start" class="flex flex-wrap items-center gap-2">
                    <label for="threads" class="text-sm font-medium text-gray-900">{{ __('Contents at a time:') }}</label>
                    <input type="number" id="threads" min="1" max="64" wire:model="threads" required class="w-20 rounded-xl border-gray-300 bg-gray-50 py-2.5 text-center text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500" />
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus:outline-hidden focus:ring-4 focus:ring-blue-300 disabled:opacity-50">
                        {{ __('Start') }}
                    </button>
                </form>
            @endif
        @else
            <span class="text-sm text-gray-500">{{ __('Only administrators can start or stop the converters.') }}</span>
        @endcan

        @error('threads')
            <p class="w-full text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl bg-gray-50 px-4 py-3">
            <dt class="text-xs font-medium text-gray-500">{{ __('Waiting') }}</dt>
            <dd class="text-lg font-semibold text-gray-900">{{ number_format($counts['waiting']) }}</dd>
        </div>
        <div class="rounded-xl bg-gray-50 px-4 py-3">
            <dt class="text-xs font-medium text-gray-500">{{ __('Converting now') }}</dt>
            <dd class="text-lg font-semibold text-gray-900">{{ number_format($converting) }}</dd>
        </div>
        <div class="rounded-xl bg-gray-50 px-4 py-3">
            <dt class="text-xs font-medium text-gray-500">{{ __('Converted today') }}</dt>
            <dd class="text-lg font-semibold text-gray-900">{{ number_format($counts['converted_today']) }}</dd>
        </div>
        <div class="rounded-xl bg-gray-50 px-4 py-3">
            <dt class="text-xs font-medium text-gray-500">{{ __('Failed') }}</dt>
            <dd class="text-lg font-semibold {{ $counts['failed'] > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ number_format($counts['failed']) }}</dd>
        </div>
    </dl>

    @if ($runningThreads > 0)
        <p class="mt-4 text-xs text-gray-500">
            {{ trans_choice('Set to :count content at a time.|Set to :count contents at a time.', $runningThreads) }}
        </p>
    @endif
</div>
