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

document.addEventListener('alpine:init', () => {
    /**
     * Upload a dump for a database restore in chunks, each small enough to pass a proxy in front of the panel, such as
     * Cloudflare, which refuses requests over 100 MB. Chunks go one at a time and are retried when the connection drops.
     * Once the whole file has arrived, the upload is handed to the Livewire component to restore from.
     */
    Alpine.data('databaseRestoreUpload', (url, csrfToken) => ({
        uploading: false,
        uploaded: false,
        progress: 0,
        error: '',
        request: null,
        attempt: 0,

        destroy() {
            this.request?.abort()
        },

        async upload(file) {
            this.request?.abort()

            let attempt = ++this.attempt
            Object.assign(this, { uploading: false, uploaded: false, progress: 0, error: '' })
            this.$wire.set('restoreUpload', '')

            if (! file) {
                return
            }

            this.uploading = true

            try {
                let { id, chunkSize } = await this.send('POST', url, JSON.stringify({ name: file.name, size: file.size }), 'application/json')
                let offset = 0
                let failures = 0

                while (offset < file.size) {
                    try {
                        let { received } = await this.send('PUT', `${url}/${id}?offset=${offset}`, file.slice(offset, offset + chunkSize), 'application/octet-stream', (loaded) => {
                            this.progress = Math.floor((offset + loaded) / file.size * 100)
                        })

                        offset = received
                        failures = 0
                    } catch (error) {
                        // The panel has the file up to somewhere else, such as when a chunk arrived but its response was lost.
                        if (error.status === 409) {
                            offset = error.body.received

                            continue
                        }

                        if (error.aborted || (error.status >= 400 && error.status < 500) || ++failures > 3) {
                            throw error
                        }

                        await new Promise((resolve) => setTimeout(resolve, failures * 2000))
                    }
                }

                if (attempt === this.attempt) {
                    this.progress = 100
                    this.uploaded = true
                    await this.$wire.set('restoreUpload', id)
                }
            } catch (error) {
                if (attempt === this.attempt && ! error.aborted) {
                    this.error = error.message
                }
            } finally {
                if (attempt === this.attempt) {
                    this.uploading = false
                    this.request = null
                }
            }
        },

        send(method, target, body, type, onProgress = null) {
            return new Promise((resolve, reject) => {
                let request = this.request = new XMLHttpRequest()

                request.open(method, target)
                request.setRequestHeader('Accept', 'application/json')
                request.setRequestHeader('Content-Type', type)
                request.setRequestHeader('X-CSRF-TOKEN', csrfToken)

                if (onProgress) {
                    request.upload.onprogress = (event) => onProgress(event.loaded)
                }

                request.onload = () => {
                    let response = null

                    try {
                        response = JSON.parse(request.responseText)
                    } catch {}

                    if (request.status >= 200 && request.status < 300 && response) {
                        resolve(response)
                    } else {
                        reject(this.failure(request.status, response))
                    }
                }

                request.onerror = () => reject(this.failure(0, null))
                request.onabort = () => reject(Object.assign(this.failure(0, null), { aborted: true }))

                request.send(body)
            })
        },

        failure(status, body) {
            let message = {
                0: 'The upload was interrupted. Check your connection and choose the file again.',
                413: 'The upload was refused as too large, possibly by a proxy in front of the panel.',
                419: 'Your session has expired. Reload the page and try again.',
            }[status] ?? (status === 422 && body?.errors
                ? Object.values(body.errors)[0][0]
                : `The upload failed with status ${status}. Choose the file again to retry.`)

            return Object.assign(new Error(message), { status, body })
        },
    }))
})
