<?php

declare(strict_types=1);

test('JSON boolean round trip preserves true', function (): void {
    expect(json_decode(json_encode(true, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBeTrue();
});
