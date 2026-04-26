export default function dynamicSliderGroup ({ productId, planId, locationId = null, initialToken = null }) {
    return {
        productId,
        planId,
        locationId,
        initialToken,
        memory: null,
        cpu: null,
        disk: null,
        token: null,
        error: null,
        loading: false,
        status: '',
        retryCount: 0,
        retryTimer: null,
        debounceTimer: null,

        init () {
            this.token = this.readStoredToken() || this.initialToken || null
            this.syncTokenToLivewire(this.token)

            this.$watch('planId', () => {
                this.clearTimers()
                this.error = null
                this.status = ''
                this.retryCount = 0
                this.token = this.readStoredToken() || null
                this.syncTokenToLivewire(this.token)
                this.dispatchReservationState()
            })

            this.$watch('token', value => {
                this.storeToken(value)
                this.syncTokenToLivewire(value)
            })

            this.$el.addEventListener('slider-change', event => {
                const { resourceType, value, initialize } = event.detail || {}
                if (!resourceType) {
                    return
                }

                this[resourceType] = Number(value)
                if (initialize) {
                    return
                }

                this.scheduleReservation(500)
            })

            this.dispatchReservationState()
        },

        clearTimers () {
            if (this.debounceTimer) {
                clearTimeout(this.debounceTimer)
            }

            if (this.retryTimer) {
                clearTimeout(this.retryTimer)
            }
        },

        get currentPlanId () {
            const numericPlanId = Number(this.planId)

            return Number.isFinite(numericPlanId) && numericPlanId > 0 ? numericPlanId : null
        },

        get currentLocationId () {
            const numericLocationId = Number(this.locationId)

            return Number.isFinite(numericLocationId) && numericLocationId > 0 ? numericLocationId : null
        },

        get storageKey () {
            return `dp_reservation_token_${this.productId}_${this.currentPlanId ?? 'unknown'}`
        },

        get hasAllResources () {
            return [this.memory, this.cpu, this.disk].every(value => Number.isFinite(Number(value)) && Number(value) >= 0)
        },

        readStoredToken () {
            try {
                return window.sessionStorage.getItem(this.storageKey)
            } catch {
                return null
            }
        },

        storeToken (value) {
            try {
                if (!value) {
                    window.sessionStorage.removeItem(this.storageKey)

                    return
                }

                window.sessionStorage.setItem(this.storageKey, value)
            } catch {
                // Ignore storage failures and keep the in-memory token.
            }
        },

        getGuestReservationKey () {
            try {
                const existing = window.sessionStorage.getItem('dp_reservation_guest_key')
                if (existing) {
                    return existing
                }

                const generated = window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
                window.sessionStorage.setItem('dp_reservation_guest_key', generated)

                return generated
            } catch {
                return `${Date.now()}-${Math.random().toString(36).slice(2)}`
            }
        },

        getIdempotencyKey () {
            const cartCookie = document.cookie
                .split('; ')
                .find(cookie => cookie.startsWith('cart='))
                ?.split('=')[1]

            return `${cartCookie || this.getGuestReservationKey()}-${this.productId}-${this.currentPlanId}-${this.currentLocationId}`
        },

        syncTokenToLivewire (value) {
            if (!this.$wire?.set) {
                return
            }

            this.$wire.set('checkoutConfig.dp_reservation_token', value || null)
        },

        dispatchReservationState () {
            const detail = {
                error: this.error,
                loading: this.loading,
                token: this.token,
                status: this.status,
            }

            window.dispatchEvent(new CustomEvent('dp-reservation-state', { detail }))
            if (this.error) {
                window.dispatchEvent(new CustomEvent('dp-reservation-error', { detail }))
            } else {
                window.dispatchEvent(new CustomEvent('dp-reservation-clear', { detail }))
            }
        },

        notify (message, type = 'error') {
            const store = window.Alpine?.store?.('notifications')
            if (!store?.addNotification) {
                return
            }

            store.addNotification([{ message, type }])
        },

        scheduleReservation (delay = 500) {
            this.clearTimers()

            if (!this.currentPlanId || !this.currentLocationId || !this.hasAllResources) {
                return
            }

            this.loading = true
            this.status = ''
            this.dispatchReservationState()

            this.debounceTimer = setTimeout(() => {
                this.createReservation()
            }, delay)
        },

        async createReservation () {
            try {
                const response = await fetch('/api/dynamic-pterodactyl/reservation', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Idempotency-Key': this.getIdempotencyKey(),
                    },
                    body: JSON.stringify({
                        product_id: this.productId,
                        plan_id: this.currentPlanId,
                        location_id: this.currentLocationId,
                        memory: Number(this.memory),
                        cpu: Number(this.cpu),
                        disk: Number(this.disk),
                    }),
                })

                const json = await response.json().catch(() => ({}))

                if (response.ok) {
                    this.retryCount = 0
                    this.error = null
                    this.status = ''
                    this.token = json?.data?.token || null
                    this.loading = false
                    this.dispatchReservationState()

                    return
                }

                if (response.status === 422) {
                    const firstError = json?.errors
                        ? Object.values(json.errors).flat()[0]
                        : null

                    this.error = firstError || json?.message || 'Insufficient capacity at this location.'
                    this.status = ''
                    this.token = null
                    this.loading = false
                    this.dispatchReservationState()

                    return
                }

                if (response.status === 429) {
                    const delay = Math.min(30000, 6000 * Math.max(1, this.retryCount + 1))
                    this.retryCount += 1
                    this.error = null
                    this.status = 'Updating capacity hold…'
                    this.loading = false
                    this.dispatchReservationState()
                    this.retryTimer = setTimeout(() => this.scheduleReservation(0), delay)

                    return
                }

                throw new Error(json?.message || 'Reservation temporarily unavailable')
            } catch (error) {
                console.error('Dynamic reservation request failed', error)
                this.error = null
                this.status = ''
                this.loading = false
                this.dispatchReservationState()
                this.notify('Capacity check temporarily unavailable; provisioning will verify on completion.', 'error')
            }
        },
    }
}
