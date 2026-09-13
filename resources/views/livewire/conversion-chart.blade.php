@php
    $points = $chart['points'];

    // Only some columns get a label underneath: at 30 days or 24 hours they would otherwise overlap
    // into an unreadable grey smear. The last column always gets one, so the chart says where it ends.
    $stride = max(1, (int) ceil(count($points) / 8));
@endphp

<div class="border-t border-gray-100 p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h3 class="text-sm font-medium text-gray-900">{{ __('Converted over time') }}</h3>
            <p class="mt-0.5 text-xs text-gray-500">{{ __($selected->description()) }}</p>
        </div>

        <div class="inline-flex rounded-lg bg-gray-100 p-0.5">
            @foreach ($ranges as $range)
                <button
                    type="button"
                    wire:click="show('{{ $range->value }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-xs font-medium transition',
                        'bg-white text-gray-900 shadow-sm' => $range === $selected,
                        'text-gray-600 hover:text-gray-900' => $range !== $selected,
                    ])
                >
                    {{ __($range->label()) }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($points === [])
        <p class="mt-6 rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
            {{ __('Nothing has been converted yet.') }}
        </p>
    @else
        <div class="mt-5 flex items-baseline gap-x-6 gap-y-1 text-xs text-gray-500">
            <span class="text-sm font-semibold text-gray-900">{{ trans_choice(':count content converted in this period|:count contents converted in this period', $chart['total']) }}</span>
            @if ($chart['busiest'] !== null)
                <span>{{ __('busiest was :title, with :count', ['title' => $chart['busiest']['title'], 'count' => number_format($chart['busiest']['total'])]) }}</span>
            @endif
        </div>

        <div class="mt-4 flex gap-3">
            {{-- The scale, so a tall column is a number rather than just a tall column. --}}
            <div class="flex h-40 w-12 shrink-0 flex-col justify-between text-right text-[10px] text-gray-400">
                <span>{{ number_format($chart['peak']) }}</span>
                <span>{{ number_format((int) round($chart['peak'] / 2)) }}</span>
                <span>0</span>
            </div>

            <div class="min-w-0 flex-1">
                <div class="relative h-40">
                    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                        <div class="border-t border-gray-100"></div>
                        <div class="border-t border-gray-100"></div>
                        <div class="border-t border-gray-200"></div>
                    </div>

                    <div class="relative flex h-full items-end gap-px">
                        @foreach ($points as $point)
                            <div
                                class="group flex h-full flex-1 items-end"
                                title="{{ $point['title'] }} &mdash; {{ trans_choice(':count content converted|:count contents converted', $point['total']) }}"
                            >
                                @if ($point['total'] > 0)
                                    <div
                                        class="w-full rounded-t-sm bg-blue-500 transition group-hover:bg-blue-700"
                                        style="height: {{ round($point['share'] * 100, 2) }}%"
                                    ></div>
                                @else
                                    {{-- An empty column is still a column: it says the pipeline was idle then,
                                         which a closed-up gap would hide. --}}
                                    <div class="h-px w-full bg-gray-200"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-2 flex gap-px text-[10px] text-gray-400">
                    @foreach ($points as $index => $point)
                        <div class="min-w-0 flex-1 text-center">
                            @if ($index % $stride === 0 || $index === count($points) - 1)
                                <span class="whitespace-nowrap">{{ $point['label'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
