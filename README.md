# WP Anonymizer

WP Anonymizer is a focused PHP CLI utility that exports a pseudonymized copy
of a WordPress/WooCommerce database for development and test environments.
It never runs transformation statements against the source database: data is
read from the source, imported into a separate working schema, transformed,
verified, and only then published as a compressed SQL artifact.

The output must still be treated as personal data. Passing the verification
gate means that the implemented checks found no forbidden residuals; it is not
a legal certification of anonymization.

## Safety contract

- Source access consists of read-only inventory queries and `mysqldump`.
- Every mutable working schema must start with `anon_tmp_`; SQL execution is
  also bound to the exact registered schema name.
- A guard inside the generated SQL aborts before transformations if the active
  database is not the expected working schema.
- The script creates a private working schema when permitted. Otherwise it
  accepts only an existing DBA-prepared schema with no tables, views, routines,
  or events.
- An automatically created schema is dropped after the run. For a DBA-prepared
  schema, the wizard chooses whether to leave it empty or drop it.
- Real runs require the PHP `pcntl` extension so `SIGINT` and `SIGTERM` invoke
  cleanup. `--allow-unsafe-signals` is an explicit exception and is recorded in
  the manifest.
- Raw and final SQL files are staged privately with mode `0600`. The raw source
  dump and seed-bearing transformation SQL are removed before publication.
- WP-CLI config output and combined MySQL credentials use transient mode-`0600`
  files; passwords are never placed in process arguments.
- Source and final dumps use `--skip-triggers`. The verification gate also
  checks that the working schema contains no triggers.
- The gzip stream and SHA-256 digest are verified before publication.
- Database cleanup must complete before any artifact is published. The
  manifest is moved last, so its presence marks a complete export.
- The output directory is required to be a real directory with mode `0700`;
  published files use mode `0600`.

Cleanup is best-effort after abnormal process termination. `SIGKILL`, a host
crash, loss of database connectivity, or loss of privileges cannot be handled
by any shutdown routine. After such an event, inspect and remove any
`anon_tmp_` schema and `.staging-*` directory before retrying.

## Requirements

- PHP CLI 7.4 or newer
- PHP `zlib` extension
- PHP `pcntl` extension for real runs, unless the unsafe override is accepted
- WP-CLI in `PATH`
- `mysql` and `mysqldump`, or the MariaDB equivalents
- Free local disk space of roughly 2.5 times the source database size
- Either permission to create/drop a database or an empty DBA-prepared schema
  whose name starts with `anon_tmp_`
- Read privileges on the source plus DDL/DML and temporary-table privileges on
  the working schema; dropping a DBA-prepared schema additionally needs the
  corresponding database privilege

No dependency installation or build step is required.

## Usage

```bash
# Inspect the plan. The source database remains read-only.
php wp-anonymizer.php --path=/var/www/shop --dry-run

# Perform an export.
php wp-anonymizer.php \
    --path=/var/www/shop \
    --output-dir=/srv/anon-export

# Replay a reviewed version-1 configuration.
php wp-anonymizer.php \
    --path=/var/www/shop \
    --config=/srv/anon-export/run-config.json
```

Available options:

| Option | Meaning |
|---|---|
| `--path=DIR` | WordPress installation root; defaults to the current directory |
| `--output-dir=DIR` | Private output directory; defaults to `./anon-export` |
| `--seed-file=FILE` | File containing exactly 64 hexadecimal seed characters |
| `--unknown=ACTION` | Default for unknown tables: `schema`, `copy`, or `exclude` |
| `--config=FILE` | Replay a `run-config.json` with `config_version: 1` |
| `--dry-run` | Build the plan without creating or modifying a database schema |
| `--allow-unsafe-signals` | Permit a real run without `pcntl`; recorded as an exception |
| `-h`, `--help` | Show CLI help |

Unknown CLI options and unsupported config versions fail closed.

### Dry-run behavior

A dry run performs source inventory and suspicious-key scans using read-only
queries. It does not create a working schema, dump data, create a seed, or run
transformation SQL. It does create or permission the local output directory and
writes `run-config.json` with mode `0600`. A transient credentials file is used
for database reads and removed by shutdown cleanup.

