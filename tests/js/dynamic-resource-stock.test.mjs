import assert from 'node:assert/strict'
import test from 'node:test'

import dynamicResourceStock, {
    isCompleteResourceQuote,
    retryAfterDelaySeconds,
    retryWaitSecondsUntil,
    snapToStep,
} from '../../themes/default/js/dynamic-resource-stock.js'

function installBrowserGlobals() {
    const events = []

    globalThis.document = {
        querySelector: () => ({ content: 'csrf-token' }),
    }
    globalThis.window = {
        clearTimeout,
        setTimeout,
        dispatchEvent: (event) => events.push(event),
    }

    return events
}

function controller(overrides = {}) {
    return Object.assign(
        dynamicResourceStock({
            endpoint: '/api/dynamic-pterodactyl/products/7/resource-quote',
            cartItemId: 11,
            expectedBoundIds: [12, 13, 14],
        }),
        {
            $wire: {
                get: () => ({
                    12: 32768,
                    13: 300,
                    14: 20480,
                    15: 91,
                }),
            },
            $nextTick: (callback) => callback(),
        },
        overrides,
    )
}

function completeQuote({
    adjusted = false,
    memory = 23552,
    memoryMax = 23552,
} = {}) {
    return {
        available: true,
        adjusted,
        selection: {
            memory,
            cpu: 300,
            disk: 20480,
        },
        bounds: {
            memory: {
                config_option_id: 12,
                min: 1024,
                max: memoryMax,
                configured_max: 32768,
                step: 1024,
            },
            cpu: {
                config_option_id: 13,
                min: 100,
                max: 400,
                configured_max: 400,
                step: 100,
            },
            disk: {
                config_option_id: 14,
                min: 10240,
                max: 51200,
                configured_max: 51200,
                step: 1024,
            },
        },
    }
}

test('disabled stock mode leaves ordinary checkout enabled without quoting', async () => {
    installBrowserGlobals()
    let quoted = false
    globalThis.fetch = async () => {
        quoted = true
        throw new Error('ordinary products must not quote capacity')
    }

    const stock = controller({
        enabled: false,
        endpoint: null,
        quoteState: 'disabled',
    })
    stock.init()
    stock.queueQuote()
    await stock.requestQuote()

    assert.equal(stock.canCheckout, true)
    assert.equal(stock.quoteState, 'disabled')
    assert.equal(quoted, false)
})

test('step clamping honors both live capacity and the configured maximum', () => {
    assert.equal(snapToStep(32768, 1024, 23552, 1024), 23552)
    assert.equal(snapToStep(32768, 1024, 32768, 1024), 32768)
    assert.equal(snapToStep(23000, 1024, 23552, 1024), 22528)
})

test('a quote sends the complete resource vector and unlocks checkout', async () => {
    const events = installBrowserGlobals()
    let request
    globalThis.fetch = async (endpoint, options) => {
        request = { endpoint, options }

        return {
            ok: true,
            status: 200,
            json: async () => ({
                data: completeQuote(),
            }),
        }
    }

    const stock = controller()
    await stock.requestQuote()

    assert.equal(stock.canCheckout, true)
    assert.equal(stock.quoteState, 'ready')
    assert.equal(request.endpoint, stock.endpoint)
    assert.deepEqual(JSON.parse(request.options.body), {
        config_options: {
            12: 32768,
            13: 300,
            14: 20480,
            15: 91,
        },
        cart_item_id: 11,
    })
    assert.equal(events.at(-1).type, 'dynamic-capacity-updated')
})

