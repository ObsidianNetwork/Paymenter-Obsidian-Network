# dp-03 Hotfix: `ConfigOptionSetupService.php` missing brace

## Context

Branch `dp-03-audit-log-coverage` HEAD `5481720` on fork `Jordanmuss99/dynamic-pterodactyl` ships a parse error that blocks extension boot.

The round-3 audit-coverage patch wrapped an `AuditLogService::log()` call in `try/catch` inside an outer `if (! empty($created))` block but only emitted one closing brace where two are required. The file fails `php -l`:

```
PHP Parse error:  syntax error, unexpected token "public"
  in Services/ConfigOptionSetupService.php on line 92
```

PHPUnit's autoloader never touches `ConfigOptionSetupService` in the current test suite, so the full 42/42 green run did not catch this. The file is loaded on product-config setup in production, so the extension will fatal on first use.

The independent reviewer caught this; this hotfix amends the existing `5481720` tip and force-pushes.

## Target file

`/var/www/paymenter/extensions/Others/DynamicPterodactyl/Services/ConfigOptionSetupService.php`

## Current state (lines 74-87, verbatim)

```php
        if (! empty($created)) {
            /** @var \Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService $audit */
            $audit = app(\Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService::class);
            try {
                $audit->log('setup_run', 'product_config', $productId, [
                    'sliders_configured' => array_keys($created),
                    'count' => count($created),
                ]);
            } catch (\Throwable $e) {
                report($e);
        }

        return $created;
    }
```

The `catch` body is followed by ONE `}` (column 8) and then `return $created;` / `}`. That single brace closes the `catch` block body but not the outer `if (! empty($created))`. The method's final `}` then closes the `if`, leaving `createDynamicSliderOptions()` syntactically unterminated — so the parser hits `public function createResourceOption` at line 92 inside the still-open method and errors.

## Required state

Insert ONE additional closing brace at correct indentation so the structure becomes:

```php
        if (! empty($created)) {
            /** @var \Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService $audit */
            $audit = app(\Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService::class);
            try {
                $audit->log('setup_run', 'product_config', $productId, [
                    'sliders_configured' => array_keys($created),
                    'count' => count($created),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $created;
    }
```

Diff intent (bottom of the `catch`/`if`):

```
             } catch (\Throwable $e) {
                 report($e);
-        }
+            }
+        }

         return $created;
     }
```

Two braces where there was one: the inner `}` closes the `catch` body (12-col indent), the outer `}` closes `if (! empty($created))` (8-col indent).

## Steps

1. `cd /var/www/paymenter/extensions/Others/DynamicPterodactyl`
2. Read lines 70-95 of `Services/ConfigOptionSetupService.php` to confirm file state still matches the excerpt above (bail if drifted).
3. Edit the brace structure as specified. Use `mcp__oc__edit` with LINE#ID anchors — targeted replace of the broken closing-brace line only, OR an insertion anchored to `report($e);`.
4. Run `php -l Services/ConfigOptionSetupService.php`. MUST print `No syntax errors detected in Services/ConfigOptionSetupService.php`. If it does not, stop and report.
5. Run full extension test suite from extension directory: `../../../vendor/bin/phpunit`. MUST pass 42/42. If any test fails, stop and report.
6. Stage narrowly: `git add Services/ConfigOptionSetupService.php`. Do NOT `git add .` or `git add -A`. `AGENTS.md` must remain untracked. Confirm `git status` shows only the one staged file before proceeding.
7. Amend the existing commit (do NOT create a new commit):
   ```
   git -c user.name=Jordanmuss99 \
       -c user.email=164892154+Jordanmuss99@users.noreply.github.com \
       commit --amend --no-edit --reset-author --date="$(date -R)"
   ```
8. Verify author on amended tip: `git log -1 --format='%an <%ae>'` → must equal `Jordanmuss99 <164892154+Jordanmuss99@users.noreply.github.com>`.
9. Force-push: `git push --force-with-lease origin dp-03-audit-log-coverage`.
10. Verify remote HEAD: `git rev-parse origin/dp-03-audit-log-coverage` equals `git rev-parse HEAD`.
11. Report: new SHA, `php -l` output, `phpunit` summary line, amended author line, remote rev-parse match.

## Hard constraints (do not violate)

- This file lives inside a nested git repo. `cd` into the extension directory before any `git` command. Never commit from `/var/www/paymenter`.
- Narrow `git add` only. `AGENTS.md` and any other untracked files must remain untracked.
- Do not modify any other file. Do not touch tests. Do not "improve" surrounding code. The scope is: add one closing brace.
- Do not create a new commit. Amend `5481720`.
- Do not push without `--force-with-lease`.
- Use the GH no-reply email above — not `jordanmuss@hotmail.com` — to avoid `GH007` push rejection.
- Filament 4, Paymenter core pricing, no skeleton edits — none of these apply to this hotfix but remain in force.

## Failure modes to bail on (report, do not improvise)

- File content at lines 74-87 does not match the current-state excerpt: STOP. Something rebased. Report and await instructions.
- `php -l` still reports a syntax error after the edit: STOP. Report.
- `phpunit` regresses: STOP. Report failing test names.
- `git add` picks up more than one file: STOP. Report `git status -s`.
- `git push` rejected: STOP. Report rejection reason verbatim.

## Acceptance criteria

- `php -l Services/ConfigOptionSetupService.php` → `No syntax errors detected`
- `../../../vendor/bin/phpunit` → 42/42 passing (or whatever the pre-hotfix count was — must not regress)
- `git log -1 --format='%H %an <%ae>'` on `dp-03-audit-log-coverage` shows new SHA with `Jordanmuss99 <164892154+...>`
- `git rev-parse HEAD` == `git rev-parse origin/dp-03-audit-log-coverage`
- `git status` clean except for pre-existing untracked (e.g. `AGENTS.md`)