## Wizard decisions

1. **Output directory and space check.** The directory is created or restricted
   to mode `0700`; low free space requires explicit confirmation.
2. **Unknown tables.** Tables outside the catalogue default to structure-only.
   They can instead be excluded or reviewed per plugin family/table. Copying an
   unknown table with data is highlighted and recorded as an exception.
3. **Suspicious metadata.** The script scans keys and value shapes in
   `usermeta`, `postmeta`, HPOS order meta, order-item meta, comment meta, and
   options. Values are never printed. Each unhandled key defaults to redaction
   or can be preserved explicitly. Preserved choices require typing
   `ACCEPT RESIDUAL RISK` and appear in the manifest.
4. **Order window.** Keep every order or only the latest N months, including
   related addresses, metadata, line items, comments, and lookup rows.
5. **Service administrator.** Disabled by default. When enabled, the script
   generates a random login and strong password and hashes the password locally
   with WordPress's bundled password implementation. The clear-text password is
   shown once at successful completion and is never saved in the manifest,
   run config, SQL command line, or output files.
6. **Attachment records.** Kept by default for test fidelity, but recorded as a
   privacy exception because metadata and EXIF-related fields may contain PII.
   Choosing removal deletes database attachment records and related rows; it
   does not remove files from the WordPress uploads directory.
7. **Free text.** Product-review and other comment bodies default to redaction.
   Preserving them is an explicit, recorded exception.
8. **Confirmation.** A real run starts only after the operator types
   `ANONYMIZE`.

The config file records decisions and discovered table/metadata keys, but not
the scanned field contents. Treat it as sensitive: keys, paths, and policy
choices can still reveal implementation details.

## Transformations

- **Users:** login, email, nicename, display name, URL, activation keys, session
  tokens, application passwords, and password hashes.
- **Customer data:** first and last names, addresses, cities, postcodes, phone
  numbers, companies, VAT numbers, and tax codes in user meta, legacy orders,
  and HPOS tables.
- **Orders:** billing/shipping fields, IP addresses, user agents, customer
  notes, order keys, transaction identifiers, and payment-related tokens.
- **Comments and order notes:** author identity, email, IP, user agent, Akismet
  payloads, and—unless excepted—comment bodies.
- **Secrets:** suspicious API keys, tokens, passwords, SMTP/payment credentials,
  application-password data, and transients.
- **Operational data:** known log, session, queue, analytics, and security tables
  are exported structure-only or excluded according to the catalogue.

Pseudonyms are deterministic functions of the normalized original value, a
field-purpose salt, and a private 256-bit seed. They do not depend on row IDs.
Equal normalized inputs in the same field category therefore map consistently,
including across legacy and HPOS storage. Synthetic Italian phone numbers, VAT
numbers, and tax codes include their expected format/check character.

## Verification gate

Before producing an artifact, the script checks for:

- non-synthetic email addresses and IP addresses;
- unchanged source values in identity, contact, fiscal, and order fields;
- surviving password/application-password material;
- Akismet payloads and secret-bearing options;
- data in tables classified as structure-only;
- unexpected triggers;
- residuals that were selected for redaction;
- orphaned WooCommerce order-item metadata;
- attachment rows when attachment removal was requested.

Every check and row count is written to the manifest. If any mandatory check
fails, no artifact is published and cleanup runs.

## Output

For run ID `<run-id>`, a successful export produces:

| File | Contents |
|---|---|
| `wp-anon-<run-id>.sql.gz` | Compressed SQL artifact |
| `wp-anon-<run-id>.sha256` | SHA-256 checksum for that artifact |
| `wp-anon-<run-id>.manifest.json` | Evidence, checks, exceptions, and cleanup status |
| `run-config.json` | Replayable wizard choices (`config_version: 1`) |
| `.anon-seed` (or `--seed-file`) | Private pseudonymization seed; never transfer with the dump |

The manifest includes `privacy_status`, per-check results, declared
`exceptions`, cleanup evidence, signal/triggers safety state, pseudonymization
metadata, and per-table actions/row counts. `privacy_status` is
`verified_with_exceptions` whenever the operator retains attachments, preserves
suspicious/free-text data, copies unknown data, or uses the unsafe signal
override.

