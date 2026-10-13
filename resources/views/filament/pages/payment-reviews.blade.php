<x-filament-panels::page>
    @php
        $overview = $this->reviewOverview;
        $readiness = $this->reviewerReadiness;
    @endphp

    {{-- ReUI Frame / FramePanel composition adapted for Blade; interactions stay in Filament. --}}
    <section class="fi-payment-workspace" aria-label="Payment approval workspace" wire:poll.10s>
        <div class="fi-payment-frame" data-slot="frame">
            <header class="fi-payment-frame-header" data-slot="frame-panel-header">
                <div>
                    <p class="fi-payment-eyebrow">Independent payment review</p>
                    <h2 class="fi-payment-title">A clear decision. An exact payment.</h2>
                    <p class="fi-payment-muted">Review reserved bills here. Track provider attempts separately in Payment Submissions.</p>
                </div>
                <x-filament::badge color="gray" icon="heroicon-m-shield-check">Review only · no transfer here</x-filament::badge>
            </header>

            <dl class="fi-payment-metrics" aria-label="Institution review counts">
                <div class="fi-payment-metric fi-payment-metric-focus" data-slot="frame-panel">
                    <dt>Awaiting review</dt>
                    <dd class="fi-payment-metric-value">{{ $overview['awaiting'] }}</dd>
                    <dd class="fi-payment-metric-caption">Reserved bills without a recorded decision</dd>
                </div>
                <div class="fi-payment-metric" data-slot="frame-panel">
                    <dt>Approval recorded</dt>
                    <dd class="fi-payment-metric-value">{{ $overview['approved'] }}</dd>
                    <dd class="fi-payment-metric-caption">Historical decisions, not current authority or settlement</dd>
                </div>
                <div class="fi-payment-metric" data-slot="frame-panel">
                    <dt>On hold or rejected</dt>
                    <dd class="fi-payment-metric-value">{{ $overview['held_or_rejected'] }}</dd>
                    <dd class="fi-payment-metric-caption">Decision does not release reserved capacity</dd>
                </div>
                <div class="fi-payment-metric" data-slot="frame-panel">
                    <dt>Capacity released</dt>
                    <dd class="fi-payment-metric-value">{{ $overview['released'] }}</dd>
                    <dd class="fi-payment-metric-caption">Separate reviewed releases; history retained</dd>
                </div>
            </dl>
        </div>

        <aside class="fi-payment-notice" role="note" aria-label="Payment safety boundaries">
            <x-filament::icon icon="heroicon-o-information-circle" class="fi-payment-notice-icon" />
            <div>
                <h3>Approval is authority, not settlement.</h3>
                <p>Agent advice is not payment authority. This page sends nothing; a network approval may queue work for a separately enabled executor. Reject or hold keeps capacity reserved. Simulation evidence grants simulation-only authority.</p>
            </div>
        </aside>

        {{ $this->table }}

        <div class="fi-payment-guidance-grid">
            <section class="fi-payment-card" aria-labelledby="payment-review-guide">
                <div class="fi-payment-card-heading">
                    <h3 id="payment-review-guide">How to review a payment</h3>
                    <span class="fi-payment-muted">Three steps</span>
                </div>
                <ol class="fi-payment-steps">
                    <li>
                        <span class="fi-payment-step-number" aria-hidden="true">1</span>
                        <div><h4>Inspect the evidence</h4><p>Check the bill, amount, recipient, network and reserved capacity.</p></div>
                    </li>
                    <li>
                        <span class="fi-payment-step-number" aria-hidden="true">2</span>
                        <div><h4>Choose a decision</h4><p>Open <strong>Review payment</strong>. Approve exact authority, reject, or hold with a reason.</p></div>
                    </li>
                    <li>
                        <span class="fi-payment-step-number" aria-hidden="true">3</span>
                        <div><h4>Confirm independently</h4><p>Use your password and enrolled authenticator. Approval never means paid.</p></div>
                    </li>
                </ol>
            </section>

            <section class="fi-payment-card" aria-labelledby="payment-reviewer-checklist">
                <div class="fi-payment-card-heading">
                    <h3 id="payment-reviewer-checklist">Your reviewer setup</h3>
                    <x-filament::badge :color="$readiness['ready'] ? 'success' : 'warning'">
                        {{ $readiness['ready'] ? 'Account prerequisites met' : 'Setup required' }}
                    </x-filament::badge>
                </div>
                <ul class="fi-payment-checklist">
                    @foreach ([
                        'verified_staff' => ['Verified staff account', 'Verified email and eligible finance or admin role.'],
                        'direct_permission' => ['Direct payment permission', 'AuthorizePayment:PaymentIntent must be assigned to you directly.'],
                        'enrolled_factor' => ['Independently enrolled authenticator', 'An independent checker must pin your existing factor through Set up reviewer.'],
                    ] as $key => [$label, $description])
                        <li>
                            <x-filament::icon
                                :icon="$readiness[$key] ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle'"
                                @class(['fi-payment-check-icon', 'fi-payment-check-met' => $readiness[$key]])
                            />
                            <div><h4>{{ $label }} <span class="fi-payment-sr-only">{{ $readiness[$key] ? '— met' : '— required' }}</span></h4><p>{{ $description }}</p></div>
                        </li>
                    @endforeach
                </ul>
                <p class="fi-payment-card-footer">Account setup is not payment approval. You cannot review a payment you prepared. Current evidence is checked again on submission.</p>
            </section>
        </div>

    </section>

    @php
        $mandate = $this->mandateOverview();
        $advisory = $this->advisoryRanking();
    @endphp

    <x-filament::section collapsible icon="heroicon-o-calendar-days">
        <x-slot name="heading">Recurring mandate lane</x-slot>
        <x-slot name="description">
            What deterministic policy decided for each due occurrence, and what the model merely
            suggested. Read only — nothing here approves or submits anything.
        </x-slot>

        <div class="fi-payment-metric-grid">
            <div class="fi-payment-metric" data-slot="frame-panel">
                <span>Released automatically</span>
                <strong>{{ $mandate['counts']['release'] ?? 0 }}</strong>
            </div>
            <div class="fi-payment-metric" data-slot="frame-panel">
                <span>Waiting on you</span>
                <strong>{{ $mandate['counts']['escalate'] ?? 0 }}</strong>
            </div>
            <div class="fi-payment-metric" data-slot="frame-panel">
                <span>Blocked</span>
                <strong>{{ $mandate['counts']['blocked'] ?? 0 }}</strong>
            </div>
        </div>

        @if (count($mandate['occurrences']) === 0)
            <p class="fi-payment-detail-notes">
                No recurring occurrence has been evaluated yet. A mandate must be independently
                approved before any occurrence can run, and the initial allowance is zero.
            </p>
        @else
            <ul class="fi-payment-detail-notes" role="list">
                @foreach ($mandate['occurrences'] as $occurrence)
                    <li>
                        <x-filament::badge
                            :color="match ($occurrence['disposition']) {
                                'release' => 'success',
                                'escalate' => 'warning',
                                default => 'danger',
                            }"
                        >{{ str_replace('_', ' ', $occurrence['disposition']) }}</x-filament::badge>

                        <strong>
                            {{ $occurrence['obligation_reference'] ?? ('Occurrence #' . $occurrence['id']) }}
                        </strong>
                        @if ($occurrence['vendor_name'])
                            &mdash; {{ $occurrence['vendor_name'] }}
                        @endif
                        @if ($occurrence['due_at'])
                            &mdash; due {{ \Illuminate\Support\Carbon::parse($occurrence['due_at'])->toDayDateTimeString() }}
                        @endif

                        @if (! $occurrence['evidence_valid'])
                            <x-filament::badge color="danger">Evidence does not re-derive</x-filament::badge>
                        @endif

                        @if ($occurrence['reason'])
                            <p>{{ $occurrence['reason'] }}</p>
                        @endif

                        @if (count($occurrence['failed_checks']) > 0)
                            <ul role="list">
                                @foreach ($occurrence['failed_checks'] as $failed)
                                    <li>
                                        <code>{{ $failed['check'] }}</code>
                                        ({{ $failed['severity'] }}) &mdash; {{ $failed['reason'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <p>
                            {{ $occurrence['passed_check_count'] }} check(s) passed.
                            An <code>escalate</code> disposition is not a payment: it still needs a
                            reservation and an independent payment review.
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-filament::section collapsible collapsed icon="heroicon-o-sparkles">
            <x-slot name="heading">Jev's advisory read</x-slot>

            @if ($advisory === null)
                <p class="fi-payment-detail-notes">
                    Advisory classification is unavailable: AI may be switched off, unconfigured, or
                    the provider did not answer. Nothing about a recurring payment depends on it —
                    the deterministic checks above decide alone.
                </p>
            @else
                <p class="fi-payment-detail-notes">
                    Advisory only. The model may rank which bills deserve a reviewer's attention; it
                    cannot approve, authorize, widen a limit or choose a payee.
                </p>
                @if ($advisory['narrative'])
                    <p class="fi-payment-detail-notes">{{ $advisory['narrative'] }}</p>
                @endif
                <ul class="fi-payment-detail-notes" role="list">
                    @foreach ($advisory['candidates'] as $candidate)
                        <li>
                            <x-filament::badge color="gray">advisory</x-filament::badge>
                            {{ $candidate['reference'] ?? ('Bill #' . ($candidate['bill_id'] ?? '?')) }}
                            — recurs {{ round(($candidate['recurring_likelihood'] ?? 0) * 100) }}%,
                            amount {{ ($candidate['stable_amount'] ?? false) ? 'looks stable' : 'varies' }}.
                            @if (! empty($candidate['rationale']))
                                <p>{{ $candidate['rationale'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </x-filament::section>

    <x-filament::section collapsible collapsed icon="heroicon-o-lock-closed">
        <x-slot name="heading">Review history and recovery rules</x-slot>
        <div class="fi-payment-detail-notes">
            <p>Review does not change local payment accounts or lock external wallet funds. Reservations hold application capacity only; rejecting or holding keeps it reserved.</p>
            <p>Recorded decisions are append-only. Historical approval counts include expired approvals and simulation-only decisions; they are not available spending authority. Renewal requires a separate reviewed workflow.</p>
            <p>Reviewer enrollment pins an existing authenticator to protect against account factor replacement. Enrollment grants no payment permission. Existing pins cannot be rotated here; changed factors require independent recovery.</p>
            <p>No capacity release, funding rollover or payment retry is available on this page. Unresolved provider attempts must be investigated before any resend.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
