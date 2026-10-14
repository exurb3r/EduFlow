<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('skills design landing page returns successful response and renders inertia component', function (): void {
    $response = $this->get('/skills/design');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('skills/design')
        ->has('canRegister')
    );
});
