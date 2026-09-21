# Restore address preparation

New backups publish a version-1 URL inventory using the existing protected backup
log transport, alongside the part and size manifests. Records are bucket-bound,
chunked below the log's 2,000-byte line limit, and verified with a SHA-256 digest.
This needs no backend deployment. Query strings and credentials are excluded from
examples; the inventory contains at most 40 candidates. Discovery retains at most
1,000 hosts and reports truncation.

The PHP exporter collects values as rows are written. The CLI exporter streams
the completed SQL dump with bounded memory; it does not reread the database.
Preparation validates the recovery code and reads the inventory before any
destructive work. A 15-minute token binds choices to the administrator, recovery
code, destination and excluded sections. Only candidate IDs come from the browser.

The worker copies the approved plan into memory before replacing the options
table, then applies primary and selected extra mappings in the same database
pass. Older/incomplete inventories use the post-restore fallback. Successful
pre-reviewed restores do not prompt again. Failed extra rewrites retain approved
candidates for retry.

## Automated checks

Run from the plugin directory:

```sh
for test_file in tests/repro-*.php; do php "$test_file" || exit 1; done
node --check js/admin-script.js
npm install --prefix /tmp/ocm-ui-tests --no-audit --no-fund jsdom jquery@3.7.1
NODE_PATH=/tmp/ocm-ui-tests/node_modules node tests/restore-precheck-ui.cjs
```

The UI test renders the real admin template with minimal WordPress stubs and
runs the real JavaScript in jsdom with simulated API responses. Tests cover
streamed SQL chunk boundaries, missing/corrupt/empty inventories, bucket isolation,
excluded databases, stale and replayed tokens, unchecked choices, URL previews,
selected-only rewrites, code/section changes, and no-candidate continuation.

## Live WordPress smoke test

This remains a release check; automated tests use database and API doubles.

1. Seed source posts/options with the primary URL, a temporary `/~username` URL,
   its longer-host collision, an external embed, and serialized settings. Create
   a backup with both PHP and CLI exporters where available.
2. On a disposable destination, enter the recovery code. Verify preparation
   leaves content, plugins and maintenance state untouched.
3. Check only the temporary domain. Confirm its preview, then start restore.
   Verify primary and selected URLs change, unrelated URLs stay intact,
   serialized settings load, and no second checklist appears.
4. Repeat with no boxes checked, a legacy backup, a database-excluded restore,
   and a simulated update failure. Confirm the appropriate fallback/retry screen.
