<x-filament-panels::page>
    <div class="mb-4 rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300">
        <p class="font-semibold flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-shield-exclamation" class="size-5" />
            Human-in-the-Loop Safeguard Active
        </p>
        <p class="mt-1 text-xs opacity-90">
            These payments exceeded EduFlow AI's autonomous threshold or triggered safety rules. As Finance Officer, you can review the AI rationale and approve or reject on-chain USDC execution.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
