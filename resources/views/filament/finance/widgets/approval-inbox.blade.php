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
        ],
        [
            'key' => 'plans',
            'title' => 'Proposals awaiting a plan decision',
            'authority' => 'Planning only. Accepting a proposal records the decision; it reserves nothing and authorizes nothing.',
            'empty' => 'No proposals are waiting for review.',
            'url' => $queues['plans'],
        ],
        [
            'key' => 'payments',
            'title' => 'Reserved payments awaiting authorization',
            'authority' => 'A hold exists. Authorization is separate, MFA-backed, expires within five minutes, and still cannot execute: no executor is shipped.',
            'empty' => 'No reserved payments are waiting for authorization.',
            'url' => $queues['payments'],
        ],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="'Approval inbox'"
        description="Cashier receipt review, plan acceptance and payment authorization are different acts. Silence is never consent, and reading this panel approves nothing."
        icon="heroicon-o-inbox"
    >
        @if ($institutionMissing)
            <x-filament::section
                heading="Institution context unavailable"
                description="Run eduflow:install or configure EDUFLOW_INSTITUTION_ID. Data is never selected by row order."
                icon="heroicon-o-exclamation-triangle"
                icon-color="danger"
            />
        @else
            @foreach ($sections as $queue)
                @php($items = $queue['key'] === 'collections' ? $collections : ($queue['key'] === 'plans' ? $plans : $payments))

                <div class="fi-section mt-6 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $queue['title'] }}
                            <span class="ms-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                {{ count($items) }}
                            </span>
                        </h4>
                        <a href="{{ $queue['url'] }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                            Open queue
                        </a>
                    </div>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $queue['authority'] }}</p>

                    @if ($items === [])
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ $queue['empty'] }}</p>
                    @else
                        <div class="mt-3 -mx-4 overflow-x-auto">
                            @if ($queue['key'] === 'collections')
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <tr>
                                            <th class="px-4 py-2">Source</th>
                                            <th class="px-4 py-2 text-right">Received</th>
                                            <th class="px-4 py-2 text-right">Restricted</th>
                                            <th class="px-4 py-2">Collection interval</th>
                                            <th class="px-4 py-2">Prepared by</th>
                                            <th class="px-4 py-2">Age</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($items as $item)
                                            <tr>
                                                <td class="px-4 py-2">
                                                    <span class="font-medium">{{ $item['sourceStream'] }}</span>
                                                    <span class="block text-xs text-gray-500">{{ $item['sourceReference'] }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-medium">{{ $item['received'] }}</td>
                                                <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $item['restricted'] }}</td>
                                                <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $item['interval'] }}</td>
                                                <td class="px-4 py-2 text-xs">{{ $item['owner'] }}</td>
                                                <td class="px-4 py-2 text-xs text-gray-500">{{ $item['age'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @elseif ($queue['key'] === 'plans')
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <tr>
                                            <th class="px-4 py-2">Trigger</th>
                                            <th class="px-4 py-2 text-right">Budget headroom</th>
                                            <th class="px-4 py-2 text-right">Cash headroom</th>
                                            <th class="px-4 py-2 text-right">Bills</th>
                                            <th class="px-4 py-2">Source digest</th>
                                            <th class="px-4 py-2">Age</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($items as $item)
                                            <tr>
                                                <td class="px-4 py-2">
                                                    <span class="font-medium">{{ $item['kind'] }}</span>
                                                    <span class="block text-xs text-gray-500">{{ $item['trigger'] }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-right">{{ $item['budgetHeadroom'] }}</td>
                                                <td class="px-4 py-2 text-right">{{ $item['cashHeadroom'] }}</td>
                                                <td class="px-4 py-2 text-right">{{ $item['bills'] }}</td>
                                                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $item['digest'], 12) }}</td>
                                                <td class="px-4 py-2 text-xs text-gray-500">{{ $item['age'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @else
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <tr>
                                            <th class="px-4 py-2">Bill</th>
                                            <th class="px-4 py-2 text-right">Held amount</th>
                                            <th class="px-4 py-2 text-right">Fee ceiling</th>
                                            <th class="px-4 py-2">Due</th>
                                            <th class="px-4 py-2">Policy / destination</th>
                                            <th class="px-4 py-2">Rail</th>
                                            <th class="px-4 py-2">Drafted by</th>
                                            <th class="px-4 py-2">Age</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($items as $item)
                                            <tr>
                                                <td class="px-4 py-2">
                                                    <span class="font-medium">{{ $item['reference'] }}</span>
                                                    <span class="block text-xs text-gray-500">Intent #{{ $item['intentId'] }} → {{ $item['recipient'] }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-medium">{{ $item['amount'] }}</td>
                                                <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-300">{{ $item['fee'] }}</td>
                                                <td class="px-4 py-2 text-xs">{{ $item['dueDate'] }}</td>
                                                <td class="px-4 py-2 text-xs">
                                                    <span class="block">Policy: {{ $item['policyVersion'] }}</span>
                                                    <span class="block text-gray-500">Destination: {{ $item['destinationVersion'] }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-xs">{{ $item['chain'] }}</td>
                                                <td class="px-4 py-2 text-xs">{{ $item['owner'] }}</td>
                                                <td class="px-4 py-2 text-xs text-gray-500">{{ $item['age'] }}</td>
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