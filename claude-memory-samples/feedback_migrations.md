---
name: feedback-migrations
description: Never edit an already-migrated file — always create a new migration for schema changes after the fact
metadata:
  node_type: memory
  type: feedback
---

Once a migration has run in any environment (production especially), never edit its file to add/change schema changes — always write a new migration instead.

**Why:** Laravel's `migrate` command tracks completed migrations by filename in the `migrations` table, not by file content. Editing an already-run migration's `up()`/`down()` is silently a no-op on re-run — `php artisan migrate` reports "Nothing to migrate" even though the file on disk now describes something different from what's actually in the database. Hit this directly: added a composite index by editing a migration file that had already run in production; the migration was pushed and `migrate` was run, it reported "Nothing to migrate", and the schema was never updated.

**How to apply:** Before editing any migration file, check whether it's plausible that it already ran somewhere (was it created in an earlier session and likely deployed already? has the owner mentioned migrating?). If there's any chance it ran, create a new migration for the additional change and leave the old file's already-executed content alone (don't even cosmetically edit it — the disk content should match what the migrations table thinks happened, for anyone reading history later).
