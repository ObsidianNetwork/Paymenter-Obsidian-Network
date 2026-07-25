# DynamicPterodactyl — Shortfall + State-Drift Admin Notifications

**Scope**: `/var/www/paymenter/extensions/Others/DynamicPterodactyl/`
**Type**: Implement the AlertService email path (stub at `AlertService::100`) and wire two critical `InvoicePaidListener` code paths to actually notify admin.

---

## Problem

Audit findings #7 + the `AlertService::100` email TODO. Two code paths currently fail silently where an admin would want to know:

1. **`InvoicePaidListener:73-76`** — when `verifyAvailability()` fails after payment. Server provisioning will likely fail, customer already paid. Current behaviour: `Log::error` + `continue`. Admin finds out from the support ticket.

2. **`InvoicePaidListener:89-98`** (added in `6dddb68`) — the state-drift branch. `confirm()` returned false (reservation was expired or cancelled between `verifyAvailability` and `confirm`). Current behaviour: `Log::warning`. Same blind spot.

Both paths have a `// TODO: notify admin` comment that this plan closes.

---

## Design

### Notification channels
`AlertService` today supports a webhook path (roughly working) and an email path (pure stub at line 100). Implement email via Laravel's `Mail::send`/`Notification` facade — match Paymenter core patterns first.

**Check Paymenter core first**:
- Grep: `grep -rn "Mail::to\|Notification::send\|->notify(" app/` under `/var/www/paymenter/app/` to see the canonical notification pattern.
- If core uses `Notification::send($admin, new SomeNotification())`, match that. If it uses `Mail::to($admin)->send(new Mailable())`, match that instead.
- Do NOT invent a new pattern.

