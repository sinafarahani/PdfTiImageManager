@use('App\Actions\Converter\ConverterStatus')

<div wire:poll.2s="syncState" class="flex flex-wrap items-center gap-4 p-6">
    @if ($status === ConverterStatus::RUNNING)
        <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
            <span class="size-2 rounded-full bg-green-500"></span>
            {{ __('Running') }} &middot; {{ trans_choice(':count process|:count processes', $runningThreads) }}
        </span>
    @else
        <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">
            <span class="size-2 rounded-full bg-gray-400"></span>
            {{ __('Stopped') }}
        </span>
    @endif

    @can('start-action')
        @if ($status === ConverterStatus::RUNNING)
            <button type="button" wire:click="stop" wire:loading.attr="disabled" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus:outline-hidden focus:ring-4 focus:ring-blue-300 disabled:opacity-50">
                {{ __('Stop') }}
            </button>
        @else
            <form wire:submit="start" class="flex flex-wrap items-center gap-2">
                <label for="threads" class="text-sm font-medium text-gray-900">{{ __('Number of processes:') }}</label>
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
