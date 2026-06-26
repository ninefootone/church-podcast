# Changelog

## [1.1.2] - 2026-06-24

### Fixed
- Audio file size and duration now populate on imported sermons. WP All Import does not fire `acf/save_post`, so the metadata-population step now also runs on `pmxi_saved_post`.

## [1.1.1] - 2026-06-24

### Fixed
- Podcast feed now reads the correct `resource_summary` field for episode descriptions (was `resources_summary`).
- Podcast feed now reads the correct `resource_bible_passages` field for the episode subtitle (was `resource_first_bible_passage`).

### Changed
- Podcast feed now returns all qualifying episodes from the last 12 months (rolling window) instead of a fixed 100-item cap. Older sermons remain available on the website.

## [1.1.0] — 2026-06-17

### Added
- Per-church podcast feeds at `/feed/podcast-church/{term-slug}/` when `resource-church` taxonomy is present and has terms
- ACF field group on `resource-church` taxonomy terms for per-church feed overrides (title, description, email, artwork, category, subcategory, language) — each falls back to global settings if left blank
- Settings page now lists all per-church feed URLs with direct links to edit church term settings
- Single-church installs with no `resource-church` taxonomy are completely unaffected — combined feed behaviour unchanged

### Fixed
- `atom:link` self-reference in channel header now correctly points to the feed being rendered (was always pointing to the combined feed URL)

## [1.0.2] — 2026-06-10

### Changed
- Test automated release via GitHub Actions

## [1.0.1] — 2026-06-10

### Changed
- Add GitHub Actions release workflow

## [1.0.0] — 2026-06-10

### Initial release
- Podcast feed at `/feed/podcast/`
- Settings page for feed metadata (title, description, artwork, category, language, email)
- Audio metadata extraction on upload (file size, duration)
- ACF field population on save for sermon resources