### Recipients
- Primary: an `AlertConfig` entry configured with `notify_emails` array, or fall back to all admin users (`User::where('role', 'admin')->get()` or Paymenter's equivalent).
- Match how AlertConfig already describes notification targets — read the model and migration first.
- If AlertConfig has a `notification_email` column, use it; otherwise broadcast to admins.

### Payload shape
Build a Notification class (assuming Notification pattern):

`app/Notifications/ReservationShortfallNotification.php` → NO, put it inside the extension since it's extension-specific:

`extensions/Others/DynamicPterodactyl/Notifications/ReservationShortfallNotification.php`:
```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationShortfallNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $serviceId,
        public int $invoiceId,
        public array $reservationSnapshot,
        public string $reason, // 'insufficient_resources' | 'state_drift'
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Paymenter] Reservation shortfall — service ' . $this->serviceId)
            ->line('A paid invoice could not reconcile with its reservation.')
            ->line('Service ID: ' . $this->serviceId)
            ->line('Invoice ID: ' . $this->invoiceId)
            ->line('Reason: ' . $this->reason)
            ->line('Snapshot: memory=' . $this->reservationSnapshot['memory']
                . ' cpu=' . $this->reservationSnapshot['cpu']
                . ' disk=' . $this->reservationSnapshot['disk'])
            ->line('Action required: verify the provisioned server has correct resources or manually migrate.')
            ->action('View service', url('/admin/services/' . $this->serviceId));
    }
}
```

### AlertService changes
Implement the email path. Current webhook-only method probably looks like `notify(string $message)`. Add a typed method:

```php
public function notifyShortfall(
    int $serviceId,
    int $invoiceId,
    array $snapshot,
    string $reason,
): void {
    $recipients = $this->getAdminRecipients();
    foreach ($recipients as $recipient) {
        $recipient->notify(new ReservationShortfallNotification(
            $serviceId, $invoiceId, $snapshot, $reason,
        ));
    }

    // Also hit webhook if configured (existing behaviour).
    if ($this->webhookUrl) {
        $this->sendWebhook([
            'event' => 'reservation_shortfall',
            'service_id' => $serviceId,
            'invoice_id' => $invoiceId,
            'reason' => $reason,
            'snapshot' => $snapshot,
        ]);
    }
}

private function getAdminRecipients(): \Illuminate\Support\Collection
{
    // Match Paymenter core convention — read core admin-query pattern before finalising.
    return \App\Models\User::query()
        ->where('role', 'admin') // adjust to match Paymenter's schema
        ->get();
}
```

Kill the stubby comment at line 100. Replace with working implementation.

### Wire from `InvoicePaidListener`
At line 73-76 (shortfall path):
```php
if (!$available) {
    Log::error('Resources no longer available for paid service', [...]);

    app(AlertService::class)->notifyShortfall(
        serviceId: $service->id,
        invoiceId: $invoice->id,
        snapshot: [
            'memory' => $reservation->memory,
            'cpu' => $reservation->cpu,
            'disk' => $reservation->disk,
        ],
        reason: 'insufficient_resources',
    );

    continue;
}
```

At the state-drift `else` branch (line 89-98):
```php
} else {
    $current = $reservationService->getByToken($reservationToken);
    Log::warning('Reservation could not be confirmed (state drift)', [...]);

    app(AlertService::class)->notifyShortfall(
        serviceId: $service->id,
        invoiceId: $invoice->id,
        snapshot: [
            'memory' => $reservation->memory,
            'cpu' => $reservation->cpu,
            'disk' => $reservation->disk,
        ],
        reason: 'state_drift:' . ($current?->status ?? 'unknown'),
    );
}
```

Remove the `// TODO: notify admin` comments — they've been addressed.

---

## Testing

### Unit tests
Extend `tests/Unit/InvoicePaidListenerTest.php` (create if missing — audit said it was skipped previously, now we have a concrete reason to build it):

1. `test_shortfall_dispatches_notification`
   - Mock `ResourceCalculationService::verifyAvailability` to return false.
   - `Notification::fake()`.
   - Trigger listener.
   - Assert `Notification::assertSentTo($admin, ReservationShortfallNotification::class, function ($n) { return $n->reason === 'insufficient_resources'; });`
2. `test_state_drift_dispatches_notification`
   - Mock `ReservationService::confirm` to return false.
   - Same notification-assertion pattern with `reason: starts_with 'state_drift:'`.
3. `test_happy_path_does_not_notify`
   - Everything succeeds. `Notification::assertNothingSent()`.

### AlertService tests
Add `tests/Unit/AlertServiceTest.php`:
1. `test_notify_shortfall_emails_all_admins` — seed 2 admin users, `Notification::fake()`, assert 2 notifications sent.
2. `test_notify_shortfall_hits_webhook_when_configured` — `Http::fake()`, assert one POST to the webhook URL with expected JSON.
3. `test_notify_shortfall_skips_webhook_when_unconfigured`.

### Manual smoke test
1. Seed Pterodactyl with 1 node at 90% capacity.
2. Create reservation for 50% capacity (node_available=40%, reservation=50% → create fails — NOT the scenario we want).
3. Actually reproduce by: reservation created successfully, then admin out-of-band spins up another server consuming the reserved capacity, then user completes payment. `verifyAvailability` returns false → shortfall branch → email fires.

Easier: temporarily hardcode `return false` in `verifyAvailability()`, complete a test checkout, verify admin inbox.

---

## Risks

| Risk | Mitigation |
|---|---|
| Mail not configured in dev env | `Notification::fake()` in tests; document in extension README that Paymenter core must have mail configured. |
| Admin recipient query doesn't match Paymenter schema | Read Paymenter `User` model + `roles` relation before finalising; grep for existing admin-notification examples. |
| Dispatching notifications synchronously slows the listener | Mark notification `ShouldQueue` if Paymenter has a queue worker. Skip if core runs queues sync. |
| Duplicate notifications if listener re-fires | `Invoice\Paid` fires once per status change. Safe. |
| Empty admin list → nothing sent, no log | Add `if ($recipients->isEmpty()) { Log::warning('No admin recipients for alert'); }`. |

---

## Acceptance

- `AlertService::notifyShortfall()` exists, sends email to all admins, hits webhook when configured.
- `InvoicePaidListener` calls it from both failure branches.
- Mail template renders correctly (verify by `Notification::fake()` inspection or a local SMTP capture).
- No more `// TODO: notify admin` comments in `InvoicePaidListener`.
- No more email-stub comment in `AlertService::100`.

---

## Commit

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git add -A
git commit -m "feat(alerts): notify admins on reservation shortfall and state drift"
```

---

## Delegation

`task(category="deep", load_skills=[], run_in_background=true, ...)`

Branch setup (run before delegating):

```bash
cd /var/www/paymenter/extensions/Others/DynamicPterodactyl
git fetch origin
git checkout -b dp-04-shortfall-notifications origin/dynamic-slider
```

Agent reads:
- Paymenter core for admin-query / notification pattern (`grep -rn "Notification::send\|->notify(" /var/www/paymenter/app/`)
- `AlertService.php` fully
- `InvoicePaidListener.php` fully
- Existing `tests/Unit/*` for mocking style
- `Models/AlertConfig.php` for notification_email column presence

Then implements, tests, commits.

Publish for review:

```bash
git push -u origin dp-04-shortfall-notifications
gh pr create --base dynamic-slider --title "feat(alerts): notify admins on reservation shortfall and state drift" --fill
```

---

## Out of scope

## Sidebar: DI consistency cleanup (carried over from dp-03 reviewer round-3)

During dp-03 reviewer round-3, the pattern inconsistency for `AuditLogService` resolution was flagged:

- `ReservationService` — constructor injection (correct)
- `AlertConfigObserver` — constructor injection (correct)
- `ConfigOptionSetupService.php:76` — resolves inline via `app(AuditLogService::class)` (deviant)

Fold into dp-04 because shortfall notifications will touch `AlertService`, which is adjacent to the audit integration points. While adding the shortfall email path, convert `ConfigOptionSetupService` to accept `AuditLogService` via constructor:

```php
public function __construct(private AuditLogService $audit) {}
```

...and replace the inline `app(...)` call at the audit site with `$this->audit->log(...)`. Update any `new ConfigOptionSetupService(...)` callers or `$this->app->make(...)` resolutions — check callers with `grep -r 'new ConfigOptionSetupService\|ConfigOptionSetupService::class' extensions/Others/DynamicPterodactyl/`. Testability win: removes the `app()` facade call, letting tests inject a Mockery mock directly.

Not a blocker; purely consistency.

- Retry of failed provisioning — separate concern.
- In-app notifications to Filament bell icon — separate feature.
- Slack/Discord integrations beyond the existing webhook — extend later if demand.
- Customer notification (refund offer, reschedule) — big UX decision, separate plan.
