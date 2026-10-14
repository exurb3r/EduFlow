<x-filament-widgets::widget>
    @if ($invoice)
        <x-filament::section class="overflow-hidden">
            <div class="space-y-6">
                {{-- Header Identity Row --}}
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200/80 pb-5 dark:border-gray-800">
                    <div class="flex items-center gap-3.5">
                        {{-- Document Icon Badge --}}
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-700 to-slate-900 text-white shadow-sm ring-4 ring-slate-100 dark:from-slate-800 dark:to-slate-950 dark:ring-slate-900">
                            <x-filament::icon icon="heroicon-m-document-text" class="h-6 w-6 text-emerald-400" />
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h1 class="font-mono text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                                    {{ $invoice->reference }}
                                </h1>
                                <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                    {{ $vendor?->name ?? 'Direct Vendor' }}
                                </span>
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>Category: <strong class="text-gray-700 dark:text-gray-300">{{ ucfirst($invoice->category) }}</strong></span>
                                <span>&bull;</span>
                                <span>Logged {{ $invoice->created_at?->format('M d, Y H:i') }}</span>
                                @if ($vendor?->email)
                                    <span>&bull;</span>
                                    <a href="mailto:{{ $vendor->email }}" class="hover:text-primary-600 dark:hover:text-primary-400">
                                        {{ $vendor->email }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Lifecycle Status Badge --}}
                        <x-filament::badge
                            :color="match($status) {
                                'auto_paid', 'paid' => 'success',
                                'escalated' => 'danger',
                                'held' => 'warning',
                                'rejected' => 'gray',
                                default => 'info',
                            }"
                            size="md"
                        >
                            <x-filament::icon
                                :icon="match($status) {
                                    'auto_paid', 'paid' => 'heroicon-m-check-circle',
                                    'escalated' => 'heroicon-m-shield-exclamation',
                                    'held' => 'heroicon-m-pause-circle',
                                    'rejected' => 'heroicon-m-x-circle',
                                    default => 'heroicon-m-clock',
                                }"
                                class="mr-1 h-3.5 w-3.5"
                            />
                            {{ ucfirst(str_replace('_', ' ', $status)) }}
                        </x-filament::badge>

                        {{-- Settlement Proof Badge --}}
                        <x-filament::badge
                            :color="match($verdict) {
                                'verified' => 'success',
                                'unsupported' => 'danger',
                                'not_settled' => 'gray',
                                default => 'info',
                            }"
                            size="md"
                        >
                            <x-filament::icon icon="heroicon-m-signal" class="mr-1 h-3.5 w-3.5" />
                            {{ match($verdict) {
                                'verified' => 'Verified on Arc',
                                'unsupported' => 'Receipt Invalid',
                                'not_settled' => 'Awaiting Settlement',
                                default => 'Checking Chain...',
                            } }}
                        </x-filament::badge>
                    </div>
                </div>

                {{-- Metric Cards Grid --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {{-- Total Amount Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Total Obligation
                            </span>
                            <span class="rounded-lg bg-emerald-100 p-1.5 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300">
                                <x-filament::icon icon="heroicon-m-currency-dollar" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 font-mono text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                            {{ $amountFormatted }}
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Arc Native USDC (6 decimals)
                        </p>
                    </div>

                    {{-- Chain Settlement Proof Card --}}
                    <div @class([
                        'relative overflow-hidden rounded-xl border p-4 transition',
                        'border-success-200/80 bg-success-50/30 dark:border-success-900/50 dark:bg-success-950/20' => $verdict === 'verified',
                        'border-danger-200/80 bg-danger-50/30 dark:border-danger-900/50 dark:bg-danger-950/20' => $verdict === 'unsupported',
                        'border-gray-200/80 bg-gray-50/50 dark:border-gray-800 dark:bg-gray-900/50' => ! in_array($verdict, ['verified', 'unsupported'], true),
                    ])>
                        <div class="flex items-center justify-between">
                            <span @class([
                                'text-xs font-medium uppercase tracking-wider',
                                'text-success-700 dark:text-success-300' => $verdict === 'verified',
                                'text-danger-700 dark:text-danger-300' => $verdict === 'unsupported',
                                'text-gray-500 dark:text-gray-400' => ! in_array($verdict, ['verified', 'unsupported'], true),
                            ])>
                                Cryptographic Settlement
                            </span>
                            <span @class([
                                'rounded-lg p-1.5',
                                'bg-success-100 text-success-700 dark:bg-success-950/70 dark:text-success-300' => $verdict === 'verified',
                                'bg-danger-100 text-danger-700 dark:bg-danger-950/70 dark:text-danger-300' => $verdict === 'unsupported',
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! in_array($verdict, ['verified', 'unsupported'], true),
                            ])>
                                <x-filament::icon icon="heroicon-m-check-badge" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-sm font-bold text-gray-950 dark:text-white">
                            @if ($verdict === 'verified')
                                Confirmed on Arc Testnet
                            @elseif ($verdict === 'unsupported')
                                Missing on Arc Rail
                            @else
                                No Settlement Broadcast
                            @endif
                        </div>
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            @if ($txHash)
                                <span class="font-mono">{{ substr($txHash, 0, 10) }}...</span>
                            @else
                                <span>No transaction hash</span>
                            @endif
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

                    {{-- Budget Allocation Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Allocated Budget
                            </span>
                            <span class="rounded-lg bg-indigo-100 p-1.5 text-indigo-700 dark:bg-indigo-950/70 dark:text-indigo-300">
                                <x-filament::icon icon="heroicon-m-scale" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-sm font-bold text-gray-950 dark:text-white truncate">
                            {{ $budget?->name ?? 'Unallocated' }}
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($budget)
                                Headroom: <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format((float) $budget->remaining_amount, 2) }} USDC</span>
                            @else
                                Needs departmental assignment
                            @endif
                        </p>
                    </div>

                    {{-- Due Date & Urgency Card --}}
                    <div @class([
                        'relative overflow-hidden rounded-xl border p-4 transition',
                        'border-danger-200/80 bg-danger-50/30 dark:border-danger-900/50 dark:bg-danger-950/20' => $isOverdue,
                        'border-gray-200/80 bg-gray-50/50 dark:border-gray-800 dark:bg-gray-900/50' => ! $isOverdue,
                    ])>
                        <div class="flex items-center justify-between">
                            <span @class([
                                'text-xs font-medium uppercase tracking-wider',
                                'text-danger-700 dark:text-danger-300' => $isOverdue,
                                'text-gray-500 dark:text-gray-400' => ! $isOverdue,
                            ])>
                                Due Schedule
                            </span>
                            <span @class([
                                'rounded-lg p-1.5',
                                'bg-danger-100 text-danger-700 dark:bg-danger-950/70 dark:text-danger-300' => $isOverdue,
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! $isOverdue,
                            ])>
                                <x-filament::icon icon="heroicon-m-calendar" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-sm font-bold text-gray-950 dark:text-white">
                            {{ $invoice->due_date?->format('M d, Y') ?? 'No Due Date' }}
                        </div>
                        <p @class([
                            'mt-1 text-xs font-medium',
                            'text-danger-600 dark:text-danger-400' => $isOverdue,
                            'text-success-600 dark:text-success-400' => $isSettled,
                            'text-gray-500 dark:text-gray-400' => ! $isOverdue && ! $isSettled,
                        ])>
                            @if ($isSettled)
                                Payment settled
                            @elseif ($isOverdue)
                                Overdue by {{ abs($daysUntilDue) }} {{ \Illuminate\Support\Str::plural('day', abs($daysUntilDue)) }}
                            @else
                                Due in {{ $daysUntilDue }} {{ \Illuminate\Support\Str::plural('day', $daysUntilDue) }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
