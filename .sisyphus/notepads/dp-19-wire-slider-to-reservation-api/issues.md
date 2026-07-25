## 2026-04-27
- The extension phpunit suite initially failed because `paymenter_test` was missing both core and extension migrations; refreshing the test database resolved that harness issue.
- Intelephense diagnostics for the nested extension repo are noisy and report framework symbols as unresolved even when runtime tests are green.
