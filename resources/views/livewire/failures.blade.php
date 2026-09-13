@use('App\Actions\Converter\Pipeline\FailureKind')
@use('App\Actions\Converter\Pipeline\Stage')

<div class="p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-wrap gap-1">
            @foreach ($kinds as $kind)
                <button
                    type="button"
                    wire:click="show('{{ $kind->value }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        'bg-gray-900 text-white' => $kind === $selected,
                        'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => $kind !== $selected,
                    ])
                >
                    {{ __($kind->label()) }}
                    <span @class([
                        'ms-1 text-xs',
                        'text-gray-300' => $kind === $selected,
                        'text-gray-400' => $kind !== $selected,
                    ])>{{ number_format($counts[$kind->value] ?? 0) }}</span>
                </button>
            @endforeach
        </div>

        <div class="relative">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Search a content id or a path') }}"
                class="w-72 max-w-full rounded-xl border-gray-300 bg-gray-50 py-2 ps-3 pe-8 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:ring-blue-500"
            />
            <span wire:loading wire:target="search" class="absolute inset-y-0 end-3 flex items-center text-xs text-gray-400">…</span>
        </div>
    </div>

    <p class="mt-2 text-xs text-gray-500">{{ __($selected->description()) }}</p>

    @if ($conversions->isEmpty())
        <p class="mt-6 rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
            @if ($search !== '')
                {{ __('Nothing matches that search.') }}
            @else
                {{ __('Nothing here. Everything of this kind has converted.') }}
            @endif
        </p>
    @else
        <ul class="mt-4 divide-y divide-gray-100">
            @foreach ($conversions as $conversion)
                <li class="py-3">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span class="font-mono text-xs text-gray-500">{{ $conversion->content_id }}</span>

                        <span @class([
                            'rounded px-2 py-0.5 text-xs font-medium',
                            'bg-red-50 text-red-700' => ! in_array($conversion->failure_stage, [Stage::Missing, Stage::Skipped], true),
                            'bg-gray-100 text-gray-600' => in_array($conversion->failure_stage, [Stage::Missing, Stage::Skipped], true),
                        ])>{{ $conversion->failure_stage?->label() ?? __('unknown') }}</span>

                        @if ($conversion->attempts > 1)
                            <span class="text-xs text-gray-400">{{ trans_choice(':count attempt|:count attempts', $conversion->attempts) }}</span>
                        @endif

                        @if ($conversion->profile_id !== null)
                            <span class="text-xs text-gray-400">{{ __('profile') }} {{ $conversion->profile_id }}</span>
                        @endif

                        <span class="text-xs text-gray-400" title="{{ $conversion->finished_at?->toDayDateTimeString() }}">
                            {{ $conversion->finished_at?->diffForHumans() }}
                        </span>
                    </div>

                    {{-- The reason carries the path of the file it was working on: the point of the list. --}}
                    <p class="mt-1 break-words text-xs text-gray-600">{{ $conversion->failure_reason }}</p>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $conversions->links() }}
        </div>
    @endif

    <p class="mt-4 text-xs text-gray-500">
        {{ __('Put them back with') }} <code class="rounded bg-gray-100 px-1">php artisan converters:retry --stage=&lt;step&gt;</code>
        {{ __('or') }} <code class="rounded bg-gray-100 px-1">--content=&lt;id&gt;</code>
    </p>
</div>
