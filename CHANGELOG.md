# Changelog

All notable changes to `smith89k/laravel-post-mcp` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-11-26

### Added

- Every list detail can grow on existing items, not only described ones: the entry shape is guessed from saved items, and a "Can add more to existing ones" switch per list on the settings page (or `grow => false` in the config) turns it off.
- New links are added to an existing related item (matched by address); details and links it already has are still never changed or removed.
- The connector lives at `/laravel-post-mcp` (was `/mcp/posts`); opening it in a browser leads to the settings page.
- Settings page at `/laravel-post-mcp/settings`: choose Hidden, Can read or Can edit for each field, a name and note for the AI app, and whether it can add new related items, inside the limits of the config. Needs the new `post_mcp_settings` migration.
- Relation `details`: Claude can send a related item as an object to fill in a new item's other columns (text, lists of links, images from a public URL) while writing the post. Existing items only get their empty details. `get_post_fields`, the tool input and the settings page list them.
- Settings page redesigned as a control panel: a light for every field, three-position switches, a part-by-part list under each link with a switch for every detail Claude fills in, and plain words for field kinds.
- Package logo in `art/logo.svg`, also used as the settings page icon.
- Consent screen in the same style: it says what Claude can edit and read with the current settings, links to the settings page, and shows where you go back to.
- Works with any AI app that supports MCP connectors, not just Claude: the pages call the connected app by its own name (or "your AI app"), list every app connected now, and desktop apps such as Cursor and VS Code can sign in (`auth.app_schemes`). Wording, commands and README are app-neutral.

### Fixed

- A list of related items is checked in full before anything is created, so one bad item no longer leaves the earlier ones behind.
- Creating a related item that needs more than a name now returns a readable error instead of a database error.

## [0.1.0] - 2026-11-24

### Added

- MCP server for an existing Eloquent post model, built on `laravel/mcp`.
- Field discovery from the table schema, `$fillable`/`$guarded`/`$hidden`, `$casts`, enums, relationships and FormRequest rules, with config overrides and caching.
- Tools: `get_post_fields`, `list_posts`, `get_post`, `create_post`, `update_post`, `publish_post`, `unpublish_post`, `schedule_post`, `upload_image` and an opt-in `delete_post`.
- Status handling for a boolean flag or a string/enum status column, with an optional publish date for scheduling.
- Slugs made from the title, kept unique and away from reserved words.
- Writable `belongsTo` and `belongsToMany` relations by label or id, with optional creation of new values.
- Media drivers: filesystem disk, spatie/laravel-medialibrary or a custom class. URL uploads refuse private addresses.
- Replaceable `PostWriter` so saves go through the site's own services; model events always fire with the default writer.
- OAuth 2.1 through Laravel Passport (claude.ai, Claude Desktop, Claude Code) and token mode.
- Gate check on every tool call (everyone is denied until the gate is defined), rate limiting and an audit log table with an optional log channel.
- `post-mcp:install`, `post-mcp:fields` and `post-mcp:token` commands.

[Unreleased]: https://github.com/smith89k/laravel-post-mcp/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/smith89k/laravel-post-mcp/releases/tag/v0.1.0
