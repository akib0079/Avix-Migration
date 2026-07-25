# Avix Migration

WordPress backup and migration plugin. Create a complete backup of a site — or
export selected posts and pages — and restore it onto another WordPress
install.

Built and maintained by [Avix Digital](https://avixdigitalagency.com).

---

## What it does

| | |
|---|---|
| **Full-site backup** | Database plus `wp-content` (themes, plugins, uploads, mu-plugins) written to a single `.avix` archive. |
| **Selective content export** | Pick individual posts, pages or custom post types. Referenced media, page-builder templates and taxonomy terms are resolved and included automatically. |
| **Restore** | Import an archive onto any install running the plugin, across differing domains and table prefixes. |
| **Rollback** | Every database restore takes a pre-import snapshot first, so a bad migration can be undone. |
| **Scheduled backups** | Recurring backups with retention rules and email notifications. |
| **Cloud destinations** | S3-compatible storage, FTP, SFTP, Google Drive and Dropbox. |
| **Site-to-site transfer** | Push or pull directly between two installs over a signed REST API, with no manual download/upload step. |
| **WP-CLI** | `wp avix export`, `import`, `status`, `list`. |

Designed to work within constrained shared hosting: uploads are chunked past
`upload_max_filesize`, and every long operation is resumable across
`max_execution_time` limits.

## Requirements

- WordPress 5.6+
- PHP 7.4+ (tested through 8.5)
- Extensions: `zip`, `zlib`, `mysqli` or `pdo_mysql`, `openssl`, `curl`, `mbstring`

## Installation

**From a release archive**

1. **Plugins → Add New → Upload Plugin**, select the `.zip`, install and activate.
2. Repeat on the destination site if you intend to migrate between installs.

**From this repository**

```bash
git clone https://github.com/<owner>/Avix-Migration.git wp-content/plugins/avix-migration
```

No build step is required — the repository is directly installable.

## Quick start

**Back up**

**Avix Migration → Backup** → choose what to include → **Start backup**.
Download the finished archive from **Backups**.

**Restore**

**Avix Migration → Import** on the destination → upload the archive → review
the pre-flight comparison → type `RESTORE` to confirm.

**Transfer directly between two sites**

1. On the destination: **Remote Sites → Generate key**.
2. On the source: **Remote Sites → Add remote**, paste the key.
3. **Push** to send this site across, or **Pull** to bring the remote in.

## Documentation

See [DOCUMENTATION.md](DOCUMENTATION.md) for architecture, the archive
format, configuration reference, security model, troubleshooting and
development notes.

## Support status

This is an internal tool maintained by Avix Digital for use across client
sites. Feature coverage and testing status for each subsystem are documented
in [DOCUMENTATION.md](DOCUMENTATION.md#verification-status) — read that before
relying on any part of it in production.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
