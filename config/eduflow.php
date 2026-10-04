<?php

return [
    // Required outside local/testing; never choose an institution by row order.
    'institution_id' => env('EDUFLOW_INSTITUTION_ID'),

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
