# Shop Anonymizer

Produces an anonymized copy of a WordPress/WooCommerce shop database, intended solely for the
supplier's development and test environments.

## What it guarantees

The script **performs no write operation on the production database**. All transformations
happen in a separate temporary schema, which is dropped when the run ends.

Each guarantee is verifiable by reading the code:

| Guarantee | Where |
|---|---|
| The source database name appears only in the `mysqldump` invocation | section 5 |
| The working schema must start with `anon_tmp_`, otherwise the script aborts | sections 3 and 7 |
| SQL guard: if the active schema is not the temporary one, the script fails before any `UPDATE` | section 7 |
| The temporary schema is dropped even on error or interruption | `cleanup()` |
| The raw dump is created with `0600` permissions and removed right after the import | sections 5 and 6 |
| No password ever appears on the command line (`--defaults-extra-file` is used) | section 1 |

During preflight the script creates and immediately drops an empty probe schema, to check the
user's privileges. This is the only write operation performed on the server, and it does not
touch the shop database in any way.

## Requirements

- PHP CLI >= 7.4 with the zlib extension
- WP-CLI in `PATH`
- `mysql` and `mysqldump` clients (or `mariadb` / `mariadb-dump`)
- Free disk space of roughly 2.5× the data size
- A MySQL user with the `CREATE DATABASE` privilege. If that is not available (typical on RDS
  with an application user), it is enough for your DBA to prepare an **empty** schema whose
  name starts with `anon_tmp_`: the wizard will ask for it.

## Usage

```bash
# Dry run: prints the full plan, creates and changes nothing
php shop-anonymizer.php --path=/var/www/shop --dry-run

# Real run
php shop-anonymizer.php --path=/var/www/shop --output-dir=/srv/anon-export
```

Options: `--path`, `--output-dir`, `--seed-file`, `--unknown`, `--config`, `--dry-run`,
`--keep-temp`.

Credentials are read from `wp-config.php` through WP-CLI: nothing has to be typed or stored
anywhere.

## What the wizard asks

1. **Output directory**, with a disk space check.
2. **Unrecognized tables.** Tables outside the rule catalogue (custom plugins, CRMs, ERPs) are
   grouped by plugin family and shown with counts, rows and size. You answer once:

    - structure only for all of them (default, no further questions);
    - exclude all of them;
    - decide per plugin group;
    - decide table by table.

   In the last two modes, a `!` suffix extends the answer to every remaining item: `1!` means
   "structure only, for this one and all the following ones". Tables copied in full are listed
   again at the end, since that is the choice most likely to leak personal data by accident.

   To skip the question entirely:

   ```bash
   php shop-anonymizer.php --path=... --unknown=schema
   ```

3. **Order time window**: all orders, or only the last N months together with their related
   tables (addresses, meta, line items, analytics lookups).
4. **Service administrator account** with a known password. The login is randomized on every
   run (`admin_test_XXXX`) so that no predictable account ends up on a shared test
   environment; it can be overridden at the prompt. The hash is computed by WP-CLI using the
   algorithm of the current installation, so it stays valid on WordPress versions predating
   the switch to bcrypt.
5. **Media library**: keep or drop the `attachment` records.
6. **Final confirmation**: full summary, then type `ANONYMIZE`.

Choices are saved to `run-config.json` next to the artifact, both to repeat the export and as
documentary evidence. The file is written in `--dry-run` too, so the most convenient workflow
is:

```bash
# 1. exploratory pass: answer once, nothing is touched
php shop-anonymizer.php --path=/var/www/shop --dry-run

# 2. real run: table choices are reused, no questions repeated
php shop-anonymizer.php --path=/var/www/shop --config=./anon-export/run-config.json
```

With `--config`, tables already decided are not asked about again, and the remaining questions
come pre-filled with the previous run's values. Tables that appeared since the last run (new
plugins) are asked about, so they never slip through unnoticed.

## What gets transformed

- **Users**: login, email, nicename, display name, URL, activation keys; passwords are replaced
  with non-matching hashes.
- **Personal details**: first and last names, addresses, cities, postcodes, phone numbers,
  company names, VAT numbers and tax codes, in `usermeta` as well as in legacy and HPOS orders.
- **Orders**: billing email, IP addresses, user agents, customer notes, `order_key`, payment
  gateway transaction identifiers.
- **Comments and order notes**: author, email, IP, user agent; order note bodies are replaced.
- **Secrets**: options matching API keys, tokens, SMTP and gateway credentials are emptied;
  transients are deleted.
