<x-filament-widgets::widget>
    @if ($student)
        <x-filament::section class="overflow-hidden">
            <div class="space-y-6">
                {{-- Header Identity Row --}}
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200/80 pb-5 dark:border-gray-800">
                    <div class="flex items-center gap-3.5">
                        {{-- Avatar Circle --}}
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-primary-500 to-primary-700 text-base font-bold text-white shadow-sm ring-4 ring-primary-50 dark:ring-primary-950/40">
                            {{ $initials }}
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h1 class="text-lg font-bold text-gray-950 dark:text-white">
                                    {{ $name }}
                                </h1>
                                <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 font-mono text-xs font-semibold text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                    {{ $studentNumber }}
                                </span>
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <a
                                    href="mailto:{{ $email }}"
                                    class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                                >
                                    <x-filament::icon icon="heroicon-m-envelope" class="h-3.5 w-3.5" />
                                    <span>{{ $email }}</span>
                                </a>
                                <span>&bull;</span>
                                <span>{{ $program }} (Year {{ $yearLevel }})</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$enrollmentColor" size="md">
                            <x-filament::icon icon="heroicon-m-academic-cap" class="mr-1 h-3.5 w-3.5" />
                            {{ ucfirst(str_replace('_', ' ', $enrollmentStatus)) }}
                        </x-filament::badge>

                        <x-filament::badge :color="$academicColor" size="md">
                            <x-filament::icon icon="heroicon-m-check-badge" class="mr-1 h-3.5 w-3.5" />
                            {{ ucfirst(str_replace('_', ' ', $academicStatus)) }}
                        </x-filament::badge>
                    </div>
                </div>

                {{-- Metric Cards Grid --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {{-- Curriculum Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Academic Curriculum
                            </span>
                            <span class="rounded-lg bg-gray-100 p-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                <x-filament::icon icon="heroicon-m-book-open" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">
                            Year {{ $yearLevel }} Standing
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Attendance: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $attendanceRate !== null ? "{$attendanceRate}%" : 'Not recorded' }}</span>
                        </p>
                    </div>

                    {{-- Tuition Balance Card --}}
                    <div @class([
                        'relative overflow-hidden rounded-xl border p-4 transition',
                        'border-danger-200/80 bg-danger-50/30 dark:border-danger-900/50 dark:bg-danger-950/20' => $hasTuitionDebt,
                        'border-success-200/80 bg-success-50/30 dark:border-success-900/50 dark:bg-success-950/20' => ! $hasTuitionDebt,
                    ])>
                        <div class="flex items-center justify-between">
                            <span @class([
                                'text-xs font-medium uppercase tracking-wider',
                                'text-danger-700 dark:text-danger-300' => $hasTuitionDebt,
                                'text-success-700 dark:text-success-300' => ! $hasTuitionDebt,
                            ])>
                                Outstanding Tuition
                            </span>
                            <span @class([
                                'rounded-lg p-1.5',
                                'bg-danger-100 text-danger-700 dark:bg-danger-950/70 dark:text-danger-300' => $hasTuitionDebt,
                                'bg-success-100 text-success-700 dark:bg-success-950/70 dark:text-success-300' => ! $hasTuitionDebt,
                            ])>
                                <x-filament::icon icon="heroicon-m-banknotes" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">
                            {{ $tuitionBalanceFormatted }}
                        </div>
                        <div class="mt-2">
                            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $tuitionAccountsCount }} {{ \Illuminate\Support\Str::plural('term account', $tuitionAccountsCount) }}</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $tuitionPercent }}% paid</span>
                            </div>
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-200/70 dark:bg-gray-800">
                                <div
                                    class="h-full rounded-full bg-primary-600 transition-all duration-300 dark:bg-primary-500"
                                    style="width: {{ $tuitionPercent }}%"
                                ></div>
                            </div>
                        </div>
                    </div>

                    {{-- Bills Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Non-Tuition Bills
                            </span>
                            <span class="rounded-lg bg-gray-100 p-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                <x-filament::icon icon="heroicon-m-receipt-percent" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">
                            {{ $unpaidBillsFormatted }}
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $openBillsCount }} open {{ \Illuminate\Support\Str::plural('charge', $openBillsCount) }}
                        </p>
                    </div>

                    {{-- Arc Payout Wallet Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Arc Payout Wallet
                            </span>
                            <span class="rounded-lg bg-primary-100 p-1.5 text-primary-700 dark:bg-primary-950/70 dark:text-primary-300">
                                <x-filament::icon icon="heroicon-m-wallet" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 font-mono text-xs font-semibold text-gray-900 dark:text-gray-100">
                            @if ($walletAddress)
                                {{ substr($walletAddress, 0, 8) . '...' . substr($walletAddress, -6) }}
                            @else
                                <span class="font-sans text-gray-400">Not configured</span>
                            @endif
                        </div>
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>ARC-TESTNET</span>
                            @if ($explorerUrl)
                                <a
                                    href="{{ $explorerUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-0.5 font-medium text-primary-600 hover:underline dark:text-primary-400"
                                >
                                    <span>ArcScan</span>
                                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3 w-3" />
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
