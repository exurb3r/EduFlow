<x-filament-widgets::widget>
    @if ($user)
        <x-filament::section class="overflow-hidden">
            <div class="space-y-6">
                {{-- Header Identity Row --}}
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200/80 pb-5 dark:border-gray-800">
                    <div class="flex items-center gap-3.5">
                        {{-- Avatar Circle --}}
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-base font-bold text-white shadow-sm ring-4 ring-indigo-50 dark:ring-indigo-950/40">
                            {{ $initials }}
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h1 class="text-lg font-bold text-gray-950 dark:text-white">
                                    {{ $name }}
                                </h1>
                                @if ($student)
                                    <span class="rounded-md border border-primary-200 bg-primary-50 px-2 py-0.5 font-mono text-xs font-semibold text-primary-700 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300">
                                        {{ $student->student_number }}
                                    </span>
                                @else
                                    <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                        User #{{ $user->id }}
                                    </span>
                                @endif
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
                                <span>{{ $emailVerified ? "Verified on {$emailVerifiedAt}" : 'Unverified Email' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($roles as $r)
                            <x-filament::badge
                                :color="match($r) { 'super_admin', 'admin' => 'danger', 'finance_officer' => 'info', 'student' => 'primary', default => 'gray' }"
                                size="md"
                            >
                                <x-filament::icon icon="heroicon-m-shield-check" class="mr-1 h-3.5 w-3.5" />
                                {{ ucfirst(str_replace('_', ' ', $r)) }}
                            </x-filament::badge>
                        @endforeach

                        @if ($canBeImpersonated && auth()->user()?->hasRole('admin'))
                            <a
                                href="{{ route('impersonate', $user->id) }}"
                                target="_blank"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-warning-200 bg-warning-50 px-3 py-1.5 text-xs font-semibold text-warning-800 transition hover:bg-warning-100 dark:border-warning-900/60 dark:bg-warning-950/40 dark:text-warning-300"
                            >
                                <x-filament::icon icon="heroicon-m-user-plus" class="h-3.5 w-3.5" />
                                <span>Impersonate</span>
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Metric Cards Grid --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {{-- Security Posture Card --}}
                    <div @class([
                        'relative overflow-hidden rounded-xl border p-4 transition',
                        'border-success-200/80 bg-success-50/30 dark:border-success-900/50 dark:bg-success-950/20' => $isMfaSecured,
                        'border-warning-200/80 bg-warning-50/30 dark:border-warning-900/50 dark:bg-warning-950/20' => ! $isMfaSecured,
                    ])>
                        <div class="flex items-center justify-between">
                            <span @class([
                                'text-xs font-medium uppercase tracking-wider',
                                'text-success-700 dark:text-success-300' => $isMfaSecured,
                                'text-warning-700 dark:text-warning-300' => ! $isMfaSecured,
                            ])>
                                Security Posture
                            </span>
                            <span @class([
                                'rounded-lg p-1.5',
                                'bg-success-100 text-success-700 dark:bg-success-950/70 dark:text-success-300' => $isMfaSecured,
                                'bg-warning-100 text-warning-700 dark:bg-warning-950/70 dark:text-warning-300' => ! $isMfaSecured,
                            ])>
                                <x-filament::icon icon="heroicon-m-shield-check" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">
                            {{ $isMfaSecured ? 'MFA Protected' : 'Action Recommended' }}
                        </div>
                        <div class="mt-1 flex flex-wrap gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            @if ($hasAppMfa)
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">App MFA</span>
                            @endif
                            @if ($hasEmailMfa)
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">Email OTP</span>
                            @endif
                            @if ($passkeysCount > 0)
                                <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300">{{ $passkeysCount }} Passkeys</span>
                            @endif
                            @if (! $isMfaSecured)
                                <span class="text-warning-600 dark:text-warning-400">No multi-factor methods active</span>
                            @endif
                        </div>
                    </div>

                    {{-- Student Services Standing --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/50 dark:hover:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Student Services
                            </span>
                            <span class="rounded-lg bg-gray-100 p-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                <x-filament::icon icon="heroicon-m-academic-cap" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">
                            @if ($student)
                                {{ $student->program }}
                            @else
                                Non-Student Account
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($student)
                                Year {{ $student->year_level }} &bull; {{ ucfirst(str_replace('_', ' ', $student->enrollment_status)) }}
                            @else
                                Portal user profile
                            @endif
                        </p>
                    </div>

                    {{-- Tuition / Financial State --}}
                    <div @class([
                        'relative overflow-hidden rounded-xl border p-4 transition',
                        'border-danger-200/80 bg-danger-50/30 dark:border-danger-900/50 dark:bg-danger-950/20' => $hasTuitionDebt,
                        'border-gray-200/80 bg-gray-50/50 dark:border-gray-800 dark:bg-gray-900/50' => ! $hasTuitionDebt,
                    ])>
                        <div class="flex items-center justify-between">
                            <span @class([
                                'text-xs font-medium uppercase tracking-wider',
                                'text-danger-700 dark:text-danger-300' => $hasTuitionDebt,
                                'text-gray-500 dark:text-gray-400' => ! $hasTuitionDebt,
                            ])>
                                Tuition Standing
                            </span>
                            <span @class([
                                'rounded-lg p-1.5',
                                'bg-danger-100 text-danger-700 dark:bg-danger-950/70 dark:text-danger-300' => $hasTuitionDebt,
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! $hasTuitionDebt,
                            ])>
                                <x-filament::icon icon="heroicon-m-banknotes" class="h-4 w-4" />
                            </span>
                        </div>
                        <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">
                            @if ($student)
                                {{ $tuitionBalanceFormatted }}
                            @else
                                N/A
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($student)
                                {{ $hasTuitionDebt ? 'Outstanding tuition balance' : 'All accounts settled' }}
                            @else
                                No active tuition ledger
                            @endif
                        </p>
                    </div>

                    {{-- Arc Payout Wallet --}}
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
