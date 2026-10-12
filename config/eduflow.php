<?php

return [
    // Required outside local/testing; never choose an institution by row order.
    'institution_id' => env('EDUFLOW_INSTITUTION_ID'),

    'background_finance' => [
        'enabled' => env('EDUFLOW_BACKGROUND_FINANCE', false),
        'queue_connection' => env('EDUFLOW_FINANCE_QUEUE_CONNECTION', 'database'),
        'queue' => 'finance-planning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Submission Runtime
    |--------------------------------------------------------------------------
    |
    | Durable submission work is recorded the moment a payment is authorized,
    | but nothing is ever handed to a worker unless an operator turns this on.
    | Enabling it is a separate, reviewed decision because it is the first step
    | toward an executor that talks to a rail, and the release it points at.
    |
    | The isolated executor ships in this build but is gated behind this flag,
    | and behind an explicit Arc *testnet* rail check with no fallback. A
    | dispatched entry with the runtime off is inspected and concluded as
    | blocked with a specific reason; it is never submitted.
    |
    | `stop_switch` holds new submissions without erasing evidence or
    | releasing a hold.
    |
    | `transport` selects how the rail answers. `auto` treats a Circle driver as
    | an asynchronous agent wallet, which is what it is: `circle wallet
    | transfer` returns a Circle transaction id rather than an on-chain hash, so
    | a synchronous submission would fail on every single payment. Setting
    | `synchronous` forces the older contract, which is correct only for a rail
    | that genuinely returns a hash from `transfer()`.
    |
    */

    'submission' => [
        'enabled' => env('EDUFLOW_SUBMISSION_ENABLED', false),
        'queue_connection' => env('EDUFLOW_SUBMISSION_QUEUE_CONNECTION', 'database'),
        'queue' => 'finance-submission',
        'stop_switch' => env('EDUFLOW_SUBMISSION_STOP', false),
        'transport' => env('EDUFLOW_SUBMISSION_TRANSPORT', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Standing Mandate Lane
    |--------------------------------------------------------------------------
    |
    | A standing mandate authorizes a *class* of recurring payment; the
    | deterministic evaluator admits each occurrence. Both default off, and the
    | evaluator is a pure function of evidence with no model and no session in
    | its path.
    |
    | `runtime_enabled` gates whether any automatic release may be recorded at
    | all; `allow_nonzero_allowance` gates whether a reviewed mandate may carry
    | a nonzero per-occurrence ceiling. Until the second is on, every mandate
    | authorizes nothing, which is §18.4's "initial allowance is zero".
    |
    */

    'mandate' => [
        'runtime_enabled' => env('EDUFLOW_MANDATE_ENABLED', false),
        'allow_nonzero_allowance' => env('EDUFLOW_MANDATE_ALLOWANCE', false),
        'queue_connection' => env('EDUFLOW_MANDATE_QUEUE_CONNECTION', 'database'),
        'queue' => 'finance-mandate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dual-Currency Display
    |--------------------------------------------------------------------------
    |
    | Settlement always happens in USDC base units. Staff-facing screens also
    | show a local-currency equivalent so a reviewer can see the real value
    | of what they are authorising. The rate used is the locked quote stored
    | on the agent decision, never a live rate.
    |
    */
    'display_currency' => env('EDUFLOW_DISPLAY_CURRENCY', 'PHP'),
    'max_rate_age_seconds' => env('EDUFLOW_MAX_RATE_AGE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Student Wallet Balance Cache
    |--------------------------------------------------------------------------
    |
    | How long a student's on-chain balance is served from cache before the
    | next read goes back to Arc. A wallet balance is a live chain reading, so
    | this is deliberately short: long enough that opening a screen is one RPC
    | call rather than several, short enough that the number shown is current.
    | The refresh action on the wallet screen bypasses it entirely.
    |
    */
    'wallet_balance_cache_seconds' => env('EDUFLOW_WALLET_BALANCE_CACHE_SECONDS', 30),

    'student_tuition' => [
        'enabled' => env('EDUFLOW_TUITION_SUBMISSION_ENABLED', false),
        'observe_enabled' => env('EDUFLOW_TUITION_OBSERVATION_ENABLED', false),
        'stop_switch' => env('EDUFLOW_TUITION_SUBMISSION_STOP', false),
        // Unattended reconciliation of a settled movement into Collections.
        // Off by default and stricter than the staff button: it requires
        // immutable consent plus verified settlement proof, attributes the
        // receipt to a named verified finance owner, and derives one
        // deterministic capture identity per movement.
        'auto_intake' => env('EDUFLOW_TUITION_AUTO_INTAKE', false),
        'intake_owner_id' => env('EDUFLOW_TUITION_INTAKE_OWNER_ID'),
        'pilot_account_ids' => [],
        'queue_connection' => env('EDUFLOW_TUITION_QUEUE_CONNECTION', 'database'),
        'queue' => 'student-tuition',
        'quote_seconds' => 120,
        'max_observations' => 10,
        'investigation_owner_id' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Campus Quick Resources
    |--------------------------------------------------------------------------
    |
    | Rendered as cards beneath "My Assistance Requests" on the student
    | dashboard. A resource only becomes clickable once its "url" is set;
    | entries without one render as a muted, non-interactive placeholder
    | rather than a link that leads nowhere. This keeps the layout stable
    | without shipping dead links or invented destinations.
    |
    | "icon" must be a kebab-case Lucide icon name. Unknown names fall
    | back to a generic icon on the client, so adding a new resource
    | never breaks the build.
    |
    */

    'resources' => [
        [
            'title' => 'Academic Library',
            'description' => 'Access research journals, past exams, and e-books.',
            'url' => null,
            'icon' => 'book-open',
        ],
        [
            'title' => 'Academic Calendar',
            'description' => 'Check term milestones, holidays, and exam schedules.',
            'url' => null,
            'icon' => 'calendar',
        ],
        [
            'title' => 'IT & LMS Guides',
            'description' => 'Self-help setup guides for Wi-Fi and student portal.',
            'url' => null,
            'icon' => 'help-circle',
        ],
        [
            'title' => 'Office Hours',
            'description' => 'Book consultation slots with advisors and faculty.',
            'url' => null,
            'icon' => 'message-square',
        ],
    ],
];
