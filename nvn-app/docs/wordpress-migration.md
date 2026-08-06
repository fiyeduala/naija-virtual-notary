# Migrating the old WordPress site into this application

**Short answer: yes — accounts and logins can be carried over, and the machinery
for the login half is already built and tested.** Nobody has to reset a password.
The rest depends on what the old database actually holds, which is why step 2
below is "find out" and not "start importing".

Nothing here touches the WordPress installation. **Do not delete it, and do not
empty its database, until the last verification step has passed.** Once it is
gone it is gone, and half of what has to be migrated only exists there.

---

## 1. What is already built

| Piece | Where | State |
| --- | --- | --- |
| `users.legacy_password`, `legacy_source`, `legacy_id` | `2026_08_06_000002_add_legacy_identity_to_users` | migrated |
| WordPress hash verification (phpass, 6.8+ bcrypt, ancient MD5) | `app/Support/WordPressHasher.php` | tested against WordPress's own `class-phpass.php` |
| Sign-in fallback + automatic upgrade to a Laravel hash | `app/Auth/LegacyUserProvider.php`, wired in `config/auth.php` | tested, 52 assertions |

### How a preserved login works

1. The import writes the WordPress hash to `users.legacy_password` and a random
   throwaway value to `users.password`.
2. The person signs in with the password they have always used. The normal
   Laravel check fails (there is no Laravel hash yet); the WordPress check
   passes.
3. In the same write, the password is re-hashed the Laravel way and
   `legacy_password` is set to `NULL`. It is used at most once per account.
4. `user.legacy_password_upgraded` goes into the audit log.

### The one rule that must not be broken

`User::casts()` contains `'password' => 'hashed'`. **Assigning a WordPress hash
to `users.password` hashes the hash**, and that account can never be signed into
again. The WordPress hash goes in `legacy_password`. Nothing else.

---

## 2. What to do right now

### a. Back up. Before anything else.

```bash
# Database — the whole thing, structure and data
mysqldump -u USER -p --single-transaction --routines --triggers DBNAME > nvn-wordpress-backup.sql
gzip nvn-wordpress-backup.sql

# Uploads, themes, plugins — the ID documents and notarized files live here
tar -czf nvn-wp-content.tar.gz wp-content/
```

Download both off the server. cPanel's own "Full Backup" is a fine second copy,
but take these two by hand as well — a restore you have never held in your own
hands is not a backup.

### b. Find out what is actually in there

Run these and keep the output. They read; they change nothing.

```sql
-- Table prefix and sizes (usually wp_, but installers randomise it)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_rows DESC;

-- How many accounts, and what roles they hold. This is the important one:
-- it tells us who becomes a client, who becomes a notary, who is an admin.
SELECT meta_value, COUNT(*) AS people
FROM wp_usermeta
WHERE meta_key = 'wp_capabilities'
GROUP BY meta_value;

-- Which hashing scheme the passwords use. Expect a mix.
SELECT LEFT(user_pass, 3) AS scheme, COUNT(*)
FROM wp_users GROUP BY scheme;

-- Duplicate or empty emails would collide on import; find them now
SELECT user_email, COUNT(*) c FROM wp_users GROUP BY user_email HAVING c > 1;
SELECT COUNT(*) FROM wp_users WHERE user_email = '' OR user_email IS NULL;

-- What the plugins left behind
SELECT post_type, COUNT(*) FROM wp_posts GROUP BY post_type ORDER BY 2 DESC;
SELECT DISTINCT meta_key FROM wp_usermeta ORDER BY meta_key;

-- Which CFDB is installed — they store uploaded files in different places
SHOW TABLES LIKE '%cf7dbplugin%';
SHOW TABLES LIKE '%db7_forms%';
```

Also note the **WordPress version** (Dashboard → Updates, or `SELECT option_value
FROM wp_options WHERE option_name = 'db_version'`) and the **active plugin list**
(`SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'`).

### c. Send those results over

The counts, the role list, the scheme breakdown, the table list, the plugin list.
**Not the dump itself, and not the `user_pass` column** — the hashes only need to
travel from the old database to the new one on the server, never through chat or
email.

With that in hand the importer (`php artisan nvn:import-wordpress`, reading the
old database over a second connection, idempotent, with `--dry-run`) is a
mechanical job. Writing it before knowing the table prefix, the role names and
which plugins actually stored anything would be guesswork.

---

## 3. What each plugin's data can become — and the honest caveats

### WP User Manager + wp_users → `users`
Straightforward. `user_email` → `email`, `display_name` (or the `first_name` /
`last_name` meta) → `full_name`, `user_registered` → `created_at`, `user_pass` →
`legacy_password`. Roles map from `wp_capabilities` onto this application's
`role` enum (`client` / `notary` / `admin`). **This is the part that fully
works, including the logins.**

