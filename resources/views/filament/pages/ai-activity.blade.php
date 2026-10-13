<div
    x-data="adminAiChat({
        csrfToken: '{{ csrf_token() }}',
        cycleUrl: {{ Js::from($cycleUrl ?? null) }},
        cycleAvailable: {{ Js::from($cycleAvailable ?? false) }},
        manualChatEnabled: false,
        providerCallsAllowed: {{ Js::from($providerCallsAllowed) }},
        provider: '{{ $provider }}',
        providerLabel: '{{ $providerLabel }}',
        modelName: '{{ $modelName }}',
        workspace: {{ Js::from($workspace) }},
        adminUser: {{ Js::from($adminUser) }},
        initialSessions: [],
        initialActiveSession: null,
    })"
    class="arc-shell relative flex h-dvh w-full overflow-hidden antialiased font-sans"
>
    {{-- Left Sidebar: Recorded AI Decision Stream (Every Run & Policy Verdict) -------------------------------- --}}
    <aside
        :class="sidebarOpen ? 'w-80 translate-x-0' : 'w-0 -translate-x-full md:w-0 md:translate-x-0'"
        class="arc-sidebar absolute inset-y-0 start-0 z-40 flex flex-col border-r transition-all duration-200 ease-in-out md:static md:z-auto"
        style="min-width: 0;"
    >
        <div x-show="sidebarOpen" class="flex h-full flex-col p-3" style="width: 20rem;">
            {{-- Sidebar Top: Brand & Close (mobile) --}}
            <div class="mb-3 flex items-center justify-between gap-1.5 px-2">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-2xs ring-1 ring-black/5 dark:bg-zinc-100 dark:text-zinc-950 dark:ring-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2L14.2 9.8L22 12L14.2 14.2L12 22L9.8 14.2L2 12L9.8 9.8L12 2Z"/>
                        </svg>
                    </div>
                    <div>
                        <span class="text-xs font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">ARC AI Activity</span>
                        <p class="text-[10px] text-zinc-400 dark:text-zinc-500">Autonomous Decision Stream</p>
                    </div>
                </div>
                <button
                    type="button"
                    @click="sidebarOpen = false"
                    class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-200/60 hover:text-zinc-700 md:hidden dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    title="Close sidebar" aria-label="Close sidebar"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Live Execution Run Switcher Button --}}
            <div class="mb-3">
                <button
                    type="button"
                    wire:click="$set('selectedDecisionId', null)"
                    @click="if (window.innerWidth < 768) sidebarOpen = false"
                    class="flex w-full items-center justify-between rounded-xl border px-3 py-2 text-xs font-medium shadow-2xs transition active:scale-[0.99] {{ $selectedDecision === null ? 'border-zinc-300 bg-white text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 ring-1 ring-black/5 dark:ring-white/10' : 'border-zinc-200/80 bg-zinc-100/50 text-zinc-700 hover:bg-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"
                >
                    <span class="flex items-center gap-2">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        <span>Active Execution & Cycle</span>
                    </span>
                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-mono text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">Live</span>
                </button>
            </div>

            {{-- Recorded Decisions Section (Stream of Every Run) --}}
            <section wire:poll.15s class="flex flex-1 flex-col overflow-hidden border-b border-zinc-200 pb-3 dark:border-zinc-800" aria-label="Recorded decisions">
                <div class="mb-2 flex items-center justify-between px-2">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-semibold text-zinc-800 dark:text-zinc-200">Recorded decisions</span>
                        <span class="rounded bg-zinc-100 px-1.5 py-0.2 text-[9px] font-mono text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ count($decisions) }}</span>
                    </div>
                    <a href="{{ $decisionLogUrl }}" class="text-[11px] text-zinc-500 hover:text-zinc-800 hover:underline dark:hover:text-zinc-300">AI Decision Log</a>
                </div>
                
                <div class="flex-1 space-y-1 overflow-y-auto pe-1 text-xs">
                    @forelse ($decisions as $decision)
                        @php
                            $badgeColor = match($decision->decision) {
                                \App\Enums\AgentDecisionType::AUTO_APPROVE => 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
                                \App\Enums\AgentDecisionType::PARTIAL_APPROVAL => 'text-blue-500 bg-blue-500/10 border-blue-500/20',
                                \App\Enums\AgentDecisionType::HOLD => 'text-amber-500 bg-amber-500/10 border-amber-500/20',
                                \App\Enums\AgentDecisionType::ESCALATE => 'text-amber-500 bg-amber-500/10 border-amber-500/20',
                                \App\Enums\AgentDecisionType::REJECT => 'text-red-500 bg-red-500/10 border-red-500/20',
                                // An advisory classification moved no money, so it
                                // must not borrow a colour that reads as an
                                // approval or a rejection.
                                \App\Enums\AgentDecisionType::ADVISORY_CLASSIFICATION => 'text-zinc-500 bg-zinc-500/10 border-zinc-500/20',
                                // Default rather than an exhaustive list: this
                                // renders stored rows, so the page must not 500
                                // the first time a sixth decision type exists.
                                default => 'text-zinc-500 bg-zinc-500/10 border-zinc-500/20',
                            };
                        @endphp
                        <button
                            wire:key="arc-decision-{{ $decision->id }}"
                            type="button"
                            wire:click="showDecision({{ $decision->id }})"
                            @click="if (window.innerWidth < 768) sidebarOpen = false"
                            class="group relative flex w-full flex-col gap-1 rounded-xl p-2.5 text-left transition {{ $selectedDecision?->id === $decision->id ? 'bg-zinc-200/80 font-medium dark:bg-zinc-800/90 shadow-2xs ring-1 ring-zinc-300 dark:ring-zinc-700' : 'text-zinc-700 hover:bg-zinc-200/40 dark:text-zinc-300 dark:hover:bg-zinc-800/60' }}"
                        >
                            <div class="flex w-full items-center justify-between gap-1.5">
                                <span class="truncate text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ str($decision->action_type)->replace('_', ' ')->title() }}
                                </span>
                                <span class="shrink-0 rounded-full border px-1.5 py-0.2 text-[9px] font-semibold {{ $badgeColor }}">
                                    {{ $decision->decision->getLabel() }}
                                </span>
                            </div>
                            <div class="flex w-full items-center justify-between text-[10px] text-zinc-500 dark:text-zinc-400">
                                <span class="font-mono">#{{ $decision->id }}</span>
                                <span>{{ $decision->created_at?->diffForHumans() }}</span>
                            </div>
                        </button>
                    @empty
                        <div class="p-4 text-center text-xs text-zinc-400 dark:text-zinc-500">
                            No recorded decisions yet.
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- Navigation to Manual Chat --}}
            <div class="py-2.5">
                <a
                    href="{{ $chatUrl }}"
                    class="flex items-center justify-between rounded-xl border border-zinc-200/90 bg-white px-3 py-2 text-xs font-medium text-zinc-800 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-[0.99] dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <span class="flex items-center gap-2">
                        <svg class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <span>Open Manual Chat</span>
                    </span>
                    <svg class="h-3 w-3 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            {{-- Sidebar Footer: Admin User Profile --}}
            <div class="mt-auto border-t border-zinc-200/80 pt-2.5 dark:border-zinc-800">
                <div class="flex items-center gap-2.5 rounded-xl px-2 py-1.5 text-zinc-700 dark:text-zinc-300">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-xs font-semibold text-white dark:bg-zinc-100 dark:text-zinc-900">
                        <span x-text="adminUser.name ? adminUser.name.charAt(0).toUpperCase() : 'A'"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-zinc-900 dark:text-zinc-100" x-text="adminUser.name || 'Admin User'"></p>
                        <p class="truncate text-[10px] text-zinc-400 dark:text-zinc-500" x-text="adminUser.email || 'admin@eduflow.test'"></p>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    {{-- Main Workspace (Full Chat Interface Filling the Screen) ---------------- --}}
    <div class="arc-workspace relative flex flex-1 flex-col overflow-hidden">
        {{-- Top App Bar --}}
        <header class="arc-header flex h-12 shrink-0 items-center justify-between border-b px-3 sm:px-4">
            {{-- Left side: Breadcrumb & Title --}}
            <div class="flex items-center gap-2">
                <a
                    href="{{ \Filament\Facades\Filament::getUrl() }}"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 active:scale-95 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>Dashboard</span>
                </a>

                <span class="h-3 w-px bg-zinc-200 dark:bg-zinc-800"></span>

                <button
                    type="button"
                    @click="sidebarOpen = !sidebarOpen"
                    class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    :title="sidebarOpen ? 'Hide stream sidebar' : 'Show stream sidebar'" :aria-label="sidebarOpen ? 'Hide stream sidebar' : 'Show stream sidebar'"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h10M4 18h16" />
                    </svg>
                </button>

                <div class="flex items-center gap-1.5 rounded-lg px-1 py-1.5" aria-label="Open ARC activity and execution log">
                    <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $assistantName ?? 'ARC AI' }}</span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Activity</span>
                </div>
            </div>

            {{-- Right side: Model indicator & Quick actions --}}
            <div class="flex items-center gap-2">
                {{-- Quick Link to Manual Chat --}}
                <a
                    href="{{ $chatUrl }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-95 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    <svg class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    <span>Manual Chat</span>
                </a>

                {{-- Provider/Model Badge --}}
                <div class="hidden items-center gap-1.5 rounded-full border border-zinc-200/80 bg-zinc-100/70 px-2.5 py-0.5 text-[11px] text-zinc-600 sm:flex dark:border-zinc-800/80 dark:bg-zinc-900/60 dark:text-zinc-400">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    <span class="font-medium text-zinc-800 dark:text-zinc-200" x-text="providerLabel"></span>
                    <span class="text-zinc-300 dark:text-zinc-700">·</span>
                    <span class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400" x-text="modelName"></span>
                </div>

                <div class="hidden text-[11px] text-zinc-400 lg:block dark:text-zinc-500">
                    <span x-text="workspace.name"></span>
                </div>

                <a
                    href="/admin/ai-activity"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    title="Open in new window" aria-label="Open in new window"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </a>
            </div>
        </header>

        {{-- Main Chat Interface Body (Scrollable Messages Stream Filling Screen) --}}
        <section wire:poll.15s class="flex-1 overflow-y-auto px-4 py-6 sm:px-8" aria-label="ARC activity">
            <div class="mx-auto max-w-3xl transition-all duration-300 space-y-6" :class="{ '!max-w-7xl': hasStartedCycle }">

                @if (! $providerCallsAllowed)
                    <p class="rounded-xl border border-amber-300 bg-amber-50/60 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        External AI calls disabled. Advisory status can be adjusted in AI Settings. Policy decision history and autonomous execution remain available.
                    </p>
                @endif

                {{-- Chat Turn: Focused Decision View (When User Selects a Run from Sidebar) --}}
                @if ($selectedDecision)
                    <div class="flex items-start gap-3" wire:key="arc-selected-{{ $selectedDecision->id }}">
                        <div class="arc-assistant-avatar flex h-8 w-8 shrink-0 items-center justify-center rounded-xl shadow-2xs mt-0.5">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L14.2 9.8L22 12L14.2 14.2L12 22L9.8 14.2L2 12L9.8 9.8L12 2Z"/>
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1 space-y-3">
                            {{-- ReUI Console Card for Focused Decision --}}
                            <div class="overflow-hidden rounded-2xl border border-zinc-800 bg-[#121214] text-zinc-100 shadow-xl">
                                {{-- Console Header --}}
                                <div class="flex items-center justify-between border-b border-zinc-800/80 px-4 py-3 sm:px-5">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <h3 class="truncate text-xs font-semibold tracking-tight text-white sm:text-sm">
                                            Decision #{{ $selectedDecision->id }} · {{ str($selectedDecision->action_type)->replace('_', ' ')->title() }}
                                        </h3>
                                        <span class="inline-flex shrink-0 items-center rounded-md bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-300 ring-1 ring-amber-500/30 ring-inset">
                                            {{ $selectedDecision->decision->getLabel() }}
                                        </span>
                                    </div>
                                    <span class="font-mono text-[11px] text-zinc-400">
                                        {{ $selectedDecision->created_at?->format('H:i:s') }}
                                    </span>
                                </div>

                                {{-- Console Steps Body --}}
                                <div class="space-y-3.5 p-4 sm:p-5 text-xs">
                                    {{-- Step 1: Policy Trace --}}
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex min-w-0 items-start gap-2.5">
                                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="9" />
                                                <path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-medium text-zinc-200">Policy verified</span>
                                                    <span class="rounded border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 font-mono text-[10px] text-amber-300">Policy: {{ $selectedDecision->policy_checked }}</span>
                                                    <span class="font-mono text-[11px] text-zinc-400">{{ str($selectedDecision->status)->replace('_', ' ')->title() }}</span>
                                                </div>
                                                <p class="mt-1 leading-relaxed text-zinc-300">{{ $selectedDecision->reasoning_summary }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Step 2: Input Snapshot Parameters --}}
                                    @if (!empty($selectedDecision->input_snapshot))
                                        <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3">
                                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">Input Snapshot Breakdown</p>
                                            <dl class="mt-2 grid grid-cols-2 gap-2 text-[11px] sm:grid-cols-3">
                                                @foreach ($selectedDecision->input_snapshot as $key => $val)
                                                    @if (is_scalar($val))
                                                        <div>
                                                            <dt class="text-zinc-500 capitalize text-[10px]">{{ str($key)->replace('_', ' ') }}</dt>
                                                            <dd class="mt-0.5 font-mono text-zinc-200 truncate">{{ $val }}</dd>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </dl>
                                        </div>
                                    @endif
                                </div>

                                {{-- Console Footer --}}
                                <div class="flex items-center justify-between border-t border-zinc-800/80 bg-zinc-950/80 px-4 py-2.5 sm:px-5">
                                    <a href="{{ \App\Filament\Resources\AgentDecisions\AgentDecisionResource::getUrl('view', ['record' => $selectedDecision], panel: 'admin') }}" class="text-xs font-medium text-emerald-400 underline underline-offset-4 hover:text-emerald-300">
                                        View full decision evidence
                                    </a>
                                    <button type="button" wire:click="$set('selectedDecisionId', null)" class="text-xs text-zinc-400 hover:text-zinc-200">
                                        &larr; Back to active cycle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Default Welcome State Turn --}}
                    <div class="flex items-start gap-3">
                        <div class="arc-assistant-avatar flex h-8 w-8 shrink-0 items-center justify-center rounded-xl shadow-2xs mt-0.5">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L14.2 9.8L22 12L14.2 14.2L12 22L9.8 14.2L2 12L9.8 9.8L12 2Z"/>
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1 space-y-4">
                            {{-- Assistant Greeting Bubble --}}
                            <div class="arc-card rounded-2xl p-4 sm:p-5 shadow-2xs space-y-2">
                                <div class="flex items-center gap-2">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">Institution operations</p>
                                    <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                    <h1 class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">ARC AI activity</h1>
                                </div>
                                <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                                    <span x-text="greetingLine"></span>
                                </h2>
                                <p class="text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    Recorded policy decisions appear here without a chat prompt. Financial authority stays with deterministic policy and required human approvals.
                                </p>
                                <span class="hidden">Manual chat and provider switches are independent of execution. They do not start or stop a cycle.</span>
                            </div>

                            {{-- Live ReUI Agent Activity Console Container (from luav6 Echo AI) --}}
                            <div wire:ignore wire:key="arc-cycle-execution" data-arc-cycle-execution>
                                <div class="space-y-4">
                                    {{-- Initial Launcher Card: First thing to see alongside greetings --}}
                                <div x-show="!hasStartedCycle" class="overflow-hidden rounded-2xl border border-zinc-200/90 bg-white p-5 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/70">
                                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800/80">
                                        <div class="flex items-center gap-2.5">
                                            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500 dark:bg-amber-400/10 dark:text-amber-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                </svg>
                                            </div>
                                            <div>
                                                <h3 class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">Autonomous Policy Evaluation & Settlement</h3>
                                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Deterministic Policy Engine & Circle Agent Wallet</p>
                                            </div>
                                        </div>
                                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-[10px] font-mono text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Ready</span>
                                    </div>

                                    <p class="mt-3 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
                                        ARC evaluates pending invoices and student assistance requests against deterministic financial policy caps. Settle verified payments autonomously on the Arc network.
                                    </p>

                                    {{-- Safeguard Footnotes --}}
                                    <div class="mt-3 space-y-1 text-[11px] text-zinc-400">
                                        <p id="arc-cycle-safety">
                                            This cycle can move real USDC. Confirmation is required. Closing or navigating away from this page does not cancel payments.
                                        </p>
                                        <p id="arc-cycle-availability" x-show="!cycleAvailable || !cycleUrl" class="text-amber-400">
                                            Execution unavailable. A valid installation institution and active primary wallet are required.
                                        </p>
                                    </div>

                                    {{-- Interactive Execution Trigger (First Button in data-arc-cycle-execution) --}}
                                    <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3.5 dark:border-zinc-800/80">
                                        <div class="text-[11px] font-mono text-zinc-400">
                                            <span x-text="cycleSummary"></span>
                                        </div>
                                        <button
                                            type="button"
                                            @click="runAutonomousCycle()"
                                            :disabled="!cycleAvailable || !cycleUrl || cycleRunning || cycleLocked"
                                            :aria-busy="cycleRunning"
                                            aria-describedby="arc-cycle-safety arc-cycle-availability"
                                            class="inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-zinc-800 disabled:opacity-40 active:scale-95 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                                        >
                                            <svg x-show="cycleRunning" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                            </svg>
                                            <svg x-show="!cycleRunning" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 2L14.2 9.8L22 12L14.2 14.2L12 22L9.8 14.2L2 12L9.8 9.8L12 2Z"/>
                                            </svg>
                                            <span>Run Autonomous Agent Cycle</span>
                                        </button>
                                    </div>
                                </div>

                                {{-- Active Execution Stream: Shown when clicked, matching luav6 Echo AI --}}
                                <div x-show="hasStartedCycle" class="space-y-4">
                                    {{-- User Turn Pill (Matching luav6 screenshot) --}}
                                    <div class="flex items-start justify-end gap-2.5">
                                        <div class="arc-user-bubble max-w-lg rounded-2xl px-4 py-2 text-xs font-medium shadow-2xs">
                                            <span>Run autonomous agent cycle</span>
                                        </div>
                                        <div class="arc-user-avatar flex h-7 w-7 shrink-0 items-center justify-center rounded-xl text-xs font-bold shadow-2xs">
                                            <span x-text="(adminUser?.first_name || 'Admin')[0].toUpperCase()"></span>
                                        </div>
                                    </div>

                                    {{-- Assistant Processing Turn: Two-column responsive layout --}}
                                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 items-start">
                                        {{-- Left Column: Thinking Pill, Activity Console & Completion Action --}}
                                        <div class="space-y-3 lg:col-span-7">
                                        {{-- Thinking / Loading Indicator Pill with 3 animated bouncing dots and timer matching luav6 --}}
                                        <div
                                            x-show="cycleRunning"
                                            class="inline-flex items-center gap-2 rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-3 py-1.5 text-xs text-zinc-600 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300"
                                        >
                                            <div class="flex h-3.5 w-3.5 items-center justify-center text-amber-500">
                                                <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                </svg>
                                            </div>
                                            <span class="font-medium text-zinc-700 dark:text-zinc-200">Thinking</span>
                                            <span class="inline-flex items-center gap-1 text-amber-500">
                                                <span class="thinking-dot"></span>
                                                <span class="thinking-dot"></span>
                                                <span class="thinking-dot"></span>
                                            </span>
                                            <span
                                                class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                                x-text="(cycleElapsedSeconds || 1) + 's'"
                                            ></span>
                                        </div>

                                        {{-- Live ReUI Agent Activity Console (from luav6 Echo AI) --}}
                                        <div class="my-3 overflow-hidden rounded-2xl border border-zinc-800 bg-[#121214] text-zinc-100 shadow-xl">
                                            {{-- Console Header --}}
                                            <div class="flex items-center justify-between border-b border-zinc-800/80 px-4 py-3 sm:px-5">
                                                <div class="flex min-w-0 items-center gap-2.5">
                                                    <h3 class="truncate text-xs font-semibold tracking-tight text-white sm:text-sm" x-text="cycleConsoleTitle"></h3>
                                                    {{-- Dynamic Status Pill --}}
                                                    <template x-if="cycleRunning">
                                                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-blue-500/15 px-2 py-0.5 text-[10px] font-medium text-blue-300 ring-1 ring-blue-500/30 ring-inset">
                                                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-blue-400"></span>
                                                            Running
                                                        </span>
                                                    </template>
                                                    <template x-if="!cycleRunning && cycleState === 'completed'">
                                                        <span class="inline-flex shrink-0 items-center rounded-md bg-emerald-500/15 px-2 py-0.5 text-[10px] font-medium text-emerald-300 ring-1 ring-emerald-500/30 ring-inset">
                                                            Completed
                                                        </span>
                                                    </template>
                                                    <template x-if="!cycleRunning && cycleState === 'failed'">
                                                        <span class="inline-flex shrink-0 items-center rounded-md bg-red-500/15 px-2 py-0.5 text-[10px] font-medium text-red-300 ring-1 ring-red-500/30 ring-inset">
                                                            Failed
                                                        </span>
                                                    </template>
                                                    <template x-if="!cycleRunning && cycleState !== 'completed' && cycleState !== 'failed'">
                                                        <span class="inline-flex shrink-0 items-center rounded-md bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-300 ring-1 ring-amber-500/30 ring-inset">
                                                            Needs you
                                                        </span>
                                                    </template>
                                                </div>

                                                <div class="flex items-center gap-1 text-zinc-400">
                                                    <button
                                                        type="button"
                                                        @click="cycleConsoleCollapsed = !cycleConsoleCollapsed"
                                                        class="rounded-md p-1 transition hover:bg-zinc-800/80 hover:text-white cursor-pointer"
                                                        :title="cycleConsoleCollapsed ? 'Expand console' : 'Collapse console'"
                                                        :aria-label="cycleConsoleCollapsed ? 'Expand console' : 'Collapse console'"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>

                                            {{-- Activity Timeline Body (Steps Matching luav6 Screenshot) --}}
                                            <div x-show="!cycleConsoleCollapsed" class="space-y-3.5 p-4 sm:p-5 text-xs">
                                                <template x-for="step in cycleSteps.filter(s => s.status !== 'pending' || !cycleRunning)" :key="step.id">
                                                    <div class="flex items-start justify-between gap-3 text-xs">
                                                        <div class="flex min-w-0 items-start gap-2.5">
                                                            <template x-if="step.status === 'completed'">
                                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                                    <circle cx="12" cy="12" r="9" />
                                                                    <path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                                                                </svg>
                              
                                                            </template>
                                                            <template x-if="step.status === 'running'">
                                                                <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-blue-400" fill="none" viewBox="0 0 24 24">
                                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                                </svg>
                                                            </template>
                                                            <template x-if="step.status === 'pending'">
                                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2 2">
                                                                    <circle cx="12" cy="12" r="9" />
                                                                </svg>
                                                            </template>
                                                            <template x-if="step.status === 'failed'">
                                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                                    <circle cx="12" cy="12" r="9" />
                                                                    <path d="m15 9-6 6m0-6 6 6" stroke-linecap="round" stroke-linejoin="round"/>
                                                                </svg>
                                                            </template>
                                                            <div class="min-w-0">
                                                                <div class="flex flex-wrap items-center gap-2">
                                                                    <span
                                                                        class="font-medium"
                                                                        :class="step.status === 'running' ? 'text-blue-300 font-semibold' : (step.status === 'completed' ? 'text-zinc-200' : 'text-zinc-500')"
                                                                        x-text="step.label"
                                                                    ></span>
                                                                    <template x-if="step.badge">
                                                                        <span class="rounded border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 font-mono text-[10px] text-amber-300" x-text="step.badge"></span>
                                                                    </template>
                                                                    <template x-if="step.target">
                                                                        <span class="font-mono text-[11px] text-zinc-400" x-text="step.target"></span>
                                                                    </template>
                                                                </div>
                                                                <template x-if="step.detail">
                                                                    <p class="mt-1 text-[11px] leading-relaxed text-zinc-400" x-text="step.detail"></p>
                                                                </template>
                                                            </div>
                                                        </div>
                                                        <span class="shrink-0 font-mono text-[11px] text-zinc-500" x-text="step.time || '—'"></span>
                                                    </div>
                                                </template>

                                                {{-- Upcoming Queued Step if running (matching luav6 Screenshot) --}}
                                                <div x-show="cycleRunning" class="space-y-3 pt-1">
                                                    <div class="flex items-center justify-between gap-3 text-xs text-zinc-500">
                                                        <div class="flex min-w-0 items-center gap-2.5">
                                                            <svg class="h-4 w-4 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2 2">
                                                                <circle cx="12" cy="12" r="9" />
                                                            </svg>
                                                            <span class="rounded border border-zinc-800 bg-zinc-900 px-1 py-0.5 font-mono text-[10px] text-zinc-500">agent</span>
                                                            <span class="truncate">Finalize and prepare the workspace changes</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            {{-- Console Footer (matching luav6 Screenshot) --}}
                                            <div class="flex items-center justify-between border-t border-zinc-800/80 bg-zinc-950/80 px-4 py-2.5 sm:px-5">
                                                <span class="font-mono text-[11px] text-zinc-500" x-text="'Executing real time pipeline ' + (cycleElapsedSeconds || 1) + 's'"></span>
                                                <span class="inline-flex items-center gap-1.5 font-mono text-[11px] text-zinc-400">
                                                    <svg class="h-3.5 w-3.5 text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                                    </svg>
                                                    <span>Echo Guard Active</span>
                                                </span>
                                            </div>
                                        </div>

                                            {{-- Completion Actions --}}
                                            <div x-show="!cycleRunning && cycleState === 'completed'" class="flex items-center justify-between pt-2">
                                                <span class="text-xs text-zinc-400">Autonomous cycle execution completed.</span>
                                                <button
                                                    type="button"
                                                    @click="resetToOverview()"
                                                    class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-700 bg-zinc-800 px-3.5 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-zinc-700 active:scale-95 cursor-pointer"
                                                >
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                    </svg>
                                                    <span>Run Another Cycle</span>
                                                </button>
                                            </div>
                                        </div>

                                        {{-- Right Column: Real-time Execution Events Log Drawer (Preview Panel) --}}
                                        <div class="space-y-3 lg:col-span-5">
                                            <div class="overflow-hidden rounded-2xl border border-zinc-800 bg-[#121214] text-zinc-100 shadow-xl">
                                                <div class="flex items-center justify-between border-b border-zinc-800/80 px-4 py-3 sm:px-5">
                                                    <div class="flex items-center gap-2">
                                                        <span class="relative flex h-2 w-2">
                                                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                                        </span>
                                                        <h2 id="arc-cycle-heading" class="text-xs font-semibold tracking-tight text-white sm:text-sm">Execution log</h2>
                                                        <span class="font-mono text-[10px] text-zinc-400" x-text="'(' + cycleEvents.length + ' facts)'"></span>
                                                    </div>
                                                    <a href="{{ $decisionLogUrl }}" class="text-[10px] text-zinc-400 hover:text-zinc-200 underline">Review AI Decision Log evidence</a>
                                                </div>

                                                <div class="space-y-3 p-4 sm:p-5 text-xs">
                                                    <p class="text-[11px] leading-relaxed text-zinc-400">
                                                        Public operational facts and recorded policy reasons only. No private model reasoning or simulated thinking.
                                                    </p>
                                                    <div class="rounded-xl border border-zinc-800/80 bg-zinc-900/50 p-2.5">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Live Status</span>
                                                            <span x-show="cycleRunId" x-cloak class="truncate font-mono text-[10px] text-zinc-400">Run <span x-text="cycleRunId"></span></span>
                                                        </div>
                                                        <p role="status" aria-live="polite" class="mt-1 font-mono text-[11px] text-zinc-200">
                                                            <span class="sr-only" x-text="cycleStateLabel + '. '"></span>
                                                            <span x-text="cycleSummary"></span>
                                                        </p>
                                                    </div>

                                                    <p x-show="cycleLocked" x-cloak class="rounded-lg border border-amber-800 bg-amber-950/40 p-2 text-xs text-amber-200">
                                                        Rerun disabled for this page session. Review recorded decisions and reconcile payment evidence before another run.
                                                    </p>
                                                    <p x-show="cycleRefreshError" x-cloak role="status" class="text-xs text-zinc-400" x-text="cycleRefreshError"></p>
                                                    <p x-show="cycleOmittedEvents > 0" x-cloak class="text-[10px] text-zinc-500">
                                                        Showing latest 100 events. <span x-text="cycleOmittedEvents"></span> earlier events omitted; review AI Decision Log for evidence.
                                                    </p>

                                                    {{-- Events Stream List --}}
                                                    <ol role="log" aria-label="Public execution events" aria-live="polite" aria-relevant="additions" tabindex="0" class="max-h-64 space-y-1.5 overflow-y-auto rounded-xl border border-zinc-800/80 bg-zinc-950/60 p-2.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-500">
                                                        <template x-for="event in cycleEvents" :key="event.run_id + ':' + event.sequence">
                                                            <li class="rounded border border-zinc-800 bg-zinc-900/60 p-2 text-xs">
                                                                <div class="flex items-center justify-between text-[10px] text-zinc-500">
                                                                    <span class="font-mono text-amber-400/90" x-text="event.phase + ' #' + event.sequence"></span>
                                                                    <time :datetime="event.occurred_at" class="font-mono text-[9px]" x-text="cycleEventTime(event.occurred_at)"></time>
                                                                </div>
                                                                <p class="mt-0.5 font-semibold text-zinc-200" x-text="event.title"></p>
                                                                <p class="mt-0.5 text-zinc-400 text-[11px] leading-relaxed" x-text="event.summary"></p>
                                                                <div x-show="cycleEventDetails(event)" class="mt-1 font-mono text-[9px] text-zinc-500" x-text="cycleEventDetails(event)"></div>
                                                            </li>
                                                        </template>
                                                    </ol>

                                                    {{-- Local Results Breakdown --}}
                                                    <div x-show="cycleStats" x-cloak class="border-t border-zinc-800/80 pt-3">
                                                        <h3 class="text-[11px] font-semibold text-zinc-200">Local results · not verified settlement</h3>
                                                        <dl class="mt-2 grid grid-cols-2 gap-1.5 text-xs sm:grid-cols-2 xl:grid-cols-4">
                                                            <div class="rounded border border-zinc-800 bg-zinc-900 p-1.5">
                                                                <dt class="text-[9px] uppercase tracking-wider text-zinc-500">Auto-paid</dt>
                                                                <dd class="font-mono font-semibold text-emerald-400" x-text="cycleStats?.auto_paid ?? '—'"></dd>
                                                            </div>
                                                            <div class="rounded border border-zinc-800 bg-zinc-900 p-1.5">
                                                                <dt class="text-[9px] uppercase tracking-wider text-zinc-500">Escalated</dt>
                                                                <dd class="font-mono font-semibold text-amber-400" x-text="cycleStats?.escalated ?? '—'"></dd>
                                                            </div>
                                                            <div class="rounded border border-zinc-800 bg-zinc-900 p-1.5">
                                                                <dt class="text-[9px] uppercase tracking-wider text-zinc-500">Held</dt>
                                                                <dd class="font-mono font-semibold text-zinc-400" x-text="cycleStats?.held ?? '—'"></dd>
                                                            </div>
                                                            <div class="rounded border border-zinc-800 bg-zinc-900 p-1.5">
                                                                <dt class="text-[9px] uppercase tracking-wider text-zinc-500">Rejected</dt>
                                                                <dd class="font-mono font-semibold text-red-400" x-text="cycleStats?.rejected ?? '—'"></dd>
                                                            </div>
                                                            <div class="col-span-2 rounded border border-zinc-800 bg-zinc-900 p-1.5 sm:col-span-2 xl:col-span-4">
                                                                <dt class="text-[9px] uppercase tracking-wider text-zinc-500">Local disbursed (USDC)</dt>
                                                                <dd class="font-mono font-semibold text-zinc-200" x-text="cycleStats?.total_disbursed_usdc ? cycleStats.total_disbursed_usdc + ' USDC' : '—'"></dd>
                                                            </div>
                                                        </dl>
                                                        <p class="mt-1 text-[9px] text-zinc-500">
                                                            These are local workflow results, not proof of on-chain settlement. Reconcile payment evidence before treating funds as settled.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                @endif

                {{-- Action Starters (2x2 Grid shown when idle on initial screen) --}}
                <div x-show="!hasStartedCycle" class="welcome-suggestions w-full max-w-3xl pt-2">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            @click="runAutonomousCycle()"
                            :disabled="!cycleAvailable || !cycleUrl || cycleRunning || cycleLocked"
                            class="group flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-2.5 text-left transition hover:border-zinc-300 hover:bg-zinc-100/70 active:scale-[0.99] disabled:opacity-40 dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            <div class="flex w-full items-center justify-between">
                                <span class="text-xs font-medium text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white">Run Autonomous Cycle</span>
                                <svg class="h-3 w-3 text-zinc-400 opacity-0 transition group-hover:translate-x-0.5 group-hover:opacity-100 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                            <span class="mt-0.5 text-[11px] text-zinc-500 line-clamp-1 dark:text-zinc-400">Execute deterministic disbursement policies</span>
                        </button>

                        <a
                            href="{{ $decisionLogUrl }}"
                            class="group flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-2.5 text-left transition hover:border-zinc-300 hover:bg-zinc-100/70 active:scale-[0.99] dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            <div class="flex w-full items-center justify-between">
                                <span class="text-xs font-medium text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white">AI Decision Log</span>
                                <svg class="h-3 w-3 text-zinc-400 opacity-0 transition group-hover:translate-x-0.5 group-hover:opacity-100 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                            <span class="mt-0.5 text-[11px] text-zinc-500 line-clamp-1 dark:text-zinc-400">Review full audit trail & on-chain evidence</span>
                        </a>

                        <a
                            href="{{ $chatUrl }}"
                            class="group flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-2.5 text-left transition hover:border-zinc-300 hover:bg-zinc-100/70 active:scale-[0.99] dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            <div class="flex w-full items-center justify-between">
                                <span class="text-xs font-medium text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white">Manual Chat Assistant</span>
                                <svg class="h-3 w-3 text-zinc-400 opacity-0 transition group-hover:translate-x-0.5 group-hover:opacity-100 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                            <span class="mt-0.5 text-[11px] text-zinc-500 line-clamp-1 dark:text-zinc-400">Ask questions & analyze student records</span>
                        </a>

                        @if ($decisions->isNotEmpty())
                            <button
                                type="button"
                                wire:click="showDecision({{ $decisions->first()->id }})"
                                class="group flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-2.5 text-left transition hover:border-zinc-300 hover:bg-zinc-100/70 active:scale-[0.99] dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                            >
                                <div class="flex w-full items-center justify-between">
                                    <span class="text-xs font-medium text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white">Inspect Latest Run Trace</span>
                                    <svg class="h-3 w-3 text-zinc-400 opacity-0 transition group-hover:translate-x-0.5 group-hover:opacity-100 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                                <span class="mt-0.5 text-[11px] text-zinc-500 line-clamp-1 dark:text-zinc-400">Decision #{{ $decisions->first()->id }} · {{ str($decisions->first()->action_type)->replace('_', ' ')->title() }}</span>
                            </button>
                        @else
                            <div class="flex flex-col justify-between rounded-xl border border-zinc-200/50 bg-zinc-50/30 p-2.5 text-left dark:border-zinc-800/50 dark:bg-zinc-900/20">
                                <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">Autonomous Policy Safety</span>
                                <span class="mt-0.5 text-[11px] text-zinc-400 dark:text-zinc-500">Decisions require human review above limit</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="pt-1 text-center">
                    <a href="{{ $decisionLogUrl }}" class="inline-block text-[11px] font-medium underline underline-offset-4 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">Open AI Decision Log</a>
                </div>
            </div>
        </section>
    </div>

    @include('filament.pages.ai-chat-style')

    @include('filament.pages.ai-activity-script')
</div>