The source database name and seed fingerprint in the manifest can themselves
be sensitive. Keep the entire output directory access-controlled.

## Transfer and import

Encrypt the archive before transfer:

```bash
gpg -c --cipher-algo AES256 wp-anon-<run-id>.sql.gz
```

Send the passphrase and checksum over a channel separate from the artifact.
Never put the passphrase directly in a command-line argument.

The recipient should:

```bash
# 1. Decrypt (GnuPG prompts interactively).
gpg --output wp-anon-<run-id>.sql.gz \
    --decrypt wp-anon-<run-id>.sql.gz.gpg

# 2. Verify integrity.
sha256sum -c wp-anon-<run-id>.sha256       # Linux
shasum -a 256 -c wp-anon-<run-id>.sha256  # macOS

# 3. Import into a new, empty test database.
mysql -u root -p -e \
    "CREATE DATABASE shop_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c wp-anon-<run-id>.sql.gz | mysql -u root -p shop_test

# 4. Rewrite URLs after import; WordPress serialization is handled by WP-CLI.
wp search-replace \
    'https://www.shop-production.tld' \
    'https://shop.test.local' \
    --path=/var/www/shop-test --all-tables --precise
```

Stop if checksum verification does not report `OK`.

## Pseudonymization and residual risk

The persistent seed intentionally makes exports stable and linkable. Anyone
holding the seed and candidate source values can test those candidates against
the pseudonyms. Even without the seed, rare attributes, free text, retained
media, copied plugin data, and combinations of quasi-identifiers may permit
re-identification.

Deleting or rotating `.anon-seed` reduces linkability between future exports;
it does **not** automatically make an existing or future dataset anonymous.
Whether data is anonymous requires a documented risk assessment considering
the data, recipients, auxiliary information, and realistic attack means. Until
then, handle the export as pseudonymized personal data under the applicable
data-protection obligations.

## Known limitations

- Attachment records are retained by default and always make the result
  `verified_with_exceptions` unless the operator removes them.
- Preserved free text or suspicious keys are not inspected semantically and may
  contain direct identifiers.
- Unknown tables copied with data have no table-specific sanitization contract.
- URLs and serialized URL references are deliberately not rewritten.
- Media files are outside the database export and are never transformed.
- Trigger omission improves safety but can reduce fidelity for plugins that
  rely on database triggers.
- The seed value is sent to the working database session to compute
  pseudonyms. Database general/audit logs may therefore capture it; review and
  protect server-side logging and retention as part of the operating procedure.
- Automated residual checks cover known patterns; they cannot prove that novel
  plugin schemas, encrypted payloads, binary blobs, or unexpected encodings are
  free of personal data.

## Manual release checklist

Run these checks for every change:

```bash
php -l wp-anonymizer.php
php wp-anonymizer.php --help
git diff --check
```

Then use only a disposable WordPress/WooCommerce fixture and a dedicated output
directory:

- Run `--dry-run`; confirm no schema is created or modified and
  `run-config.json` has mode `0600` and `config_version: 1`.
- Run once with automatic schema creation and once with a DBA-prepared empty
  schema; test both `empty` and `drop` cleanup choices.
- Interrupt a real run with `SIGINT` and `SIGTERM`; confirm the working schema,
  raw dump, generated SQL, credentials file, and staging directory are gone.
- Force a verification failure; confirm no manifest or partial artifact is
  published.
- Import a successful artifact into an empty disposable database and run the
  residual queries independently.
- Validate generated phone, VAT, and tax-code formats/check characters.
- Confirm order-window deletion leaves no orphaned item meta or related order
  rows in legacy and HPOS storage.
- Test both attachment choices and both free-text choices; reconcile manifest
  exceptions and `privacy_status` with the selections.
- Enable the service administrator; verify login, confirm the password is shown
  once, and search all output files to ensure the clear-text password is absent.
- Run without `pcntl`: confirm a real run is blocked unless
  `--allow-unsafe-signals` is provided and the exception appears in the
  manifest.
- Check artifact, checksum, manifest, config, seed, and directory permissions.

Never use production credentials or real customer dumps as test fixtures.
