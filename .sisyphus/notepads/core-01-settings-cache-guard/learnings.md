## Learnings
- `SettingsProvider::getSettings()` must treat cached settings as invalid unless they are a `Collection` containing `theme`.
- A poisoned cache can cascade into `@vite()` resolving a missing `public/build/manifest.json`, so the cache guard must self-heal.
