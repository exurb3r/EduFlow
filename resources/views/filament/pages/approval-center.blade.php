<x-filament-panels::page>
    <div class="mb-4 rounded-xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-800 dark:text-red-300">
        <p class="font-semibold flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-5" />
            Legacy path — not the authorized vendor payment route
        </p>
        <p class="mt-1 text-xs opacity-90">
            These escalations come from the earlier student-assistance demo, which uses legacy float amounts and pays without a reserved intent, a reviewed destination or verified Arc settlement. It is <strong>not</strong> the safe authorization path and is retained only so existing demo data stays readable.
        </p>
        <p class="mt-1 text-xs opacity-90">
            Vendor payments run in the finance panel: Payment Reviews requires a reserved hold, fresh step-up authentication, an independent reviewer and an exact bound snapshot. Start there instead.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
