<x-filament-panels::page>
    @php
        $funding = $this->affordability();
        $observation = $funding['observation'];
        $money = static fn ($units) => \App\DTOs\Money::formatExact($units, 'USDC');
    @endphp

    <div class="flex flex-col gap-6" wire:poll.30s>
        <x-filament::section>
            <x-slot name="heading">Can we cover our bills?</x-slot>
            <x-slot name="description">One wallet balance. Clear obligations. Independent approval before spending.</x-slot>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Observed Arc balance', $observation['balance_base_units'], $observation['available'] ? 'Block '.$observation['block_number'].' · '.$observation['observed_at'] : 'Unavailable, never assumed zero'],
                    ['After protections', $funding['available_base_units'], 'Less reserve, restricted cash and committed capacity'],
                    ['Unpaid bills', $funding['unpaid_base_units'], $funding['unpaid_count'].' recorded bills · '.$funding['overdue_count'].' overdue'],
                    ['Funding gap', $funding['funding_gap_base_units'], 'Additional cash needed for bills not already committed'],
                ] as [$label, $amount, $detail])
                    <div class="flex min-w-0 flex-col gap-2">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="break-words text-2xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white">{{ $money($amount) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $detail }}</p>
                    </div>
                @endforeach
            </div>
            @if ($funding['cash_coverage_percent'] !== null)
                <div class="flex flex-col gap-2 pt-6">
                    <label for="bill-cash-coverage" class="text-sm font-medium">Cash covers {{ $funding['cash_coverage_percent'] }}% of bills not already committed</label>
                    <progress id="bill-cash-coverage" max="100" value="{{ $funding['cash_coverage_percent'] }}" class="h-3 w-full accent-primary-600">{{ $funding['cash_coverage_percent'] }}%</progress>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Cash comparison only. Invoice evidence, budget headroom and independent payment approval still apply.</p>
                </div>
            @endif
        </x-filament::section>

        @if ($funding['blockers'] !== [])
            <x-filament::section icon="heroicon-o-exclamation-triangle">
                <x-slot name="heading">Resolve before payment</x-slot>
                <ul class="flex flex-col gap-3 text-sm text-gray-700 dark:text-gray-300" role="status">
                    @foreach ($funding['blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($funding['raw_funding_gap_base_units'] !== null && $funding['funding_gap_base_units'] === null)
            <x-filament::section>
                <x-slot name="heading">Even before protections</x-slot>
                <p class="text-sm">Recorded bills exceed observed wallet cash by <strong class="tabular-nums">{{ $money($funding['raw_funding_gap_base_units']) }}</strong>. Final funding gap remains unknown until policy reserve and commitments can be verified.</p>
            </x-filament::section>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-filament::section>
                <x-slot name="heading">Where cash is protected</x-slot>
                <dl class="flex flex-col gap-4 text-sm">
                    @foreach ([['Policy reserve', $funding['protected_reserve_base_units']], ['Restricted receipts', $funding['restricted_base_units']], ['Committed payment capacity', $funding['committed_base_units']]] as [$label, $amount])
                        <div class="flex flex-wrap items-center justify-between gap-2"><dt>{{ $label }}</dt><dd class="font-medium tabular-nums">{{ $money($amount) }}</dd></div>
                    @endforeach
                </dl>
                <p class="text-sm">Bills already covered by commitments: <strong class="tabular-nums">{{ $money($funding['committed_unpaid_base_units']) }}</strong>. These bills are included in the unpaid total but are not counted twice in the funding gap.</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Conservative planning comparison. Historic holds stay protected until independently released. Submitted or uncertain payments are not money available to spend again.</p>
            </x-filament::section>
            <x-filament::section>
                <x-slot name="heading">Student payments and policy</x-slot>
                <div class="flex flex-col gap-4 text-sm">
                    <p>Reviewed receipts: <strong class="tabular-nums">{{ $money($funding['reviewed_receipts_base_units']) }}</strong></p>
                    <p>{{ $funding['unreviewed_collections'] }} collections need review. Receipts already in this wallet are not added to its balance again.</p>
                    <p>Active policy: {{ $funding['policy_title'] ?? 'None published' }}</p>
                    <p>Configured classification engine: <strong>{{ $funding['classification_engine'] }}</strong>. {{ $funding['ai_advisory_enabled'] ? 'AI advisory and provider disclosure are enabled.' : 'AI advisory or provider disclosure is disabled; no policy evidence will be sent.' }}</p>
                    <p>The configured agent may classify approved obligations against published policy. Exact allocation arithmetic is bounded by reviewed receipts and budget context; AI cannot approve or send money.</p>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button tag="a" :href="route('finance.policies.index')" color="gray" size="sm">Review policy</x-filament::button>
                        @if (auth()->user()?->hasAnyRole(['admin', 'super_admin']))
                            <x-filament::button tag="a" :href="route('finance.policy-evaluations.index')" color="gray" size="sm">Agent progress</x-filament::button>
                        @endif
                        <x-filament::button tag="a" :href="\App\Filament\Pages\Collections::getUrl(panel: 'finance')" color="gray" size="sm">Review collections</x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Wallet evidence, not ledger assumptions</x-slot>
            <div class="flex flex-col gap-3 text-sm">
                <code class="break-all">{{ $observation['address'] ?? 'Treasury not configured' }}</code>
                <p>{{ $observation['chain'] }} · Chain {{ $observation['chain_id'] }}</p>
                <p>Recorded ledger balance: {{ $money($funding['ledger_base_units']) }}. Ledger is shown for comparison only, never substituted for an unavailable chain read.</p>
                @if ($funding['ledger_drift_base_units'] !== null && $funding['ledger_drift_base_units'] !== '0')
                    <p>Ledger minus observed balance: <strong class="tabular-nums">{{ $money($funding['ledger_drift_base_units']) }}</strong>. No ledger correction is applied by this page.</p>
                @endif
                <p>Arc uses USDC for gas. Native and ERC-20 interfaces expose the same funds, not two balances.</p>
                <x-filament::badge color="gray">Read only · no payment approved</x-filament::badge>
            </div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
