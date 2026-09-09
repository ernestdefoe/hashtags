# Changelog

All notable changes to **ernestdefoe/hashtags**.

## 2.0.2 — 2026-09-09

### Fixed

- **Posting was broken on any install with a database table prefix.** `POST /api/discussions` returned a 500 with `SQLSTATE[42S22]: Unknown column 'post_hashtag.hashtag_id' in 'field list'`. `selectRaw()` is passed through verbatim — the query builder only prefixes identifiers it wraps itself — so in the counter-recount query the `from`, `join`, `where` and `group by` were correctly prefixed while the select list was not. Because that query runs from the post-save sync, every new post hit it, not just `hashtags:reindex`. Now interpolates `getTablePrefix()` into the raw select expressions, the same way core builds its own post-number expression.
- Installs **without** a table prefix were never affected by this and are unchanged.

### Notes

- `HashtagSyncer` is typed to the concrete `Connection` now, since `getTablePrefix()` is not declared on `ConnectionInterface`. Flarum binds the interface to that class, so container resolution is unchanged.
- Every other raw expression in the extension was audited; this was the only one carrying table names. `HashtagFilter`'s subquery and the Eloquent model paths are prefixed by the builder on their own.

No migration needed.

## 2.0.1 — 2026-09-09

### Fixed

- **Admin panel crashed on the extension's settings page.** `app.extensionData` is a Flarum 1.x API and does not exist on Flarum 2's `AdminApplication`, so registering the settings page threw `TypeError: Cannot read properties of undefined (reading 'for')` from inside the initializer — which takes down the extension's entire admin bundle, not just the page. The console reported `ernestdefoe-hashtags failed to initialize`. Now uses `new Extend.Admin().page(...)`, the Flarum 2 route.

### Changed

- `npm run check-typings` now runs clean. It needed two declaration files rather than one: an ambient `declare module 'ext:…'` is only legal in a global script file, while augmenting `ForumApplication` is only legal from a module file — sharing one file silently disables whichever of the two loses. Three expected `ext:`-related errors had been burying the real one.

### Not a bug

- `There are no commands defined in the "hashtags" namespace` when running `php flarum hashtags:reindex` is what a **disabled** extension reports. Enable the extension and the command appears. 2.0.1 makes the admin page usable again so it can be toggled normally.

No functional change to the forum side. Upgrading from 2.0.0 requires no migration.

## 2.0.0 — 2026-09-09

Initial release.

### Added

- Inline `#hashtag` parsing that coexists with `flarum/mentions` without patching it. A `#` matching a real tag slug stays a tag mention; anything else becomes a freeform hashtag. Precedence is positional, so it does not depend on extension load order.
- Case-insensitive matching with case-preserving display — `#GameDay` and `#gameday` are one hashtag, one feed, one canonical URL.
- `/hashtag/:name` feed, backed by a `hashtag:` discussion search filter so visibility scoping, pagination and sorting are core's own.
- `/hashtags` browse page with search and popular / recently used / A–Z, linked from the index navigation.
- `hashtag:` and `-hashtag:` filters in the search box.
- Shared `#` composer autocomplete offering tags and hashtags in one dropdown, when `flarum/mentions` is installed.
- Read-only `GET /api/hashtags`.
- `php flarum hashtags:reindex` to backfill forums installed after they already had posts, with `--dry-run` and `--chunk`.
- Unicode hashtags, logical-property styling for RTL, light and dark themes.

### Notes

- Safe by construction inside code spans, fenced blocks, indented blocks and URLs; `C#`, `F#`, `foo#bar` and `#42` are never matched.
- Hidden posts contribute nothing; deleting a post corrects the counts.
- No settings, deliberately — hashtags exist because a post says so.