test('a quote must contain valid bounds for the exact managed slider IDs', () => {
    const valid = completeQuote()
    assert.equal(isCompleteResourceQuote(valid, [12, 13, 14]), true)

    const missing = structuredClone(valid)
    delete missing.bounds.disk
    assert.equal(isCompleteResourceQuote(missing, [12, 13, 14]), false)

    const unexpected = structuredClone(valid)
    unexpected.bounds.disk.config_option_id = 99
    assert.equal(isCompleteResourceQuote(unexpected, [12, 13, 14]), false)

    const duplicate = structuredClone(valid)
    duplicate.bounds.disk.config_option_id = 13
    assert.equal(isCompleteResourceQuote(duplicate, [12, 13, 14]), false)

    const malformed = structuredClone(valid)
    malformed.bounds.memory.max = 23000
    assert.equal(isCompleteResourceQuote(malformed, [12, 13, 14]), false)

    const invalidSelection = structuredClone(valid)
    invalidSelection.selection.memory = 23000
    assert.equal(isCompleteResourceQuote(invalidSelection, [12, 13, 14]), false)
})

test('a partial successful response fails closed instead of unlocking checkout', async () => {
    const events = installBrowserGlobals()
    const partial = completeQuote()
    delete partial.bounds.cpu
    globalThis.fetch = async () => ({
        ok: true,
        status: 200,
        json: async () => ({ data: partial }),
    })

    const stock = controller()
    await stock.requestQuote()

    assert.equal(stock.quoteState, 'error')
    assert.equal(stock.canCheckout, false)
    assert.equal(stock.latestQuote, null)
    assert.equal(
        stock.quoteError,
        'Live resource availability is temporarily unavailable. Please try again.',
    )
    assert.equal(events.at(-1).type, 'dynamic-capacity-failed')
})

test('inventory failures keep checkout locked and expose only a safe message', async () => {
    const events = installBrowserGlobals()
    globalThis.fetch = async () => ({
        ok: false,
        status: 503,
        json: async () => ({
            message: 'upstream application key leaked details',
        }),
    })

    const stock = controller()
    await stock.requestQuote()

    assert.equal(stock.canCheckout, false)
    assert.equal(stock.quoteState, 'error')
    assert.equal(
        stock.quoteError,
        'Live resource availability is temporarily unavailable. Please try again.',
    )
    assert.equal(events.at(-1).type, 'dynamic-capacity-failed')
})

test('rate limits honor Retry-After and block immediate retry amplification', async () => {
    const events = installBrowserGlobals()
    globalThis.fetch = async () => ({
        ok: false,
        status: 429,
        headers: {
            get: (name) => name === 'Retry-After' ? '30' : null,
        },
        json: async () => ({
            message: 'internal limiter details must not be exposed',
        }),
    })

    const stock = controller()
    await stock.requestQuote()

    assert.equal(stock.canCheckout, false)
    assert.equal(stock.canRetry, false)
    assert.equal(stock.retryWaitSeconds, 30)
    assert.equal(
        stock.quoteError,
        'Availability checks are temporarily rate-limited. Retry in 30 seconds.',
    )
    assert.equal(events.at(-1).detail.retry_after, 30)

    let queued = false
    stock.queueQuote = () => {
        queued = true
    }
    stock.retryQuote()
    assert.equal(queued, false)
    window.clearTimeout(stock._retryCooldownTimer)
})

test('Retry-After parsing accepts dates and safely bounds bad values', () => {
    const now = Date.parse('2026-07-27T00:00:00Z')
    assert.equal(
        retryAfterDelaySeconds({
            headers: {
                get: () => 'Mon, 27 Jul 2026 00:00:12 GMT',
            },
        }, now),
        12,
    )
    assert.equal(
        retryAfterDelaySeconds({
            headers: { get: () => '999999' },
        }, now),
        300,
    )
    assert.equal(
        retryAfterDelaySeconds({
            headers: { get: () => 'not-a-date' },
        }, now),
        5,
    )
})

test('rate-limit retry countdown advances against the absolute deadline', () => {
    assert.equal(retryWaitSecondsUntil(31_000, 1_000), 30)
    assert.equal(retryWaitSecondsUntil(31_000, 2_001), 29)
    assert.equal(retryWaitSecondsUntil(31_000, 30_999), 1)
    assert.equal(retryWaitSecondsUntil(31_000, 31_000), 0)
    assert.equal(retryWaitSecondsUntil(Number.NaN, 1_000), 0)
})