### WCFM Multivendor → `notary_profiles`
Vendor accounts are WordPress users with a vendor role plus `wp_usermeta` rows
(store name, address, phone, commission). Those become notary profiles.

Two things do not come from WCFM itself:
- **Sealing assets.** Signatures, stamps and seals here are files on the private
  disk that `NotaryProfile::canSeal()` checks for. WCFM has no equivalent — but
  **CFDB does** (see below), so these are recoverable from the form
  submissions rather than having to be re-collected. Where a match cannot be
  made, the notary simply starts unable to seal and uploads theirs, which is the
  correct behaviour: a seal is not something to guess at.
- **Verification status.** Imported notaries should land as *pending review*
  rather than approved, unless you confirm each one. Approval here means the
  platform vouches for them, and CFDB records the application, not your decision
  on it.

### WooCommerce orders → history
Past sales can be imported as a **read-only historical record** marked with
`legacy_source`. They must **not** be written into `payments` as live rows: the
payout ledger is `payments.payout_id`, and injecting historical orders with no
payout would make every one of those notaries appear to be owed money they were
already paid. If you want their lifetime totals visible, the right shape is a
separate archive table or a reporting figure, not the live ledger.

### WP Customer Area → `notarization_requests`
Possible in principle. Whether it is worth it depends on how many open requests
exist. **Completed** ones are history and belong with the archive above; only
genuinely **open** requests need to become live rows, and if there are only a
handful, re-entering them by hand is faster and safer than an importer.

### Contact Form 7 + CFDB → notary applications, partner applications, sealing assets
Contact Form 7 on its own stores nothing — it emails submissions and forgets
them. **CFDB was installed, so this data survives.** Confirmed 2026-08-06: it
holds notary requests, partner applications, and confirmed partners including
their stamps and seals.

**First question: which CFDB.** The two plugins share a nickname and store
uploaded files in completely different places, which changes what your backups
have to cover.

| | Contact Form DB (mdsimpson) | CFDB7 (arshidkv12) |
| --- | --- | --- |
| Table | `wp_cf7dbplugin_submits` | `wp_db7_forms` |
| Shape | one row per **field**: `submit_time`, `form_name`, `field_name`, `field_value`, `field_order` | one row per **submission**: `form_id`, `form_value` (PHP-serialized array), `form_date` |
| Uploaded files | **`file` LONGBLOB — inside the database** | on disk, `wp-content/uploads/cfdb7_uploads/`, referenced by filename |

```sql
SHOW TABLES LIKE '%cf7dbplugin%';   -- Contact Form DB
SHOW TABLES LIKE '%db7_forms%';     -- CFDB7

-- Contact Form DB: which forms, how many submissions, how many files
SELECT form_name, COUNT(DISTINCT submit_time) AS submissions
FROM wp_cf7dbplugin_submits GROUP BY form_name;
SELECT COUNT(*) FROM wp_cf7dbplugin_submits WHERE file IS NOT NULL;

-- CFDB7: which forms and how many submissions
SELECT form_post_id, COUNT(*) FROM wp_db7_forms GROUP BY form_post_id;
```

**If it is CFDB7, the `wp-content` tarball is load-bearing** — the rows point at
files that a host prune or a plugin cleanup setting can have removed while the
row survives. Check that the files are actually on disk before trusting them. If
it is Contact Form DB, the stamps and seals travel inside the `mysqldump` and
nothing else is needed.

**What CFDB does not contain, and cannot:**

- **No link to a user account.** Submissions are flat form data — there is no
  `user_id`. Matching a submission to a `wp_users` row means joining on an email
  address that someone typed into a form, which is not always the address they
  registered with. Expect a fraction that will not match automatically and needs
  a person to look at it. This is the real remaining work in the migration.
- **No state.** "Confirmed partner" is a decision, not a form field. CFDB proves
  someone applied and what they uploaded; whether you approved them lives in the
  WCFM vendor status or the `wp_capabilities` role, and if it lives only in an
  inbox or in memory, it is a judgment call per person on import.

### wp-content/uploads → the private disk
Uploaded ID documents and finished PDFs need copying to `storage/app/private/`
and re-pointing. They are **not** public files here, and they must not be
dropped into `public/`.

---

## 4. Cutting over

1. Import into a **staging copy** of this application first, never straight into
   the live database.
2. Verify: row counts match, and — the real test — take three real accounts
   (a client, a notary, an admin), ask each to sign in with their existing
   password, and watch `legacy_password` become `NULL`.
3. Put WordPress into maintenance mode. Do not delete it.
4. Point the domain at this application.
5. Announce it from **Content & settings → Email** in the admin panel: one send
   to everyone, saying the site has moved and their existing password still
   works. That system is built and tested.
6. **Keep the WordPress database for at least 90 days**, untouched, before
   dropping it. Something always turns up.

---

*Built and verified 6 August 2026. The login machinery is tested against
WordPress's own hasher; the importer is not written yet and is waiting on the
answers to step 2b.*
