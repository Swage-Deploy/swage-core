{{-- Asks before running a wire:confirm action, in place of the browser's own dialog. resources/js/app.js opens it. --}}
<div
    x-data="{ question: '', details: '', label: '', danger: false, action: null }"
    x-on:swage-confirm.window="
        question = $event.detail.question;
        details = $event.detail.details;
        label = $event.detail.label || @js(__('Confirm'));
        danger = $event.detail.danger;
        action = $event.detail.action;
        $flux.modal('confirm-action').show();
    "
>
    <flux:modal name="confirm-action" class="w-full max-w-md" x-on:close="action = null">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" x-text="question"></flux:heading>
                <flux:subheading x-show="details" x-text="details" class="mt-2 whitespace-pre-line"></flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    x-show="! danger"
                    variant="primary"
                    x-on:click="let run = action; action = null; $flux.modal('confirm-action').close(); run?.()"
                    data-test="confirm-action-button"
                ><span x-text="label"></span></flux:button>
                <flux:button
                    x-show="danger"
                    variant="danger"
                    x-on:click="let run = action; action = null; $flux.modal('confirm-action').close(); run?.()"
                    data-test="confirm-danger-action-button"
                ><span x-text="label"></span></flux:button>
            </div>
        </div>
    </flux:modal>
</div>
