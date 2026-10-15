@php
    /**
     * Three separate authorities, listed separately on purpose: a cashier
     * cannot verify their own receipts, a plan reviewer cannot authorize a
     * payment, and a payment reviewer must not be the person who drafted it.
     */
    $sections = [
        [
            'key' => 'collections',
            'title' => 'Awaiting independent receipt review',
            'authority' => 'Cashier or accountant prepared these records. A separate reviewer must confirm the source; neither role can authorize spending.',
            'empty' => 'No unreviewed collection batches.',
            'url' => $queues['collections'],
            'action_label' => 'Review batch',
            'icon' => 'heroicon-m-banknotes',
        ],
        [
            'key' => 'plans',
            'title' => 'Proposals awaiting a plan decision',
            'authority' => 'Planning only. Accepting a proposal records the decision; it reserves nothing and authorizes nothing.',
            'empty' => 'No proposals are waiting for review.',
            'url' => $queues['plans'],
            'action_label' => 'Examine proposal',
            'icon' => 'heroicon-m-document-text',
        ],
        [
            'key' => 'payments',
            'title' => 'Reserved payments awaiting authorization',
            'authority' => 'A hold exists. Exact authorization requires fresh MFA and expires within five minutes. A separately enabled worker submits; verified settlement alone proves payment.',
            'empty' => 'No reserved payments are waiting for authorization.',
            'url' => $queues['payments'],
            'action_label' => 'Authorize payment',
            'icon' => 'heroicon-m-shield-check',
        ],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="'Approval inbox'"
        description="Three different acts. Reading this panel approves nothing, and silence is never consent."
        icon="heroicon-o-inbox"
    >
        @if ($institutionMissing)
            <x-filament::section
                heading="Institution context unavailable"
                description="Run app:install or configure EDUFLOW_INSTITUTION_ID. Data is never selected by row order."
                icon="heroicon-o-exclamation-triangle"
                icon-color="danger"
            />
        @else
            {{--
                Colour here encodes state, not category.

                The three lanes were amber, sky and emerald, which read as three
                severities — and emerald is the colour this panel uses for
                "settled and proven", so a queue of payments awaiting
                authorization was tinted like good news. The lanes are three
                kinds of work, not three states, so hue comes from whether work
                is actually waiting: amber when it is, grey when it is not.
            --}}
            @php
                $laneTone = fn (int $count): string => $count > 0
                    ? 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400'
                    : 'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400';
            @endphp
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
                <a href="{{ $queues['collections'] }}" class="flex items-center justify-between p-3.5 rounded-xl border border-gray-200 bg-white/50 hover:bg-white transition-colors dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg {{ $laneTone(count($collections)) }}">
                            <x-filament::icon icon="heroicon-m-banknotes" class="h-5 w-5" />
                        </div>
                        <div>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Unreviewed Receipts</span>
                            <div class="text-lg font-bold text-gray-950 dark:text-white">{{ count($collections) }}</div>
                        </div>
                    </div>
                    <span class="text-xs font-semibold hover:underline">Open &rarr;</span>
                </a>

                <a href="{{ $queues['plans'] }}" class="flex items-center justify-between p-3.5 rounded-xl border border-gray-200 bg-white/50 hover:bg-white transition-colors dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg {{ $laneTone(count($plans)) }}">
                            <x-filament::icon icon="heroicon-m-document-text" class="h-5 w-5" />
                        </div>
                        <div>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Pending Proposals</span>
                            <div class="text-lg font-bold text-gray-950 dark:text-white">{{ count($plans) }}</div>
                        </div>
                    </div>
                    <span class="text-xs font-semibold hover:underline">Open &rarr;</span>
                </a>

                <a href="{{ $queues['payments'] }}" class="flex items-center justify-between p-3.5 rounded-xl border border-gray-200 bg-white/50 hover:bg-white transition-colors dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg {{ $laneTone(count($payments)) }}">
                            <x-filament::icon icon="heroicon-m-shield-check" class="h-5 w-5" />
                        </div>
                        <div>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Payment Holds</span>
                            <div class="text-lg font-bold text-gray-950 dark:text-white">{{ count($payments) }}</div>
                        </div>
                    </div>
                    <span class="text-xs font-semibold hover:underline">Open &rarr;</span>
                </a>
            </div>

            @foreach ($sections as $queue)
                @php($items = $queue['key'] === 'collections' ? $collections : ($queue['key'] === 'plans' ? $plans : $payments))

                {{-- Flat, like the stat tiles: the band already sits on the panel, and a
                     drop shadow on each inner lane card stacked three elevations
                     in one column. The border carries the separation. --}}
                <div class="fi-section mt-5 rounded-xl border border-gray-200/90 bg-white/40 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 pb-3 dark:border-white/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon :icon="$queue['icon']" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $queue['title'] }}
                                {{-- Amber when work is waiting, grey when the lane is clear. Previously this
                                 was a per-lane hue, so an empty lane and a busy
                                 lane could wear the same "attention" colour. --}}
                                <span class="ms-1.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold {{ count($items) > 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' }}">
                                    {{ count($items) }}
                                </span>
                            </h4>
                        </div>
                        <a href="{{ $queue['url'] }}" class="inline-flex items-center gap-1 text-xs font-medium hover:underline">
                            Open queue
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3 w-3" />
                        </a>
                    </div>

                    <div class="mt-2.5 rounded-lg bg-gray-50/80 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300 flex items-start gap-2">
                        <x-filament::icon icon="heroicon-m-information-circle" class="h-4 w-4 shrink-0 text-gray-400 mt-0.5" />
                        <span>{{ $queue['authority'] }}</span>
                    </div>

                    @if ($items === [])
                        <div class="mt-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-check-circle" class="mx-auto h-8 w-8 text-emerald-500/70 mb-1" />
                            <p>{{ $queue['empty'] }}</p>
                        </div>
                    @else
                        <div class="mt-3 -mx-4 overflow-x-auto">
                            @if ($queue['key'] === 'collections')
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-white/5">
                                        <tr>
                                            <th class="px-4 py-2">Source</th>
                                            <th class="px-4 py-2 text-right">Received</th>
                                            <th class="px-4 py-2 text-right">Restricted</th>
                                            <th class="px-4 py-2">Collection interval</th>
                                            <th class="px-4 py-2">Prepared by</th>
                                            <th class="px-4 py-2">Age</th>
                                            <th class="px-4 py-2 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($items as $item)
                                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition">
                                                <td class="px-4 py-2.5">
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $item['sourceStream'] }}</span>
                                                    <span class="block text-xs font-mono text-gray-500">{{ $item['sourceReference'] }}</span>
                                                </td>
                                                <td class="px-4 py-2.5 text-right font-semibold text-gray-950 dark:text-white">{{ $item['received'] }}</td>
                                                <td class="px-4 py-2.5 text-right text-gray-600 dark:text-gray-300">{{ $item['restricted'] }}</td>
                                                <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $item['interval'] }}</td>
                                                <td class="px-4 py-2.5 text-xs font-medium">{{ $item['owner'] }}</td>
                                                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $item['age'] }}</td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <a href="{{ $item['url'] }}" class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-400 dark:hover:bg-amber-950/60">
                                                        Review
                                                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3" />
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @elseif ($queue['key'] === 'plans')
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-white/5">
                                        <tr>
                                            <th class="px-4 py-2">Trigger</th>
                                            <th class="px-4 py-2 text-right">Budget headroom</th>
                                            <th class="px-4 py-2 text-right">Cash headroom</th>
                                            <th class="px-4 py-2 text-right">Bills</th>
                                            <th class="px-4 py-2">Source digest</th>
                                            <th class="px-4 py-2">Age</th>
                                            <th class="px-4 py-2 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($items as $item)
                                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition">
                                                <td class="px-4 py-2.5">
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $item['kind'] }}</span>
                                                    <span class="block text-xs font-mono text-gray-500">{{ $item['trigger'] }}</span>
                                                </td>
                                                <td class="px-4 py-2.5 text-right font-medium">{{ $item['budgetHeadroom'] }}</td>
                                                <td class="px-4 py-2.5 text-right font-medium">{{ $item['cashHeadroom'] }}</td>
                                                <td class="px-4 py-2.5 text-right"><span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium dark:bg-white/10">{{ $item['bills'] }}</span></td>
                                                <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $item['digest'], 12) }}</td>
                                                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $item['age'] }}</td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <a href="{{ $item['url'] }}" class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20">
                                                        Examine
                                                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3" />
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @else
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-white/5">
                                        <tr>
                                            <th class="px-4 py-2">Bill</th>
                                            <th class="px-4 py-2 text-right">Held amount</th>
                                            <th class="px-4 py-2 text-right">Fee ceiling</th>
                                            <th class="px-4 py-2">Due</th>
                                            <th class="px-4 py-2">Policy / destination</th>
                                            <th class="px-4 py-2">Rail</th>
                                            <th class="px-4 py-2">Drafted by</th>
                                            <th class="px-4 py-2">Age</th>
                                            <th class="px-4 py-2 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($items as $item)
                                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition">
                                                <td class="px-4 py-2.5">
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $item['reference'] }}</span>
                                                    <span class="block text-xs font-mono text-gray-500">Intent #{{ $item['intentId'] }} → {{ $item['recipient'] }}</span>
                                                </td>
                                                <td class="px-4 py-2.5 text-right font-semibold text-gray-950 dark:text-white">{{ $item['amount'] }}</td>
                                                <td class="px-4 py-2.5 text-right text-gray-600 dark:text-gray-300">{{ $item['fee'] }}</td>
                                                <td class="px-4 py-2.5 text-xs">{{ $item['dueDate'] }}</td>
                                                <td class="px-4 py-2.5 text-xs">
                                                    <span class="block font-medium">Policy: {{ $item['policyVersion'] }}</span>
                                                    <span class="block text-gray-500">Destination: {{ $item['destinationVersion'] }}</span>
                                                </td>
                                                <td class="px-4 py-2.5 text-xs"><span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium dark:bg-white/10">{{ $item['chain'] }}</span></td>
                                                <td class="px-4 py-2.5 text-xs font-medium">{{ $item['owner'] }}</td>
                                                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $item['age'] }}</td>
                                                <td class="px-4 py-2.5 text-right">
                                                    {{-- Neutral: this is a row action, not a verdict. It was emerald, which is
                                                             the colour this panel uses for proven settlement, so
                                                             clicking "Authorize" looked like money already moved. --}}
                                                    <a href="{{ $item['url'] }}" class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20">
                                                        Authorize
                                                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3" />
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