- **Structure only**: sessions, payment tokens, API keys, application logs, Action Scheduler
  queues, and the reporting tables of security and SEO plugins.

Replacement values are synthetic but well-formed (emails, phone numbers, postcodes), with
cardinality and referential integrity preserved, so tests stay meaningful.

## Automated verification

Before producing the artifact, the script runs a set of residual-data queries: emails outside
the fake domain, unreplaced password hashes, real IP addresses, tables that should be empty.
**If a single check fails, no file is produced** and the temporary schema is dropped.

## Output

| File | Contents |
|---|---|
| `shop-anon-<date>.sql.gz` | the artifact to hand over |
| `shop-anon-<date>.sha256` | archive checksum, to be shared separately |
| `shop-anon-<date>.manifest.json` | rules applied, per-table row counts, checks passed |
| `run-config.json` | wizard choices, to repeat the export |
| `.anon-seed` | **secret**, stays on the machine: never hand it over with the dump |

Recommended transfer:

```bash
gpg -c --cipher-algo AES256 shop-anon-<date>.sql.gz
```

This produces `shop-anon-<date>.sql.gz.gpg`. Send the passphrase and the checksum over a
channel other than the one used for the file.

## Receiving the artifact

The four steps below are what the recipient runs. GnuPG is available on Linux
(`apt install gnupg`), macOS (`brew install gnupg`) and Windows (Gpg4win).

**1. Decrypt.** GnuPG prompts for the passphrase interactively:

```bash
gpg --output shop-anon-<date>.sql.gz --decrypt shop-anon-<date>.sql.gz.gpg
```

Never pass the passphrase as a command-line argument: it would be visible in the process list
and in the shell history. For unattended pipelines, read it from a file with restrictive
permissions instead:

```bash
gpg --batch --pinentry-mode loopback --passphrase-file ~/.secrets/anon.pass \
    --output shop-anon-<date>.sql.gz --decrypt shop-anon-<date>.sql.gz.gpg
```

**2. Verify integrity** against the checksum received separately:

```bash
sha256sum -c shop-anon-<date>.sha256      # Linux
shasum -a 256 -c shop-anon-<date>.sha256  # macOS
```

The expected output ends with `OK`. If it does not, stop: the file is corrupted or has been
altered in transit, and it should be requested again rather than imported.

**3. Import** into an empty database — never into an existing one:

```bash
mysql -u root -p -e "CREATE DATABASE shop_test CHARACTER SET utf8mb4"
gunzip -c shop-anon-<date>.sql.gz | mysql -u root -p shop_test
```

**4. Rewrite the URLs**, which the export deliberately leaves untouched:

```bash
wp search-replace 'https://www.shop-production.tld' 'https://shop.test.local' \
    --path=/var/www/shop-test --all-tables --precise
```

The service account credentials are printed in the export summary and recorded in
`shop-anon-<date>.manifest.json`.

If the archive arrives as an encrypted ZIP or 7z instead of a `.gpg` file, `unzip` and `7z x`
prompt for the passphrase in the same way; the remaining three steps are unchanged.

## Anonymization vs. pseudonymization

The persistent seed makes the transformation **deterministic**: the same email address always
maps to the same fake value. This is deliberate, as it keeps test fixtures stable across
exports, but it means that anyone holding the seed can check whether a known individual is
present in the dataset. Under GDPR art. 4(5) the result is therefore **pseudonymized**, not
anonymized: the dataset remains within the scope of the GDPR and is processed under art. 28.

To obtain a genuinely anonymous dataset, delete the `.anon-seed` file before each run: values
will differ on every export and the link to the originals becomes unrecoverable.

## Known limitations

- **Product review bodies are preserved** (only the author is anonymized). If review text may
  contain personal data, the `comments` table should be assessed case by case.
- The script **does not rewrite URLs** (`siteurl`, `home`, references inside serialized data).
  That replacement is done after the import with `wp search-replace`, which handles
  serialization correctly.
- Uncatalogued plugin tables are exported without data unless explicitly chosen otherwise. If a
  test needs their contents, a dedicated rule should be added to the catalogue. Send such
  requests, and any failed verification report, to <a.gatta@xeader.com>.

## Substitution values

Synthetic names, streets and cities are drawn from Italian dictionaries, so an Italian shop's
dataset stays plausible for testing (address formats, sorting, string lengths). They can be
swapped for another locale in the `$firstNames` / `$lastNames` / `$cities` / `$streets` arrays
in section 7.
