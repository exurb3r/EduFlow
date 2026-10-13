<x-filament-panels::page>
    @php
        $status = $this->treasuryStatus();
        $unpaid = $this->unpaidInvoicesSummary();
        $net = $this->netLiquidity();
        $wallet = $this->wallet();
        $address = $status['treasury_address'] ?? $wallet?->address ?? 'Not configured';
    @endphp

    {{-- Top Overview Metrics --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Stat 1: On-Chain Available USDC --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Available USDC (On-Chain)</span>
                <x-filament::badge :color="$status['is_fake'] ? 'warning' : ($status['live_available'] ? 'success' : 'gray')">
                    {{ $status['is_fake'] ? 'Simulation' : ($status['live_available'] ? 'Live Arc' : 'Offline') }}
                </x-filament::badge>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                    {{ $status['onchain_balance'] !== null ? number_format($status['onchain_balance'], 2) : '—' }}
                </span>
                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">USDC</span>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($status['block'])
                    Observed block #{{ number_format($status['block']) }} on {{ strtoupper($status['chain']) }}
                @else
                    {{ $status['error'] ?? 'Live balance reading unavailable' }}
                @endif
            </p>
        </div>

        {{-- Stat 2: Need to Pay (Unpaid Invoices) --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Need to Pay (Invoices)</span>
                @if ($unpaid['overdue_count'] > 0)
                    <x-filament::badge color="danger">
                        {{ $unpaid['overdue_count'] }} overdue
                    </x-filament::badge>
                @else
                    <x-filament::badge color="gray">
                        {{ $unpaid['count'] }} pending
                    </x-filament::badge>
                @endif
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                    {{ number_format($unpaid['total_amount'], 2) }}
                </span>
                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">USDC</span>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Total outstanding obligations awaiting payout
            </p>
        </div>

        {{-- Stat 3: Net Liquidity (Headroom) --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Net Liquidity</span>
                @if ($net !== null)
                    <x-filament::badge :color="$net >= 0 ? 'success' : 'danger'">
                        {{ $net >= 0 ? 'Surplus' : 'Deficit' }}
                    </x-filament::badge>
                @else
                    <x-filament::badge color="gray">Unknown</x-filament::badge>
                @endif
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span @class([
                    'text-3xl font-extrabold tracking-tight',
                    'text-emerald-600 dark:text-emerald-400' => $net !== null && $net >= 0,
                    'text-red-600 dark:text-red-400' => $net !== null && $net < 0,
                    'text-gray-950 dark:text-white' => $net === null,
                ])>
                    {{ $net !== null ? ($net >= 0 ? '+' : '').number_format($net, 2) : '—' }}
                </span>
                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">USDC</span>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Remaining buffer after settling all pending invoices
            </p>
        </div>

        {{-- Stat 4: Arc Gas Balance --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Native Gas Balance</span>
                <x-filament::badge color="info">
                    Chain {{ $status['chain_id'] }}
                </x-filament::badge>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                    {{ $status['onchain_balance'] !== null ? number_format($status['onchain_balance'], 2) : '—' }}
                </span>
                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">USDC Gas</span>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                USDC is native gas token on Arc (sub-second finality)
            </p>
        </div>
    </div>

    {{-- Wallet & Settlement Rail Details --}}
    <x-filament::section icon="heroicon-o-credit-card">
        <x-slot name="heading">Institution Treasury Address & Settlement Rail</x-slot>
        <x-slot name="description">Point of origin for vendor disbursements and tuition collection destination.</x-slot>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            {{-- Address Box --}}
            <div class="space-y-1.5 lg:col-span-2">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Treasury Wallet Address</span>
                <div class="flex flex-wrap items-center gap-2">
                    <code class="rounded-md bg-gray-100 px-2.5 py-1.5 font-mono text-sm font-semibold text-gray-900 select-all dark:bg-gray-800 dark:text-gray-100">
                        {{ $address }}
                    </code>
                    @if (! empty($status['address_url']))
                        <a
                            href="{{ $status['address_url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="size-4" />
                            ArcScan
                        </a>
                    @endif
                </div>
            </div>

            {{-- Local Database Ledger Balance vs Chain Sync --}}
            <div class="space-y-1.5">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Local Database Ledger</span>
                <div class="flex items-center gap-2">
                    <span class="text-base font-bold text-gray-900 dark:text-white">
                        {{ number_format($status['ledger_balance'], 2) }} USDC
                    </span>
                    @if ($status['in_sync'] === true)
                        <x-filament::badge color="success" icon="heroicon-m-check">
                            In sync
                        </x-filament::badge>
                    @elseif ($status['in_sync'] === false)
                        <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
                            Drift: {{ number_format($status['drift'] ?? 0, 2) }} USDC
                        </x-filament::badge>
                    @endif
                </div>
            </div>

            {{-- Network --}}
            <div class="space-y-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Blockchain Network</span>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ strtoupper($status['chain']) }} (ID: {{ $status['chain_id'] }})
                </p>
            </div>

            {{-- Execution Driver --}}
            <div class="space-y-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Execution Gateway</span>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ ucfirst($status['driver']) }} Agent Stack
                </p>
            </div>

            {{-- RPC Node --}}
            <div class="space-y-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">RPC Host</span>
                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate" title="{{ $status['rpc_host'] ?? '—' }}">
                    {{ $status['rpc_host'] ?? 'Not connected' }}
                </p>
            </div>
        </div>
    </x-filament::section>

    {{-- Interactive Table of Unpaid Invoices --}}
    {{ $this->table }}
</x-filament-panels::page>
