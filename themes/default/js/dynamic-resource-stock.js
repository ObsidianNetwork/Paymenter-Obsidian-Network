const safeMessage = 'Live resource availability is temporarily unavailable. Please try again.'
const rateLimitMessage = 'Availability checks are temporarily rate-limited.'
const defaultRetryAfterSeconds = 5
const maxRetryAfterSeconds = 300

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

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
}

export function isCompleteResourceQuote(quote, expectedBoundIds) {
    if (
        !isRecord(quote)
        || quote.available !== true
        || typeof quote.adjusted !== 'boolean'
        || !isRecord(quote.selection)
        || !isRecord(quote.bounds)
        || !Array.isArray(expectedBoundIds)
        || expectedBoundIds.length === 0
    ) {
        return false
    }

    const expectedIds = expectedBoundIds.map(normalizeInteger)
    if (
        expectedIds.some((id) => id === null || id <= 0)
        || new Set(expectedIds).size !== expectedIds.length
    ) {
        return false
    }

    const bounds = Object.entries(quote.bounds)
    if (bounds.length !== expectedIds.length) {
        return false
    }

    const expectedIdSet = new Set(expectedIds)
    const seenIds = new Set()
    for (const [resource, bound] of bounds) {
        if (
            !['memory', 'cpu', 'disk'].includes(resource)
            || !isRecord(bound)
        ) {
            return false
        }

        const optionId = normalizeInteger(bound.config_option_id)
        const min = normalizeInteger(bound.min)
        const max = normalizeInteger(bound.max)
        const configuredMax = normalizeInteger(bound.configured_max)
        const step = normalizeInteger(bound.step)
        const selected = normalizeInteger(quote.selection[resource])

        if (
            optionId === null
            || !expectedIdSet.has(optionId)
            || seenIds.has(optionId)
            || min === null
            || max === null
            || configuredMax === null
            || step === null
            || selected === null
            || step <= 0
            || max < min
            || configuredMax < max
            || (max - min) % step !== 0
            || selected < min
            || selected > max
            || (selected - min) % step !== 0
        ) {
            return false
        }

        seenIds.add(optionId)
    }

    return seenIds.size === expectedIdSet.size
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

export function retryAfterDelaySeconds(response, currentTime = Date.now()) {
    const rawValue = response?.headers?.get?.('Retry-After')
    if (typeof rawValue !== 'string' || rawValue.trim() === '') {
        return defaultRetryAfterSeconds
    }

    const value = rawValue.trim()
    let seconds = null
    if (/^\d+$/.test(value)) {
        seconds = Number(value)
    } else {
        const retryAt = Date.parse(value)
        if (Number.isFinite(retryAt)) {
            seconds = Math.ceil((retryAt - currentTime) / 1000)
        }
    }

    if (!Number.isSafeInteger(seconds)) {
        return defaultRetryAfterSeconds
    }

    return Math.min(maxRetryAfterSeconds, Math.max(1, seconds))
}

export function retryWaitSecondsUntil(
    retryAvailableAt,
    currentTime = Date.now(),
) {
    if (
        !Number.isFinite(retryAvailableAt)
        || !Number.isFinite(currentTime)
    ) {
        return 0
    }

    return Math.max(
        0,
        Math.ceil((retryAvailableAt - currentTime) / 1000),
    )
}

export default function dynamicResourceStock({
    endpoint,
    cartItemId = null,
    enabled = true,
    expectedBoundIds = [],
}) {
    return {
        endpoint,
        cartItemId,
        enabled,
        expectedBoundIds,
        quoteState: enabled ? 'loading' : 'disabled',
        quoteError: '',
        latestQuote: null,
        _requestId: 0,
        _controller: null,
        _quoteTimer: null,
        _retryCooldownTimer: null,
        _retryAvailableAt: 0,
        _retryQueued: false,
        retryWaitSeconds: 0,
        _adjustmentPasses: 0,

        init() {
            if (!this.enabled) {
                return
            }

            this.$nextTick(() => this.requestQuote())
        },

        get canCheckout() {
            return !this.enabled
                || (this.quoteState === 'ready' && this.latestQuote?.available === true)
        },

        get canRetry() {
            return this.retryWaitSeconds === 0
        },

        retryQuote() {
            if (!this.canRetry) {
                return
            }

            this._adjustmentPasses = 0
            this.queueQuote(0)
        },

        queueQuote(delay = 250) {
            if (!this.enabled) {
                return
            }

            window.clearTimeout(this._quoteTimer)
            // Invalidate the in-flight quote immediately. Waiting until the
            // debounced replacement starts leaves a window where the previous
            // selection can resolve and re-enable checkout with stale bounds.
            this._requestId++
            this._controller?.abort()
            if (this._retryAvailableAt > Date.now()) {
                this._retryQueued = true
                this.quoteState = 'error'

                return
            }

            this.quoteState = 'loading'
            this.quoteError = ''
            window.dispatchEvent(new CustomEvent('dynamic-capacity-loading'))
            this._quoteTimer = window.setTimeout(() => this.requestQuote(), delay)
        },

        async requestQuote() {
            if (!this.enabled) {
                return
            }

            if (this._retryAvailableAt > Date.now()) {
                this._retryQueued = true
                this.quoteState = 'error'

                return
            }

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

                if (response.status === 429) {
                    this.failRateLimitedQuote(
                        retryAfterDelaySeconds(response),
                    )

                    return
                }

                if (!response.ok || payload?.data?.available !== true) {
                    throw new Error(responseMessage(response, payload))
                }

                const quote = payload.data
                if (!isCompleteResourceQuote(quote, this.expectedBoundIds)) {
                    throw new Error(safeMessage)
                }

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
            this.clearRetryCooldown()
            this.latestQuote = null
            this.quoteState = 'error'
            this.quoteError = message || safeMessage
            window.dispatchEvent(new CustomEvent('dynamic-capacity-failed', {
                detail: { message: this.quoteError },
            }))
        },

        failRateLimitedQuote(seconds) {
            this.clearRetryCooldown()
            this.latestQuote = null
            this.quoteState = 'error'
            this.retryWaitSeconds = seconds
            this._retryAvailableAt = Date.now() + (seconds * 1000)
            this.quoteError = `${rateLimitMessage} Retry in ${seconds} seconds.`
            window.dispatchEvent(new CustomEvent('dynamic-capacity-failed', {
                detail: {
                    message: this.quoteError,
                    retry_after: seconds,
                },
            }))
            this.scheduleRetryCooldownTick()
        },

        scheduleRetryCooldownTick() {
            window.clearTimeout(this._retryCooldownTimer)
            const remaining = retryWaitSecondsUntil(
                this._retryAvailableAt,
            )
            this.retryWaitSeconds = remaining
            if (remaining === 0) {
                this._retryCooldownTimer = null
                this._retryAvailableAt = 0
                if (this._retryQueued) {
                    this._retryQueued = false
                    this.queueQuote(0)

                    return
                }

                if (this.quoteState === 'error') {
                    this.quoteError = `${rateLimitMessage} You can retry now.`
                }

                return
            }

            this.quoteError = `${rateLimitMessage} Retry in ${remaining} seconds.`
            const millisecondsUntilNextSecond = Math.max(
                1,
                (this._retryAvailableAt - Date.now())
                    - ((remaining - 1) * 1000),
            )
            this._retryCooldownTimer = window.setTimeout(
                () => this.scheduleRetryCooldownTick(),
                Math.min(1000, millisecondsUntilNextSecond),
            )
        },

        clearRetryCooldown() {
            window.clearTimeout(this._retryCooldownTimer)
            this._retryCooldownTimer = null
            this._retryAvailableAt = 0
            this._retryQueued = false
            this.retryWaitSeconds = 0
        },
    }
}
