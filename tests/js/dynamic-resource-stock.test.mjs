import assert from 'node:assert/strict'
import test from 'node:test'

import dynamicResourceStock, {
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
                data: {
                    available: true,
                    adjusted: false,
                    selection: { memory: 23552, cpu: 300, disk: 20480 },
                    bounds: {
                        memory: {
                            config_option_id: 12,
                            min: 1024,
                            max: 23552,
                            configured_max: 32768,
                            step: 1024,
                        },
                    },
                },
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

test('an adjusted quote stays locked until its clamped selection is requoted', async () => {
    installBrowserGlobals()
    globalThis.fetch = async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            data: {
                available: true,
                adjusted: true,
                selection: { memory: 23552, cpu: 300, disk: 20480 },
                bounds: {},
            },
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
                    data: {
                        available: true,
                        adjusted: false,
                        selection: { memory: 8192 },
                        bounds: {},
                    },
                }),
            }
        }

        return {
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    available: true,
                    adjusted: false,
                    selection: { memory: 16384 },
                    bounds: {},
                },
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
