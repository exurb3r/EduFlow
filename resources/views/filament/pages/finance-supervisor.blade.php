<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Planning and review — never execution</x-slot>
        <x-slot name="description">
            Background finance produces evidence-bound proposals for staff review. Accepting a plan is not payment authorization.
        </x-slot>
        <x-filament::badge color="warning">Non-executable workspace</x-filament::badge>
        <p>
            Accepting a plan changes no accounts and sends no transfer. Preparing an accepted bill for payment review
            may hold capacity against independently reviewed funding; exact payment approval stays separate.
            USDC valuations are reference values, not verified settlement funding. Legacy agents cannot run from this page.
        </p>
    </x-filament::section>

    <div wire:poll.10s>
        {{ $this->operations }}
    </div>

    {{ $this->table }}
</x-filament-panels::page>
