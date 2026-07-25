=== Avix Migration ===
Contributors: avixdigitalagency
Tags: backup, migration, import, export, cloud storage
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.10
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, migrate, and transfer WordPress sites — full-site or selected content — between installs, with cloud storage and direct site-to-site transfer.

== Description ==

Avix Migration is Avix Digital Agency's in-house replacement for third-party
migration plugins. Install it on a source site and a destination site, and
move a WordPress install between them without hand-editing SQL, wrestling
with mismatched table prefixes, or losing access to wp-admin partway
through a restore.

= Core features =

* **Full-site backup** — database + wp-content (themes, plugins, uploads,
  mu-plugins), with auto-detected exclusions for other plugins' own backup
  folders so archives don't balloon with data that shouldn't be there twice.
* **Selective content export** — pick specific posts/pages/custom post
  types; media, Elementor templates, and taxonomy terms referenced by that
  content come along automatically.
* **Resumable everything** — every backup and restore is chunked and
  resumable, so closing the browser tab mid-operation doesn't restart it
  from zero.
* **Safe restores** — a pre-import snapshot lets a failed or unwanted
  restore be rolled back with one click; "keep me logged in as the current
  admin" means you're never dropped at a login screen after restoring
  someone else's database.
* **Scheduled backups** — recurring backups with retention policies (keep
  last N and/or delete after X days) and email notifications.
* **Cloud storage** — S3-compatible (AWS S3, Cloudflare R2, Wasabi,
  DigitalOcean Spaces), FTP, SFTP, Google Drive, and Dropbox destinations.
* **Direct site-to-site transfer** — push this site to another Avix
  Migration install, or pull one into this one, with no manual
  download/upload step. Authenticated with a signed connection key; the
  archive streams directly between the two sites.
* **WP-CLI support** — `wp avix export`, `wp avix import`, `wp avix status`,
  `wp avix list` for scripted or CI-driven backups.

= Requirements =

* PHP 7.4 or later (tested through 8.5).
* The `zip`, `zlib`, `mysqli` or `pdo_mysql`, `openssl`, `curl`, and
  `mbstring` PHP extensions (all present on essentially every host).
* SFTP support requires either the `ssh2` PHP extension or the bundled
  `phpseclib` library (included — no extra setup needed).

== Installation ==

1. Upload the `avix-migration` folder to `/wp-content/plugins/`, or install
   the zip through **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Install and activate the same plugin on the destination site if you plan
   to migrate between two installs.
4. Go to **Avix Migration** in the admin menu to create your first backup.

= Migrating between two sites (download/upload) =

1. On the source site, go to **Avix Migration → Backup**, choose what to
   include, and run the backup.
2. Download the finished `.avix` archive from **Avix Migration → Backups**.
3. On the destination site, go to **Avix Migration → Import**, upload the
   file, review the pre-flight comparison, and confirm.

= Migrating between two sites (direct transfer, no download/upload) =

1. On the destination site, go to **Avix Migration → Remote Sites** and
   generate a connection key.
2. On the source site, go to **Avix Migration → Remote Sites**, add a
   remote, and paste the key.
3. Click **Push** to send this site to the remote, or use **Pull** from the
   other site to bring a remote site into this one.

== Frequently Asked Questions ==

= Does this touch wp-config.php? =

No. A restored archive never overwrites `wp-config.php` — the destination
site keeps its own database credentials and secret keys.

= What happens to my current admin login after a restore? =

If "keep me logged in as the current admin" is checked (the default), your
current username and password keep working after the restore, even though
the imported database has its own set of users.

= Can I undo a restore? =

Yes — a full-site import takes a rollback snapshot of the destination's
existing tables before touching anything. If something goes wrong, use the
**Rollback** button on the failure or success screen to restore the
previous state instantly.

= Does scheduling work reliably on a low-traffic site? =

Scheduled backups rely on WordPress's own cron system, which only fires on
a page load (same as all default WP-Cron behavior). For time-sensitive
schedules, ask your host to point a real system cron at `wp-cron.php`
every few minutes — the same recommendation applies to any WordPress
scheduling plugin.

= Why can't I connect Google Drive or Dropbox without extra setup? =

Both require your own OAuth application (client ID and secret), entered on
the destination's connection form before clicking Connect. This is
deliberate: using your own app credentials means this plugin's Drive/
Dropbox access is scoped only to your own agency's use, with no dependency
on a third-party developer account.

== Changelog ==

Full history, including fix detail, is in CHANGELOG.md.

= 1.0.10 =
* Fixed anchor-styled buttons rendering with an invisible label.
* Fixed destructive buttons losing their label on hover.

= 1.0.9 =
* Fixed Save schedule and Start backup doing nothing, caused by a nested form
  element in the shared destinations panel.

= 1.0.8 =
* Fixed unreadable disabled buttons.

= 1.0.7 =
* Fixed modals rendering without a background or button styling.

= 1.0.6 =
* Added Tools > Database check, reporting the database's real error behind
  WordPress's generic media-upload failure message.

= 1.0.5 =
* Added restore and guided cleanup for rollback snapshots from Tools.

= 1.0.3 =
* Fixed concurrent job execution causing "table already exists" during restore.

= 1.0.2 =
* Fixed imports failing on sites using Action Scheduler.
* Fixed silent corruption of double-serialized values during search-replace.
* Added detailed failure diagnostics to the job log.

= 1.0.0 =
* Full-site backup and restore.
* Selective content export/import with dependency resolution.
* Resumable, chunked export/import pipeline.
* Pre-import rollback snapshots.
* Scheduled backups with retention and email notifications.
* Cloud storage: S3-compatible, FTP, SFTP, Google Drive, Dropbox.
* Direct site-to-site push/pull over a signed REST API.
* WP-CLI commands: export, import, status, list.
