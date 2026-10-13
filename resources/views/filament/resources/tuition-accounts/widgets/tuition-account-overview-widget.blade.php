<x-filament-widgets::widget>
    @if ($account)
        <x-filament::section class="overflow-hidden">
            <x-slot name="heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-50 dark:bg-primary-950/50">
                            <x-filament::icon
                                icon="heroicon-m-banknotes"
                                class="h-6 w-6 text-primary-600 dark:text-primary-400"
                            />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-semibold leading-tight text-gray-950 dark:text-white">
                                    {{ $studentName }}
                                </h2>
                                <span class="font-mono text-xs font-medium text-gray-500 dark:text-gray-400">
                                    ({{ $studentNumber }})
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $termName }} &bull; Tuition Ledger
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-filament::badge :color="$statusColor" size="lg">
                            @if ($isPaidInFull)
                                <x-filament::icon icon="heroicon-m-check-circle" class="mr-1 h-4 w-4" />
                            @elseif ($isPartiallyPaid)
                                <x-filament::icon icon="heroicon-m-clock" class="mr-1 h-4 w-4" />
                            @else
                                <x-filament::icon icon="heroicon-m-exclamation-circle" class="mr-1 h-4 w-4" />
                            @endif
                            {{ $statusLabel }}
                        </x-filament::badge>
                    </div>
                </div>
            </x-slot>

            <div class="space-y-6 pt-2">
                {{-- Metric Cards Grid --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {{-- Total Tuition Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Total Tuition
                            </span>
                            <span class="rounded-lg bg-primary-100 p-1.5 text-primary-700 dark:bg-primary-950/70 dark:text-primary-300">
                                <x-filament::icon icon="heroicon-m-receipt-percent" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                                {{ $totalFormatted }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Required payment for {{ $termName }}
                        </p>
                    </div>

                    {{-- Amount Paid Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-success-200/80 bg-success-50/30 p-4 transition hover:border-success-300 dark:border-success-900/50 dark:bg-success-950/20 dark:hover:border-success-800">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-success-700 dark:text-success-300">
                                Amount Paid
                            </span>
                            <span class="rounded-lg bg-success-100 p-1.5 text-success-700 dark:bg-success-950/70 dark:text-success-300">
                                <x-filament::icon icon="heroicon-m-check-badge" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="text-2xl font-bold tracking-tight text-success-700 dark:text-success-400">
                                {{ $paidFormatted }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center gap-1.5 text-xs text-success-700 dark:text-success-400">
                            <span class="font-semibold">{{ $percentage }}%</span>
                            <span>settled on Arc Network</span>
                        </div>
                    </div>

                    {{-- Remaining Balance Card --}}
                    <div @class([
                        'relative overflow-hidden rounded-xl border p-4 transition',
                        'border-danger-200/80 bg-danger-50/30 dark:border-danger-900/50 dark:bg-danger-950/20 hover:border-danger-300 dark:hover:border-danger-800' => $account->remainingAmount() > 0,
                        'border-success-200/80 bg-success-50/30 dark:border-success-900/50 dark:bg-success-950/20 hover:border-success-300 dark:hover:border-success-800' => $account->remainingAmount() === 0,
                    ])>
                        <div class="flex items-center justify-between">
                            <span @class([
                                'text-xs font-medium uppercase tracking-wider',
                                'text-danger-700 dark:text-danger-300' => $account->remainingAmount() > 0,
                                'text-success-700 dark:text-success-300' => $account->remainingAmount() === 0,
                            ])>
                                Remaining Balance
                            </span>
                            <span @class([
                                'rounded-lg p-1.5',
                                'bg-danger-100 text-danger-700 dark:bg-danger-950/70 dark:text-danger-300' => $account->remainingAmount() > 0,
                                'bg-success-100 text-success-700 dark:bg-success-950/70 dark:text-success-300' => $account->remainingAmount() === 0,
                            ])>
                                <x-filament::icon icon="heroicon-m-scale" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span @class([
                                'text-2xl font-bold tracking-tight',
                                'text-danger-700 dark:text-danger-400' => $account->remainingAmount() > 0,
                                'text-success-700 dark:text-success-400' => $account->remainingAmount() === 0,
                            ])>
                                {{ $remainingFormatted }}
                            </span>
                        </div>
                        <p @class([
                            'mt-1 text-xs',
                            'text-danger-600 dark:text-danger-400' => $account->remainingAmount() > 0,
                            'text-success-600 dark:text-success-400' => $account->remainingAmount() === 0,
                        ])>
                            @if ($account->remainingAmount() > 0)
                                Outstanding balance pending payment
                            @else
                                All tuition fees are cleared
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Visual Progress Meter --}}
                <div class="rounded-xl border border-gray-200/80 bg-gray-50/40 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-gray-700 dark:text-gray-300">
                            Tuition Payment Progress
                        </span>
                        <span class="font-semibold text-gray-900 dark:text-white">
                            {{ $percentage }}% Complete ({{ $paidFormatted }} / {{ $totalFormatted }})
                        </span>
                    </div>

                    <div class="mt-2 h-3 w-full overflow-hidden rounded-full bg-gray-200/80 dark:bg-gray-800">
                        <div
                            @class([
                                'h-full rounded-full transition-all duration-500 ease-out',
                                'bg-success-500 dark:bg-success-400' => $isPaidInFull,
                                'bg-amber-500 dark:bg-amber-400' => $isPartiallyPaid,
                                'bg-gray-400 dark:bg-gray-600' => $isUnpaid,
                            ])
                            style="width: {{ min(100, max(0, $percentage)) }}%"
                        ></div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 rounded-full bg-primary-500"></span>
                            <span>Network: Arc Testnet &bull; USDC (6 decimals)</span>
                        </div>

                        @if ($explorerUrl)
                            <a
                                href="{{ $explorerUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >
                                <span>Inspect Wallet on ArcScan</span>
                                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
