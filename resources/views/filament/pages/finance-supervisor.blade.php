<x-filament-panels::page>
    {{-- Control Center Hero & Status Overview --}}
    <x-filament::section class="overflow-hidden">
        <div class="space-y-6">
            {{-- Top Identity & Context Row --}}
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200/80 pb-5 dark:border-gray-800">
                <div class="flex items-center gap-3.5">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-indigo-800 text-white shadow-sm ring-4 ring-indigo-50 dark:from-indigo-700 dark:to-indigo-950 dark:ring-indigo-950/40">
                        <x-filament::icon icon="heroicon-m-clipboard-document-list" class="h-6 w-6 text-indigo-200" />
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                                Finance Supervisor Command Center
                            </h1>
                            <x-filament::badge color="info" size="sm">
                                Autonomous Engine Supervisor
                            </x-filament::badge>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Supervise autonomous background workflows, inspect AI budget proposals, and verify receipt routes before dual-custody settlement.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="warning" size="md">
                        <x-filament::icon icon="heroicon-m-shield-exclamation" class="mr-1 h-3.5 w-3.5" />
                        Non-Executable Workspace
                    </x-filament::badge>

                    <x-filament::badge color="success" size="md">
                        <x-filament::icon icon="heroicon-m-lock-closed" class="mr-1 h-3.5 w-3.5" />
                        Dual-Custody Governance
                    </x-filament::badge>
                </div>
            </div>

            {{-- 4-Stage Governance Pipeline Flowchart --}}
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Autonomous Allocation Architecture (4-Stage Pipeline)
                    </span>
                    <span class="text-[11px] text-gray-400">
                        Step 3 is performed on this page
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {{-- Step 1 --}}
                    <div class="relative rounded-lg border border-gray-200/70 bg-white p-3 shadow-xs dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300">1</span>
                            <span class="text-xs font-bold text-gray-900 dark:text-gray-100">Evidence Intake</span>
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                            Cashier records tuition/bills & staff captures budget planning snapshot.
                        </p>
                    </div>

                    {{-- Step 2 --}}
                    <div class="relative rounded-lg border border-gray-200/70 bg-white p-3 shadow-xs dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">2</span>
                            <span class="text-xs font-bold text-gray-900 dark:text-gray-100">AI Policy Proposal</span>
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                            Background worker evaluates institution policy and suggests line allocations.
                        </p>
                    </div>

                    {{-- Step 3 (Active) --}}
                    <div class="relative rounded-lg border-2 border-primary-500/80 bg-primary-50/40 p-3 shadow-xs dark:border-primary-500/60 dark:bg-primary-950/30">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white">3</span>
                                <span class="text-xs font-bold text-primary-950 dark:text-primary-100">Supervisor Review</span>
                            </div>
                            <span class="rounded bg-primary-200/70 px-1.5 py-0.2 text-[10px] font-semibold text-primary-800 dark:bg-primary-900 dark:text-primary-200">You Are Here</span>
                        </div>
                        <p class="mt-1 text-[11px] text-primary-800/80 dark:text-primary-300/80">
                            Review exact figures. Accept, reject, or request proposal correction.
                        </p>
                    </div>

                    {{-- Step 4 --}}
                    <div class="relative rounded-lg border border-gray-200/70 bg-white p-3 shadow-xs dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">4</span>
                            <span class="text-xs font-bold text-gray-900 dark:text-gray-100">Dual-Custody MFA</span>
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                            Payment Approvals desk verifies capacity & signs payment on Arc Network.
                        </p>
                    </div>
                </div>
            </div>

            {{-- 4 Live KPI Metric Cards --}}
            @php
                $metrics = $this->getSupervisorMetrics();
            @endphp
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Awaiting Review Card --}}
                <div @class([
                    'relative overflow-hidden rounded-xl border p-4 transition',
                    'border-warning-300 bg-warning-50/40 dark:border-warning-900/60 dark:bg-warning-950/20' => $metrics['waiting'] > 0,
                    'border-gray-200/80 bg-gray-50/50 dark:border-gray-800 dark:bg-gray-900/50' => $metrics['waiting'] === 0,
                ])>
                    <div class="flex items-center justify-between">
                        <span @class([
                            'text-xs font-medium uppercase tracking-wider',
                            'text-warning-800 dark:text-warning-300' => $metrics['waiting'] > 0,
                            'text-gray-500 dark:text-gray-400' => $metrics['waiting'] === 0,
                        ])>
                            Awaiting Decision
                        </span>
                        <span @class([
                            'rounded-lg p-1.5',
                            'bg-warning-100 text-warning-700 dark:bg-warning-950/70 dark:text-warning-300' => $metrics['waiting'] > 0,
                            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $metrics['waiting'] === 0,
                        ])>
                            <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4" />
                        </span>
                    </div>
                    <div class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ $metrics['waiting'] }} {{ \Illuminate\Support\Str::plural('proposal', $metrics['waiting']) }}
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ $metrics['waiting'] > 0 ? 'Requires supervisor decision' : 'All proposals cleared' }}
                        </span>
                        @if ($metrics['waiting'] > 0)
                            <button
                                type="button"
                                wire:click="showWaitingPlans"
                                class="font-medium text-warning-700 hover:underline dark:text-warning-400"
                            >
                                Filter table &rarr;
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Background Engine Card --}}
                <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-900/50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Background Worker
                        </span>
                        <span class="flex h-2.5 w-2.5 rounded-full {{ $metrics['isEngineHealthy'] ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400' }}"></span>
                    </div>
                    <div class="mt-2 flex items-center gap-2 text-lg font-bold text-gray-950 dark:text-white">
                        <span>{{ $metrics['isEngineHealthy'] ? 'Engine Active' : 'Engine Disabled' }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Queue: <code class="rounded bg-gray-100 px-1 py-0.5 text-[11px] font-mono dark:bg-gray-800">{{ $metrics['queueName'] }}</code>
                        &bull; Heartbeat: {{ $metrics['latestHeartbeat'] }}
                    </p>
                </div>

                {{-- Accepted Proposals Card --}}
                <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-900/50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Accepted Proposals
                        </span>
                        <span class="rounded-lg bg-emerald-100 p-1.5 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300">
                            <x-filament::icon icon="heroicon-m-check-badge" class="h-4 w-4" />
                        </span>
                    </div>
                    <div class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ $metrics['accepted'] }} {{ \Illuminate\Support\Str::plural('plan', $metrics['accepted']) }}
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Immutable planning decisions on file
                    </p>
                </div>

                {{-- Receipt Routing Card --}}
                <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-900/50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Receipt Routing
                        </span>
                        <span class="rounded-lg bg-indigo-100 p-1.5 text-indigo-700 dark:bg-indigo-950/70 dark:text-indigo-300">
                            <x-filament::icon icon="heroicon-m-arrows-right-left" class="h-4 w-4" />
                        </span>
                    </div>
                    <div class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ $metrics['activeRoutes'] }} active {{ \Illuminate\Support\Str::plural('route', $metrics['activeRoutes']) }}
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Bounded successor planning mappings
                    </p>
                </div>
            </div>

            {{-- Governance Safety Details (Clear & Scannable) --}}
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3.5 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-400">
                <div class="flex items-start gap-2.5">
                    <x-filament::icon icon="heroicon-m-information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
                    <div class="space-y-1">
                        <strong class="font-semibold text-slate-800 dark:text-slate-200">Supervisor Governance Principles:</strong>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 pt-1">
                            <div>&bull; <strong>No Direct Execution:</strong> Accepting a proposal creates a recommendation; no transfers are broadcast here.</div>
                            <div>&bull; <strong>Dual-Custody Protection:</strong> Payment approval requires independent MFA verification at the Payment Reviews desk.</div>
                            <div>&bull; <strong>Cryptographic Base Units:</strong> All amounts are integer 6-decimal USDC base units verified against Arc Network.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- Operations & Routing Section --}}
    <div wire:poll.10s>
        {{ $this->operations }}
    </div>

    {{-- Background Workflow Runs Table --}}
    <div class="space-y-4">
        {{-- Quick Filter Pills --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Quick Filter:</span>
                <button
                    type="button"
                    wire:click="filterByState(null)"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition',
                        'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $this->activeStateFilter === null,
                        'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800' => $this->activeStateFilter !== null,
                    ])
                >
                    All Runs ({{ $metrics['total'] }})
                </button>
                <button
                    type="button"
                    wire:click="filterByState('waiting_for_review')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition',
                        'bg-warning-600 text-white dark:bg-warning-500' => $this->activeStateFilter === 'waiting_for_review',
                        'border border-gray-200 bg-white text-gray-700 hover:bg-warning-50 hover:text-warning-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => $this->activeStateFilter !== 'waiting_for_review',
                    ])
                >
                    <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5" />
                    Needs Decision ({{ $metrics['waiting'] }})
                </button>
                <button
                    type="button"
                    wire:click="filterByState('running')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition',
                        'bg-info-600 text-white dark:bg-info-500' => $this->activeStateFilter === 'running',
                        'border border-gray-200 bg-white text-gray-700 hover:bg-info-50 hover:text-info-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => $this->activeStateFilter !== 'running',
                    ])
                >
                    <x-filament::icon icon="heroicon-m-arrow-path" class="h-3.5 w-3.5" />
                    Running ({{ $metrics['running'] }})
                </button>
                <button
                    type="button"
                    wire:click="filterByState('completed')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition',
                        'bg-success-600 text-white dark:bg-success-500' => $this->activeStateFilter === 'completed',
                        'border border-gray-200 bg-white text-gray-700 hover:bg-success-50 hover:text-success-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => $this->activeStateFilter !== 'completed',
                    ])
                >
                    <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" />
                    Completed ({{ $metrics['completed'] }})
                </button>
                @if ($metrics['failed'] > 0)
                    <button
                        type="button"
                        wire:click="filterByState('failed')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition',
                            'bg-danger-600 text-white dark:bg-danger-500' => $this->activeStateFilter === 'failed',
                            'border border-gray-200 bg-white text-gray-700 hover:bg-danger-50 hover:text-danger-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => $this->activeStateFilter !== 'failed',
                        ])
                    >
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
                        Blocked / Failed ({{ $metrics['failed'] }})
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-m-arrow-path" class="h-3.5 w-3.5 text-primary-500" />
                <span>Live polling active (10s)</span>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
