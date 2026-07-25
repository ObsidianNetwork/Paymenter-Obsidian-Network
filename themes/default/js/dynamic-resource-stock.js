const safeMessage = 'Live resource availability is temporarily unavailable. Please try again.'

function normalizeInteger(value) {
    if (typeof value === 'number' && Number.isSafeInteger(value)) {
        return value
    }

    if (typeof value !== 'string' || !/^-?\d+$/.test(value)) {
        return null
    }

    const parsed = Number(value)

    return Number.isSafeInteger(parsed) ? parsed : null
}

export function snapToStep(value, min, max, step) {
    const numericValue = normalizeInteger(value)
    const numericMin = normalizeInteger(min)
    const numericMax = normalizeInteger(max)
    const numericStep = normalizeInteger(step)

    if (
        numericValue === null
        || numericMin === null
        || numericMax === null
        || numericStep === null
        || numericStep <= 0
        || numericMax < numericMin
    ) {
        throw new Error('Invalid dynamic resource bound.')
    }

    const clamped = Math.min(numericMax, Math.max(numericMin, numericValue))

    return numericMin + Math.floor((clamped - numericMin) / numericStep) * numericStep
}

function responseMessage(response, payload) {
    if (response.status === 409) {
        return payload?.message || 'The selected location does not currently have enough capacity.'
    }

    if (response.status === 422) {
        return payload?.message || 'Choose a valid resource combination before continuing.'
    }

    return safeMessage
}

export default function dynamicResourceStock({ endpoint, cartItemId = null }) {
    return {
        endpoint,
        cartItemId,
        quoteState: 'loading',
        quoteError: '',
        latestQuote: null,
        _requestId: 0,
        _controller: null,
        _quoteTimer: null,
        _adjustmentPasses: 0,

        init() {
            this.$nextTick(() => this.requestQuote())
        },

        get canCheckout() {
            return this.quoteState === 'ready' && this.latestQuote?.available === true
        },

        queueQuote(delay = 250) {
            window.clearTimeout(this._quoteTimer)
            this.quoteState = 'loading'
            this.quoteError = ''
            window.dispatchEvent(new CustomEvent('dynamic-capacity-loading'))
            this._quoteTimer = window.setTimeout(() => this.requestQuote(), delay)
        },

        async requestQuote() {
            if (!this.endpoint) {
                this.failQuote(safeMessage)
                return
            }

            const requestId = ++this._requestId
            this._controller?.abort()
            this._controller = new AbortController()
            this.quoteState = 'loading'
            this.quoteError = ''
            window.dispatchEvent(new CustomEvent('dynamic-capacity-loading'))
            let timedOut = false
            const timeoutId = window.setTimeout(() => {
                if (requestId === this._requestId) {
                    timedOut = true
                    this._controller?.abort()
                }
            }, 10000)

            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: this._controller.signal,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        config_options: this.$wire.get('configOptions'),
                        cart_item_id: this.cartItemId,
                    }),
                })
                const payload = await response.json().catch(() => ({}))

                if (requestId !== this._requestId) {
                    return
                }

                if (!response.ok || payload?.data?.available !== true) {
                    throw new Error(responseMessage(response, payload))
                }

                const quote = payload.data
                this.latestQuote = quote
                this.quoteError = ''
                window.dispatchEvent(new CustomEvent('dynamic-capacity-updated', {
                    detail: quote,
                }))

                if (quote.adjusted === true) {
                    if (this._adjustmentPasses >= 2) {
                        this.failQuote(
                            'Live capacity changed repeatedly. Review the selected resources and try again.',
                        )

                        return
                    }

                    this._adjustmentPasses++
                    this.quoteState = 'loading'
                    this.$nextTick(() => this.queueQuote(0))

                    return
                }

                this._adjustmentPasses = 0
                this.quoteState = 'ready'
            } catch (error) {
                if (requestId !== this._requestId) {
                    return
                }
                if (error?.name === 'AbortError' && !timedOut) {
                    return
                }

                this.failQuote(error?.message || safeMessage)
            } finally {
                window.clearTimeout(timeoutId)
            }
        },

        failQuote(message) {
            this.latestQuote = null
            this.quoteState = 'error'
            this.quoteError = message || safeMessage
            window.dispatchEvent(new CustomEvent('dynamic-capacity-failed', {
                detail: { message: this.quoteError },
            }))
        },
    }
}
