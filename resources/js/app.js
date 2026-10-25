/**
 * Updates and plugin installs restart the panel, so a poll can land while it is down and get a gateway error
 * from nginx or Cloudflare. Livewire would show that error page in a modal; skip it and let the next poll retry.
 */
document.addEventListener('livewire:init', () => {
    /**
     * Ask before running a wire:confirm action with the themed modal in partials/confirm-modal instead of the browser's
     * own dialog. Livewire's directive keeps the action until it is confirmed, so it can wait for the modal. Add
     * .danger for actions that delete or remove something. Should Livewire change how the directive works, buttons
     * fall back to the browser's dialog.
     */
    Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm' || directive.modifiers.includes('prompt') || ! el.__livewire_confirm) {
            return
        }

        let message = directive.expression.replaceAll('\\n', '\n') || 'Are you sure?'
        let [, question, details] = message.match(/^(.*?\?)\s+(.*)$/s) ?? [null, message, '']

        el.__livewire_confirm = (action, instead) => {
            // The click is held back until the modal answers, so nothing else acts on it in the meantime.
            instead()

            window.dispatchEvent(new CustomEvent('swage-confirm', {
                detail: {
                    question,
                    details,
                    label: el.textContent.trim(),
                    danger: directive.modifiers.includes('danger'),
                    action,
                },
            }))
        }
    })

    Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if ([502, 503, 504].includes(response.status)) {
                preventDefault()
            }
        })
    })
})
