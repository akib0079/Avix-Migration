# Changelog

All notable changes to Avix Migration are recorded here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.10]

### Fixed
- "Create backup" and other anchor-styled buttons rendered with an invisible
  label. The generic link colour rule outweighed the button variant on
  specificity, painting the brand colour onto a brand-coloured fill.
- Destructive buttons lost their label on hover: the shared hover rule
  repainted the fill with the neutral surface colour while the label stayed
  white.

## [1.0.9]

### Fixed
- **Save schedule and Start backup did nothing.** The shared destinations
  partial contained a `<form>` and was embedded inside the Backup and
  Schedules forms. HTML forbids nested forms — the parser drops the inner
  start tag but honours its `</form>`, closing the outer form early and
  leaving each page's own submit button attached to no form, so clicking it
  fired no submit event. The partial no longer contains a form element.

## [1.0.8]

### Fixed
- Disabled buttons were unreadable. A blanket `opacity` faded fill and label
  together, leaving a white label on a pale tint. Replaced with an explicit
  muted style that stays legible.

## [1.0.7]

### Fixed
- Modals rendered without a background, and their buttons without fill or
  label. Modals and toasts are appended to `document.body`, outside the
  container on which the design tokens were declared, so every `var()`
  reference resolved to nothing. Tokens are now declared on `:root` as well,
  and body-level components carry explicit fallbacks.

## [1.0.6]

### Added
- **Tools → Database check.** Performs the same insert WordPress does when
  saving a media upload and reports the database's real error, rather than the
  generic "Could not insert attachment into the database" wp-admin shows.
  Includes a per-table report with `AUTO_INCREMENT` versus current maximum ID.

## [1.0.5]

### Added
- Rollback snapshots can be restored directly from **Tools**, reconstructing
  the mapping from table names so recovery does not depend on the failed job
  still existing.
- The snapshot list identifies which entry holds the original site content
  when several imports failed in sequence.

### Changed
- Dropping a snapshot now requires typed confirmation and states that the
  tables may be the only remaining copy of the site's content.

## [1.0.3]

### Fixed
- **Concurrent job execution.** Browser polling could overlap when a tick
  outran the poll interval, running the same step twice in parallel. Two ticks
  entering the rollback snapshot within the same second derived identical
  table names and the second failed with "table already exists". Jobs now take
  an exclusive file lock and re-read state after acquiring it.

### Changed
- The rollback snapshot never renames over an existing backup table, and pins
  its timestamp to the job so a retry reuses one snapshot rather than creating
  a second.

## [1.0.2]

### Fixed
- **Imports failed on any site using Action Scheduler** (WooCommerce and
  similar) with a fatal "tried to modify a property on an incomplete object".
  Serialized objects are no longer unserialized at all: payloads containing an
  object token are rewritten at the byte level, recomputing each string length
  prefix while leaving class names intact.
- Nested and double-serialized payloads had their inner length prefixes left
  stale, producing silently corrupt data.

### Added
- Step failures record the exception class, message, originating `file:line`
  and stack frames to the job log.
- Search-replace processes each row inside its own error boundary, so a single
  unrewritable value cannot abort a restore that has already replayed the
  database.

## [1.0.1]

### Changed
- Brand palette and single light theme.

## [1.0.0]

### Added
- Full-site backup and restore across differing domains and table prefixes.
- Selective content export/import with dependency resolution for media,
  page-builder templates and taxonomy terms.
- Resumable, chunked export and import pipeline.
- Pre-import rollback snapshots.
- Scheduled backups with retention rules and email notifications.
- Cloud storage destinations: S3-compatible, FTP, SFTP, Google Drive, Dropbox.
- Direct site-to-site push and pull over a signed REST API.
- WP-CLI commands: `export`, `import`, `status`, `list`.
