<x-filament-panels::page>
    <div class="fi-collections-workspace" wire:poll.10s>
        <x-filament::section icon="heroicon-o-document-text">
            <x-slot name="heading">Record fees received</x-slot>
            <x-slot name="description">Bring a receipt or completed collection report.</x-slot>

            <ol class="fi-collections-steps" aria-label="How collections work">
                <li>
                    <span class="fi-collections-step-number" aria-hidden="true">1</span>
                    <div>
                        <h3>Choose your proof</h3>
                        <p>Official receipt, bank credit confirmation, or cashier closing report. Use its existing receipt/report number.</p>
                    </div>
                </li>
                <li>
                    <span class="fi-collections-step-number" aria-hidden="true">2</span>
                    <div>
                        <h3>Record its totals</h3>
                        <p>Click <strong>Record collection</strong>. Upload the file, enter received amount, set-aside funds, and completed period.</p>
                    </div>
                </li>
                <li>
                    <span class="fi-collections-step-number" aria-hidden="true">3</span>
                    <div>
                        <h3>Get a separate review</h3>
                        <p>Another authorized staff member checks the record. Your own entry cannot be self-approved.</p>
                    </div>
                </li>
            </ol>

            <div class="fi-collections-scope">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <p>Records college collection totals, not individual student balances. Uploading or approving a receipt does not confirm a bank balance, fund Arc, or authorize payment.</p>
            </div>
        </x-filament::section>

        @php($overview = $this->collectionOverview())
        <dl class="fi-collections-review-counts" aria-label="Recorded review status">
            <div>
                <dt>Awaiting review</dt>
                <dd>{{ $overview['awaiting'] }}</dd>
            </div>
            <div>
                <dt>Recorded approvals</dt>
                <dd>{{ $overview['approved'] }}</dd>
            </div>
            <div>
                <dt>Held or rejected</dt>
                <dd>{{ $overview['held_or_rejected'] }}</dd>
            </div>
        </dl>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
