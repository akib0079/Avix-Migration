# Avix Migration — Technical Documentation

Version 1.0.10 · Avix Digital

---

## Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [The `.avix` archive format](#the-avix-archive-format)
4. [The job engine](#the-job-engine)
5. [Backup](#backup)
6. [Restore](#restore)
7. [Selective content export](#selective-content-export)
8. [Scheduled backups](#scheduled-backups)
9. [Cloud storage destinations](#cloud-storage-destinations)
10. [Site-to-site transfer](#site-to-site-transfer)
11. [Security model](#security-model)
12. [Hosting limits](#hosting-limits)
13. [WP-CLI](#wp-cli)
14. [Troubleshooting](#troubleshooting)
15. [Development](#development)
16. [Verification status](#verification-status)

---

## Overview

Avix Migration moves a WordPress site — or part of one — from one install to
another. It replaces the download/upload/hand-edit-SQL routine with a single
archive format and a resumable pipeline that survives shared-hosting limits.

Two export modes:

- **Full site** — the database plus `wp-content`. WordPress core is not
  included; the destination already has it, and shipping core invites
  version and `wp-config.php` conflicts for no benefit.
- **Selected content** — specific posts/pages/CPTs, with their media,
  page-builder templates and taxonomy terms resolved automatically.

---

## Architecture

```
avix-migration/
├── avix-migration.php          Bootstrap, constants, activation hooks
├── uninstall.php
├── includes/
│   ├── class-plugin.php        Composition root
│   ├── class-autoloader.php    Avix_Migration_Foo_Bar → includes/foo/class-foo-bar.php
│   ├── archive/                .avix reader, writer, manifest, store
│   ├── job/                    Job model, runner, budget, step base class
│   │   └── steps/              export/ import/ content/ pipelines
│   ├── db/                     Dump, replay, serialization-safe search-replace
│   ├── fs/                     Filesystem scanner, exclusion detection
│   ├── storage/                Provider interface + S3/FTP/SFTP/Drive/Dropbox
│   ├── remote/                 Connection keys, HMAC auth, REST controller
│   ├── schedule/               Scheduler, retention, notifications
│   ├── rollback/               Pre-import snapshots
│   ├── admin/                  Controllers + views (no build step)
│   ├── cli/                    WP-CLI commands
│   └── util/                   Filesystem, crypto, logger, sysinfo, chunked upload
├── assets/                     css/ js/ img/ — plain files, served as-is
└── vendor/                     phpseclib (SFTP)
```

**Conventions**

- Class `Avix_Migration_Storage_Provider_S3` lives at
  `includes/storage/class-storage-provider-s3.php`. Interfaces use an
  `interface-` prefix, traits `trait-`.
- All state lives in files (`wp-content/avix-backups/`) and options. No custom
  database tables, so there is nothing to migrate between plugin versions.
- Admin CSS/JS are the literal files the browser loads. No build step, no
  `node_modules`, editable directly on a server.

---

## The `.avix` archive format

A sequentially written stream, chosen over ZIP because it can be read and
written through a byte cursor — which is what makes export *and* import
resumable, with no practical size ceiling and no `ZipArchive` memory
behaviour to work around.

Each entry is a fixed **4381-byte header** followed by raw content:

| Offset | Length | Field |
|---|---|---|
| 0 | 255 | Filename, null-padded |
| 255 | 14 | Content length (decimal string) |
| 269 | 12 | Modification time (Unix) |
| 281 | 4096 | Relative directory path |
| 4377 | 4 | Flags |

Entry order is fixed and meaningful:

1. **`avix-manifest.json`** — always first, so any archive can be validated by
   reading one header plus a small JSON blob, without scanning the file.
   Records format version, archive type, source site URL, `ABSPATH`, uploads
   path, table prefix, WP/PHP/MySQL versions, plugin and theme inventory,
   totals, and the originating schedule id where applicable.
2. **`database.sql.gz`** — the gzipped dump (full-site archives).
   **`content.json.gz`** — the content index (selective archives).
3. **File entries** — stored uncompressed so byte offsets stay predictable and
   resume is trivial. Most payload is already-compressed media, so the
   compression saving would be marginal.
4. **EOF** — 4381 zero bytes, plus a `.checksum.json` sidecar written alongside
   the archive (SHA-256 and byte count).

An archive without the EOF marker is an interrupted export, and is
distinguishable from a complete one.

---

## The job engine

Every long operation is a **job**: a state document at
`wp-content/avix-backups/jobs/{job_id}.json`. Deliberately a file, not an
option — an import replaces the database underneath the running process, so
DB-backed state is unreliable at exactly the moment it matters.

A job holds its step list, current step index, a per-step cursor (table plus
row keyset for database work; file index plus byte offset for file work),
running totals, and timings.

`Job_Runner::run()` executes steps until a **time budget** (60% of
`max_execution_time`) or **memory budget** (70% of `memory_limit`) is reached,
then persists and returns. Three drivers share the one engine:

| Driver | Used by |
|---|---|
| Browser | Admin JS polls `wp_ajax_avix_run_step` in a loop |
| Cron | Scheduled backups, chained via short-lived single events |
| WP-CLI | Loops to completion with no time limit |

**Locking.** A job takes an exclusive `flock()` before running. Browser polling
can overlap when a tick outruns the poll interval, and two ticks executing the
same step concurrently corrupts cursor state. A tick that cannot take the lock
returns current progress and lets the poller retry. The lock is a file, for the
same reason job state is.

Each step implements `execute( Job $job ): Step_Result`. Nothing else in the
codebase needs to know about chunking.

---

## Backup

**Pipeline:** `Prepare → Scan_Files → Database → Write_Archive → Finalize → Upload`

- **Table discovery** by prefix, with an "all tables in this database" option
  for shared databases.
- **Row pagination** uses keyset pagination on the primary key. `LIMIT/OFFSET`
  is only a fallback for tables without a single-column key — on a large table
  it degrades badly.
- **`INSERT` batching** capped near 800 KB to stay under `max_allowed_packet`.
- **Binary columns** emitted as hex literals; `NULL` distinguished from empty
  string.
- **Optional row skips:** transients, post revisions, spam and trashed
  comments.

**Exclusions.** Other backup plugins store their archives inside `wp-content`,
which would otherwise be copied into yours. The wizard auto-detects these
(`ai1wm-backups`, `updraft`, cache directories and similar) and pre-selects
them, with sizes shown. On a typical site this is the difference between a
3 GB and an 800 MB archive.

---

## Restore

**Pipeline:** `Validate → Rollback_Snapshot → Extract_Database → Extract_Files → Database_Replay → Search_Replace → Keep_Admin → Finalize`

**Validation** checks the manifest schema, format version, SHA-256 and byte
count before a single table is touched.

**Rollback snapshot** renames every existing table aside to
`avix_rb_{timestamp}_{table}`. `RENAME TABLE` is near-instant and copies no
rows. MySQL auto-commits DDL and cannot roll it back transactionally, so
renaming rather than dropping is what makes undo possible at all. Snapshots are
retained after a successful import and managed from **Tools → Rollback
snapshots**.

**Replay** streams the dump and executes it statement by statement against a
byte cursor, so it resumes mid-file. Table-name tokens are rewritten from the
source prefix to the destination's.

**Search-replace** is the highest-risk component and is handled carefully:

- Serialized values are unserialized, recursed, and **re-serialized**, so
  string byte-length prefixes are recomputed. A naive `str_replace` on
  serialized data corrupts every length prefix after the first substitution.
- Values containing an object token (`O:`, `C:`, `E:`) are **never
  unserialized**. With `allowed_classes => false` — which is required, since
  instantiating arbitrary classes from imported data is an object-injection
  hole — PHP returns `__PHP_Incomplete_Class`, and writing to one is a fatal
  error. Those payloads are instead rewritten at the byte level, recomputing
  each `s:LEN:` prefix while leaving class names untouched. Parsing is
  length-driven, not delimiter-driven: after reading the declared length the
  next two bytes are verified to be `";`. This matters because `https:` itself
  contains the `s:` sequence being scanned for.
- JSON is detected, decoded, recursed and re-encoded with its original
  escaping preserved.
- Replacement sets cover four variants of every URL — plain, slash-escaped
  (`https:\/\/`, as page builders store it), URL-encoded, and
  protocol-relative — plus `ABSPATH` and uploads-path pairs and `http`/`https`
  variants.
- Each row is processed inside its own error boundary. By this point the
  database has already been replayed, so aborting would strand the site
  half-migrated; an unrewritable row is reported as a warning instead.

**Known limitation.** Base64-encoded page-builder payloads (some Divi and
WPBakery modules store entire encoded structures rather than referencing IDs)
are not rewritten. This is detected and surfaced as a post-import warning
rather than passing silently.

**`wp-config.php` is never restored.** The destination keeps its own database
credentials and salts.

**Keep me logged in.** Optionally re-injects the current administrator into the
imported user tables, so the operator is not dropped at a login screen holding
credentials for a different site's database.

---

## Selective content export

Same container, `archive_type: "content"`, with a `content.json` index richer
than WXR.

**Dependency resolution** is the part WXR gets wrong. For each selected post
the exporter collects referenced attachments from:

- `post_content` — `<img>` tags, `wp-image-{id}` classes, gallery shortcodes
- `_thumbnail_id`
- Page-builder JSON (`_elementor_data`) image widgets
- Post meta holding attachment IDs (ACF-style fields)

Referenced page-builder templates are followed too, up to a bounded depth —
without them a page imports and renders broken.

**On import,** attachments are restored first to build an `old_id → new_id`
map, then post content, meta and builder JSON are rewritten against it. Media
is staged to a job-private directory first and placed into uploads with
WordPress's own collision-safe naming, so an import can never overwrite an
unrelated existing file.

**Conflict modes:** skip, overwrite, or import as a duplicate — matched via an
`_avix_source_id` marker. Authors are matched by login, falling back to the
importing user. Terms are created parents-first.

---

## Scheduled backups

Rather than registering one cron event per schedule, a single hourly
housekeeping tick evaluates every schedule's due-ness. This removes a whole
class of bookkeeping bugs where an add/edit/delete leaves an orphaned event.

Frequencies: hourly, 6-hourly, daily, weekly, monthly. Daily and above fire
within the hour containing their configured time-of-day.

**Retention** treats "keep last N" as a protective floor: the N most recent
archives are never deleted regardless of age, and the day-based cutoff only
prunes what lies beyond that floor. This matches the semantics used by restic,
borg and UpdraftPlus, and prevents a day rule from deleting an operator's only
recent backup. Retention is scoped by the schedule id recorded in each
manifest, so it never touches manual backups or another schedule's archives.

**Cron caveat.** WP-Cron only fires on a page load. For time-sensitive
schedules, point a real system cron at `wp-cron.php`. This applies to every
WordPress scheduling plugin, not just this one, and the Schedules screen says
so.

---

## Cloud storage destinations

All providers implement one interface — `test_connection`, `upload_chunk`,
`download`, `delete`, `list_files` — with upload expressed as a resumable,
offset-based chunk operation rather than "send this whole file".

| Provider | Notes |
|---|---|
| **S3-compatible** | Hand-rolled SigV4 signing, no SDK dependency. Multipart upload. Custom endpoint and path-style toggle cover AWS S3, Cloudflare R2, Wasabi, DigitalOcean Spaces and MinIO. |
| **FTP** | Native `ftp_*`. Resumes via `ftp_fput()`'s offset parameter (an FTP `REST` before `STOR`), in binary mode. |
| **SFTP** | Vendored phpseclib. SFTP's own `SSH_FXP_WRITE` carries a byte offset, so chunking is native. |
| **Google Drive** | OAuth2 with resumable upload sessions. Chunks are multiples of 256 KiB per Google's requirement. |
| **Dropbox** | OAuth2 with `upload_session/start`, `append_v2`, `finish`. |

Credentials are encrypted at rest with AES-256-GCM, keyed from the site's
`AUTH_KEY`/`SECURE_AUTH_KEY`, so a database dump does not hand over live cloud
credentials.

**Google Drive and Dropbox require your own OAuth application** (client ID and
secret, entered on the connection form). This is deliberate: using your own
credentials scopes access to your own organisation and removes any dependency
on a third-party developer account or review process.

**Known limitation.** S3 and Dropbox *downloads* buffer the response in memory
rather than streaming to disk, so restoring a large archive *from* those two
providers can exceed `memory_limit`. Uploads to all providers are chunked and
unaffected; Google Drive streams downloads correctly.

---

## Site-to-site transfer

Two installs, both running the plugin. One issues a connection key, the other
consumes it.

**Key issuance.** The destination generates a key: base64 of
`{ site_url, key_id, secret, expires }`, displayed once. The issuing site
stores the secret encrypted at rest — not hashed, because verifying an HMAC
requires recomputing it from the shared secret, which a hash cannot do.

**REST surface** under `avix/v1`: `handshake`, `manifest`, `export/start`,
`export/status`, `send-chunk`, `receive-chunk`, `import/start`,
`import/status`.

**Push** runs a normal local export whose destination is the remote, streaming
4 MB chunks to `receive-chunk`, then triggers a normal import there and polls
its status. **Pull** is the mirror: ask the remote to export, poll, download
via `send-chunk`, then run a normal local import. Both directions reuse the
existing export and import pipelines — only the transport is new.

Status polling doubles as the remote job's keep-alive: each poll also advances
that job by one runner tick, so no separate cron chain is required.

---

## Security model

**Admin endpoints.** Every AJAX action routes through one dispatcher that
enforces a capability check and nonce verification once, rather than repeating
it per handler — a handler that forgets the check is the classic way a plugin
ships an unauthenticated action. The two `admin-post.php` handlers (archive
download, OAuth callback) carry their own capability and nonce checks.

**REST endpoints** are authenticated with HMAC-SHA256 over method, path,
body hash, timestamp and nonce. The secret never crosses the wire. Requests
outside a 5-minute clock-skew window are rejected; nonces are single-use, so a
captured request cannot be replayed; repeated failures are rate-limited and
logged.

**Archive storage** is protected by `.htaccess`, `web.config` and an
`index.php`, *plus* a 32-character random token in every archive filename, with
downloads served only through an authenticated PHP endpoint. The token matters
because `.htaccess` does nothing on nginx, which a large share of hosts run.

**Path traversal.** Archives are treated as untrusted input even when the
operator uploaded them. Any entry whose resolved path escapes the extraction
directory, is absolute, or is a symlink is rejected. Filenames arriving over
the REST API are validated against the exact pattern the exporter generates —
a client-supplied filename is never used to build a filesystem path, even from
an authenticated peer.

**Object injection.** Imported serialized data is never unserialized with class
instantiation enabled. See [Restore](#restore).

---

## Hosting limits

| Limit | Handling |
|---|---|
| `upload_max_filesize` / `post_max_size` | Bypassed. The browser slices archives into 2 MB chunks; each POST carries 2 MB regardless of archive size. |
| `max_execution_time` | Handled. Each tick uses 60% of the limit, persists its cursor, and resumes. Works on a 30-second cap. |
| `memory_limit` | Handled on the main paths — 70% ceiling, files streamed in bounded chunks, SQL replayed statement by statement. Exception: S3/Dropbox downloads, noted above. |
| `max_allowed_packet` | `INSERT` batches capped near 800 KB. |
| Disk space | A hard floor no chunking solves. Roughly 2× the archive size is needed for archive plus extraction. |

---

## WP-CLI

```bash
wp avix export [--no-database] [--no-files] [--exclude=<dirs>]
wp avix import <file> [--keep-current-admin=<login>] [--conflict-mode=<mode>] [--yes]
wp avix status <job_id>
wp avix list
```

CLI runs have no execution-time limit, so commands loop the job to completion
synchronously with a progress bar rather than chunking across requests.

---

## Troubleshooting

**"Could not insert attachment into the database", or an empty media library
after a restore.** WordPress hides the underlying database error behind that
generic message. Run **Tools → Database check**, which performs the same insert
and reports MySQL's actual error, plus a table-by-table report including
`AUTO_INCREMENT` versus current maximum ID — a counter at or below the maximum
makes every new post collide on the primary key, which is a normal outcome of a
half-completed replay.

**A restore failed and the site looks empty or broken.** The pre-import
snapshot holds your previous content. Go to **Tools → Rollback snapshots** and
use **Restore**. If several imports failed in sequence, restore the **oldest**
snapshot — each later attempt only snapshotted the partial results of the one
before it. The screen labels which one that is. Do not drop snapshots until the
site is confirmed correct.

**A job appears stuck.** The progress screen distinguishes a slow step from a
hung one via a heartbeat. Jobs with no progress for an hour are failed
automatically by the housekeeping tick; **Tools → Reset stuck jobs** forces it.

**A step failed with an unclear error.** Failures record the exception class,
message, originating `file:line` and the top stack frames to the job log,
visible in the collapsible log panel on the progress screen. **Tools → Log**
shows plugin-scoped entries; step failures are logged against the job.

**Scheduled backups are not running.** WP-Cron requires site traffic. Confirm
with a real system cron hitting `wp-cron.php`.

---

## Development

**No build step.** CSS and JS in `assets/` are the literal files served. Edit
and reload.

**Dependencies.** Composer is used only for phpseclib (SFTP). `vendor/` is
committed so a clone is immediately installable without a Composer run. To
refresh:

```bash
composer install --no-dev --optimize-autoloader
```

**Autoloading.** `Avix_Migration_Foo_Bar` resolves to
`includes/foo/class-foo-bar.php`, with `interface-` and `trait-` prefixes
supported.

**Adding a job step.** Extend `Avix_Migration_Job_Step`, implement `execute()`
and `label()`, and add the class name to the relevant pipeline array. Return
`cont()` to be called again with the cursor intact, `step_complete()` to
advance, or `failed()` to stop. Do not return `job_complete()` from a step
that may later have steps appended after it.

**Adding a storage provider.** Implement
`Avix_Migration_Storage_Provider`, register it in `Storage_Manager`, and add
its fields to the destinations partial. `upload_chunk()` must be resumable
from an arbitrary offset.

**Styling.** Design tokens are declared on `:root` as well as `.avix-wrap`,
because modals and toasts are appended to `document.body` — outside
`.avix-wrap` — and custom properties inherit down the tree. Component rules
carry explicit fallbacks so a control is never rendered unreadable if a token
fails to resolve.

**Views and forms.** The shared destinations partial contains no `<form>`
element by design: it is embedded inside the Backup and Schedules forms, and
HTML forbids nested forms. A nested `<form>` start tag is dropped by the parser
while its `</form>` is honoured, which silently closes the outer form early and
detaches the host page's own submit button.

---

## Verification status

Stated plainly so that nobody relies on an untested path.

**Covered by automated tests** (pure PHP and Node, no WordPress bootstrap
required):

| Area | Coverage |
|---|---|
| Serialization-safe search-replace | Nested serialized structures, serialized objects of unloadable classes, double-serialized payloads, multibyte content, NUL-byte property names, escaped-slash builder JSON, deliberately corrupt input |
| Archive format | Write/read round-trip, resume from arbitrary offset, EOF detection |
| Database export/import | Statement splitting against embedded semicolons, escaped quotes and binary blobs |
| Content export/import | Dependency resolution heuristics, template-depth bounding, ID remapping, parent-before-child term ordering |
| Scheduling | Due/not-due decisions per frequency and time window; retention floor semantics |
| S3 SigV4 | Byte-exact match against AWS's published `aws-c-auth` test vectors |
| Remote authentication | Signature verification, tampering across every signed element, replay, clock skew, rate limiting |
| Job concurrency | Two OS processes racing one job; the step executes exactly once |
| Pipeline wiring | Every referenced step class resolves; ordering invariants (snapshot before replay, upload after finalize) |
| Admin UI | Element-ID/markup cross-checks; provider field-visibility mapping |

**Not yet verified end-to-end:** a complete backup-and-restore cycle between
two live installs, and site-to-site push/pull between two live installs. The
authentication layer for the latter is well covered by tests, but the transport
has not been exercised against a real remote.

Treat those two paths as unproven, and exercise them on disposable installs
before using them on a site that matters.