test('changing a selection locks checkout before the debounced quote starts', () => {
    const events = installBrowserGlobals()
    const stock = controller({
        quoteState: 'ready',
        latestQuote: { available: true },
    })

    stock.queueQuote(60000)

    assert.equal(stock.canCheckout, false)
    assert.equal(stock.quoteState, 'loading')
    assert.equal(events.at(-1).type, 'dynamic-capacity-loading')
    window.clearTimeout(stock._quoteTimer)
})

test('retry resets adjustment history and requests the current selection immediately', () => {
    installBrowserGlobals()
    let queuedDelay = null
    const stock = controller({
        quoteState: 'error',
        quoteError: 'Temporary failure',
        _adjustmentPasses: 2,
        queueQuote: (delay) => {
            queuedDelay = delay
        },
    })

    stock.retryQuote()

    assert.equal(stock._adjustmentPasses, 0)
    assert.equal(queuedDelay, 0)
})

test('an adjusted quote stays locked until its clamped selection is requoted', async () => {
    installBrowserGlobals()
    globalThis.fetch = async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            data: completeQuote({ adjusted: true }),
        }),
    })

    const stock = controller({
        $nextTick: () => {},
    })
    await stock.requestQuote()

    assert.equal(stock.canCheckout, false)
    assert.equal(stock.quoteState, 'loading')
    assert.equal(stock._adjustmentPasses, 1)
})

test('a slower superseded response cannot overwrite the newest quote', async () => {
    installBrowserGlobals()
    let releaseFirst
    const firstResponse = new Promise((resolve) => {
        releaseFirst = resolve
    })
    let call = 0
    globalThis.fetch = async () => {
        call++
        if (call === 1) {
            await firstResponse

            return {
                ok: true,
                status: 200,
                json: async () => ({
                    data: completeQuote({ memory: 8192 }),
                }),
            }
        }

        return {
            ok: true,
            status: 200,
            json: async () => ({
                data: completeQuote({ memory: 16384 }),
            }),
        }
    }

    const stock = controller()
    const first = stock.requestQuote()
    const second = stock.requestQuote()
    await second
    releaseFirst()
    await first

    assert.equal(stock.latestQuote.selection.memory, 16384)
})

test('a queued selection invalidates an active quote before the debounce expires', async () => {
    const events = installBrowserGlobals()
    let releaseResponse
    let requestSignal
    globalThis.fetch = async (_endpoint, options) => {
        requestSignal = options.signal
        await new Promise((resolve) => {
            releaseResponse = resolve
        })

        return {
            ok: true,
            status: 200,
            json: async () => ({
                data: completeQuote({ memory: 8192 }),
            }),
        }
    }

    const stock = controller()
    const activeQuote = stock.requestQuote()
    stock.queueQuote(60000)

    assert.equal(requestSignal.aborted, true)
    assert.equal(stock.quoteState, 'loading')
    assert.equal(stock.canCheckout, false)

    // Even a transport that ignores AbortController must not allow the old
    // response to overwrite the locked state while the replacement is queued.
    releaseResponse()
    await activeQuote

    assert.equal(stock.quoteState, 'loading')
    assert.equal(stock.canCheckout, false)
    assert.equal(stock.latestQuote, null)
    assert.equal(
        events.filter((event) => event.type === 'dynamic-capacity-updated').length,
        0,
    )
    window.clearTimeout(stock._quoteTimer)
})

test('an error from a quote invalidated during debounce stays suppressed', async () => {
    const events = installBrowserGlobals()
    let rejectRequest
    globalThis.fetch = async () => new Promise((_resolve, reject) => {
        rejectRequest = reject
    })

    const stock = controller()
    const activeQuote = stock.requestQuote()
    stock.queueQuote(60000)
    rejectRequest(new Error('stale upstream failure'))
    await activeQuote

    assert.equal(stock.quoteState, 'loading')
    assert.equal(stock.quoteError, '')
    assert.equal(stock.canCheckout, false)
    assert.equal(
        events.filter((event) => event.type === 'dynamic-capacity-failed').length,
        0,
    )
    window.clearTimeout(stock._quoteTimer)
})
