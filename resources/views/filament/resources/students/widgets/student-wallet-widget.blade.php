<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <span class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-m-wallet" class="h-5 w-5" />
                Student wallet
            </span>
        </x-slot>

        <x-slot name="description">
            Live {{ strtoupper($chain) }} balance and receive details for this student.
        </x-slot>

        <x-slot name="afterHeader">
            <div class="flex items-center gap-2">
                @if (! $hasGeneratedWallet && $address !== null)
                    <x-filament::button
                        color="warning"
                        size="sm"
                        x-on:click.prevent="
                            if (window.confirm('Mint a Circle agent wallet for this student and make it the payout address? Circle allows only {{ $walletLimit }} agent wallets per blockchain and there is no way to release one, so this permanently uses one of {{ $remainingSlotsPhrase }}.')) {
                                \$wire.generateWallet()
                            }
                        "
                    >
                        <x-slot name="icon">
                            <x-filament::icon icon="heroicon-m-plus-circle" class="h-4 w-4" />
                        </x-slot>
                        Generate wallet
                    </x-filament::button>
                @elseif (! $hasGeneratedWallet)
                    <x-filament::button
                        color="primary"
                        size="sm"
                        x-on:click.prevent="
                            if (window.confirm('Mint a Circle agent wallet for this student and make it the payout address? Circle allows only {{ $walletLimit }} agent wallets per blockchain and there is no way to release one, so this permanently uses one of {{ $remainingSlotsPhrase }}.')) {
                                \$wire.generateWallet()
                            }
                        "
                    >
                        <x-slot name="icon">
                            <x-filament::icon icon="heroicon-m-plus-circle" class="h-4 w-4" />
                        </x-slot>
                        Generate wallet
                    </x-filament::button>
                @endif

                <x-filament::button
                    color="gray"
                    size="sm"
                    wire:click="refresh"
                    wire:loading.attr="disabled"
                    wire:target="refresh"
                >
                    <x-slot name="icon">
                        <x-filament::icon icon="heroicon-m-arrow-path" class="h-4 w-4" />
                    </x-slot>
                    Refresh
                </x-filament::button>

                @if ($explorerUrl !== null)
                    <x-filament::button
                        :href="$explorerUrl"
                        color="gray"
                        size="sm"
                        tag="a"
                        target="_blank"
                    >
                        <x-slot name="icon">
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" />
                        </x-slot>
                        ArcScan
                    </x-filament::button>
                @endif
            </div>
        </x-slot>

        @if ($address === null && ! $hasGeneratedWallet)
            <div class="flex items-start gap-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-950/50">
                <x-filament::icon icon="heroicon-m-wallet" class="mt-0.5 h-5 w-5 text-warning-500" />
                <div>
                    <p class="text-sm font-medium">No wallet yet</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($reason !== null)
                            {{ $reason }}
                        @else
                            Generate a Circle agent wallet, or set a payout address on the student
                            record. An address is never invented for the student — the payout
                            address is what the disbursement path pays.
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ $slotSummary }}
                    </p>
                </div>
            </div>
        @elseif ($state === 'invalid')
            <div class="flex items-start gap-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-950/50">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-0.5 h-5 w-5 text-danger-500" />
                <div>
                    <p class="text-sm font-medium">Stored address is not usable</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $reason ?? 'The stored address is not a valid Arc destination.' }}
                        Payments to this student are blocked until it is corrected.
                    </p>
                </div>
            </div>
        @else
            <dl class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-1">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Balance</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5" wire:loading.class="animate-pulse" wire:target="refresh">
                        @if ($isAvailable)
                            <span class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ $balance }}
                            </span>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">USDC</span>
                        @else
                            <span class="text-2xl font-semibold tracking-tight text-gray-400 dark:text-gray-500">
                                —
                            </span>
                        @endif
                    </dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Payout address</dt>
                    <dd class="mt-1 flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded-md bg-gray-50 px-2 py-1.5 text-sm dark:bg-gray-950/50"
                              x-data
                              x-on:click="window.navigator.clipboard.writeText(@js($address)); $tooltip('Copied', { element: $event.target })"
                              title="{{ $address }}">
                            {{ $address }}
                        </code>
                    </dd>
                    @unless ($isAvailable)
                        <p class="mt-2 text-sm text-warning-600 dark:text-warning-400">
                            {{ $reason }}
                        </p>
                    @endunless
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                @if ($isAvailable)
                    <x-filament::badge color="success">Live</x-filament::badge>
                @else
                    <x-filament::badge color="warning">Chain unreadable</x-filament::badge>
                @endif

                <x-filament::badge color="gray">{{ strtoupper($chain) }}</x-filament::badge>

                @if ($isFake)
                    <x-filament::badge color="warning">Fake driver</x-filament::badge>
                @endif
            </div>
        @endif

        @if ($hasGeneratedWallet)
            <dl class="mt-5 grid gap-4 border-t border-gray-200 pt-4 text-sm sm:grid-cols-4 dark:border-white/10">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Generated by</dt>
                    <dd class="mt-0.5 font-medium text-gray-950 dark:text-white">
                        {{ $generatedProvider ?? 'circle' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Generated</dt>
                    <dd class="mt-0.5 font-medium text-gray-950 dark:text-white">
                        {{ $generatedAt ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Faucet drips</dt>
                    <dd class="mt-0.5 font-medium text-gray-950 dark:text-white">
                        {{ $fundingCount }}
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Last funded</dt>
                    <dd class="mt-0.5 font-medium text-gray-950 dark:text-white">
                        {{ $lastFunding ?? 'Never' }}
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Top this wallet up with
                <code class="rounded bg-gray-50 px-1.5 py-0.5 text-xs dark:bg-gray-950/50">php artisan student:fund-wallet</code>
                — the faucet drips a fixed 20 USDC and rate-limits, so funding is a
                deliberate command rather than a button.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
