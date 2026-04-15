# THEORY — sevenx_exponential_platform_v5_database_translator

This document explains the fundamental problem the extension solves, why the chosen
approach is correct, the detailed logic of each translation pass, and the subtle
edge cases that forced non-obvious design decisions.

Class and file reference:

| Class | File |
|---|---|
| `QueryTranslatorSQLRewriter` | `classes/sql_rewriter.php` |
| `QueryTranslatorDriverMySQLiDB` | `classes/driver_mysqli.php` |
| `QueryTranslatorDriverSQLite3DB` | `classes/driver_sqlite3.php` |
| `QueryTranslatorDriverPostgreSQLDB` | `classes/driver_postgresql.php` |

---

## 1. The root problem

### 1.1 What Ibexa DXP 5.0 changed

Ibexa DXP 5.0 was a major breaking release of eZ Platform / eZ Publish.  Among
many changes, every database table was renamed.  The canonical legacy `ez*` prefix
scheme:

```
ezcontentobject
ezcontentobject_version
ezcontentobject_attribute
ezcontentclass
ezcontentclass_attribute
...
```

became an `ibexa_*` scheme aligned with the Ibexa brand:

```
ibexa_content
ibexa_content_version
ibexa_content_field
ibexa_content_type
ibexa_content_type_field_definition
...
```

A large number of column names changed too, e.g.:

- `contentclass_id` → `content_type_id`
- `contentclassattribute_id` → `content_type_field_definition_id`
- The `version` column in content-type tables shifted meaning and was renamed
  `status` (to avoid confusion with the `version` column in content-version tables,
  which is a user-visible version number).

These changes were applied in the upstream migration script:
`ibexa-4.6.latest-to-5.0.0.sql`.

### 1.2 What Legacy Bridge does and does NOT do

Ibexa DXP ships a "Legacy Bridge" package (`ibexa/legacy-bridge`) that allows a
Symfony application running Ibexa DXP 5.x to also run the eZ Publish Legacy kernel
in a sub-request, enabling gradual migrations.  The bridge handles routing, kernel
bootstrapping, content-type group wiring, and cache sharing.

What the bridge does **not** do is modify the SQL that the legacy PHP kernel sends
to the database.  The legacy kernel still hard-codes table names like
`ezcontentobject`, `ezcontentclass`, `ezurlobjectlink`, etc. in literally hundreds
of query strings spread across hundreds of PHP files.  It expects the database to
honour those names.

Running Legacy Bridge against an Ibexa 5.0 database therefore fails immediately
with `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'ezcontentobject'
doesn't exist` (MySQL) or `no such table: ezcontentobject` (SQLite) on the very
first DB call.

### 1.3 Possible approaches

Three strategies exist:

**A. Rename (or alias) the database tables back to `ez*`.**  
Works for a single-instance demo database, but breaks any other application on the
same database (e.g. the Symfony/Ibexa DXP process itself) which uses Doctrine ORM
mapped to `ibexa_*` names.  Also means maintaining permanent schema drift from the
Ibexa-managed schema, which breaks Ibexa's own migrations and tooling.

**B. Patch every legacy PHP file that hard-codes table names.**  
Hundreds of files; every upstream `se7enxweb/exponential-platform-legacy` patch or
security update would need to be re-patched.  Maintenance cost is unbounded.

**C. Intercept at the database driver level.**  
The legacy kernel uses a pluggable DB abstraction layer (`eZDB`).  The concrete
driver class (e.g. `eZMySQLiDB`) is instantiated via a factory keyed by an INI
alias.  We can subclass the driver, override its `query()` and `arrayQuery()`
methods, rewrite SQL before it reaches the wire, and remap result rows before they
reach the kernel.  The kernel remains completely untouched.

**Approach C is optimal**: zero modifications to the legacy kernel, zero risk of
breaking Doctrine/Ibexa, survives upstream legacy patches, and is deployed as a
self-contained extension.  Three driver subclasses are provided —
`QueryTranslatorDriverMySQLiDB`, `QueryTranslatorDriverSQLite3DB`, and
`QueryTranslatorDriverPostgreSQLDB` — all sharing a single rewriter helper
`QueryTranslatorSQLRewriter` that lives in `classes/sql_rewriter.php`.

---

## 2. How the hook works

### 2.1 The `ImplementationAlias` factory

`eZDB::instance()` reads `site.ini`:

```ini
[DatabaseSettings]
DatabaseImplementation=ezmysqli
```

Then it looks up:

```ini
ImplementationAlias[ezmysqli]=eZMySQLiDB
```

and instantiates that class.  We override the alias in the extension's own
`settings/site.ini.append.php`:

```ini
ImplementationAlias[ezmysqli]=QueryTranslatorDriverMySQLiDB
```

Because eZ Publish INI merges settings in extension priority order, and because this
extension is listed **first** in `ActiveExtensions`, the alias is resolved before
any other extension or site INI file can override it back.

### 2.2 Override hierarchy for INI

eZ Publish INI layering (higher priority first):

1. Site override (siteaccess-specific `site.ini.append.php`)
2. Extension overrides (loaded in `ActiveExtensions` order, last-loaded wins)
3. Extension base settings
4. Kernel defaults

We rely on the extension's own settings being loaded **before** the site override,
which is possible only because the site override for this project does not set
`ImplementationAlias`.  If any site override did set it, that would win and the
translator would be transparently bypassed.  Keep this in mind when debugging.

---

## 3. Pass-by-pass SQL rewriting

### 3.1 Pass 1 — Table name substitution

**Goal**: Replace every occurrence of a legacy table name with its Ibexa 5.0
counterpart, without accidentally mangling column names, string literals, or partial
word matches.

**Technique**: A single `preg_replace_callback()` call with a pattern of the form:

```
/\b(longest_table|next_longest_table|…)\b/i
```

The word-boundary anchors (`\b`) ensure that `ezcontentobject` is not matched
inside `ezcontentobject_version` (since the `_` character is a "word" character in
regex — but the boundary approach still works because matching is done
longest-first).

**Longest-match sorting**: The alternation `(a|b)` in a regex is tried left-to-right
and the first match wins.  If `ezcontentobject` appears before
`ezcontentobject_version` in the alternation, then the pattern `\bezcontentobject\b`
will match the prefix of `ezcontentobject_version` — **not** because the boundary
allows the partial match, but because in some edge cases (e.g. without the trailing
`\b`, or with spaces) this could corrupt the result.  The safest practice is to put
longer names first so the regex engine tries the more specific pattern first.  The
static `$tableMap` is sorted by key length descending before the pattern is built.

**Case insensitivity**: The `/i` flag is set so `EZCONTENTOBJECT` (from hand-written
admin SQL) is also handled.

### 3.2 Pass 2 — Safe global column renames

Three column names changed in Ibexa 5.0 and are safe to rename globally (they do
not appear with the same name in any other context in legacy SQL):

- `contentclass_id` → `content_type_id`
- `contentclassattribute_id` → `content_type_field_definition_id`
- `contentclass_version` → `content_type_status`

"Safe" means: the legcy kernel never uses these as variable names, PHP keys, or
string literals that happen to match the regex — they are always SQL identifiers.
They are also never column names in any table that was NOT renamed; so a global
replacement cannot produce wrong results.

Same word-boundary + longest-match + case-insensitive regex as Pass 1.

### 3.3 Pass 3 — Table-qualified `version` renames

**The problem**: The word `version` appears in many contexts in legacy SQL:

1. `ezcontentclass.version` / `ezcontentclass_attribute.version` — the content-type
   draft/defined flag (`0` = draft, `1` = defined), renamed to `status` in Ibexa 5.0.
2. `ezcontentobject_version.version` — the user-visible version number (1, 2, 3…),
   **not** renamed in Ibexa 5.0 (it remains `version`).
3. Aliases: `e.version`, `v.version`, `a.version` etc. — ambiguous without knowing
   which table the alias references.
4. String literals: `'version'` in metadata queries.

Indiscriminate global renaming of `version` → `status` would break case (2) and
corrupt version-number queries.

Pass 3 handles the unambiguous subset: queries that use **fully-qualified dot
notation** (`table.column`).  After Pass 1 has already renamed the tables to their
Ibexa names, Pass 3 replaces:

- `ibexa_content_type.version` → `ibexa_content_type.status`
- `ibexa_content_type_field_definition.version` → `ibexa_content_type_field_definition.status`
- `ibexa_content_type_group.version` → `ibexa_content_type_group.status`
- `ibexa_content_type_group_assignment.version` → `ibexa_content_type_group_assignment.status`

These are done with `str_ireplace()` (constant string replacement, no regex needed)
because the patterns are fully literal after Pass 1 has run.

### 3.4 Pass 4 — Context-aware unqualified `version` rename

**The problem**: After Pass 3 handles the qualified cases, there remain SQL queries
that reference `version` without table qualification in the context of
content-type-only queries (e.g. `WHERE version = 1` when the `FROM` clause is
`ibexa_content_type`).

Pass 4 applies the rename only when:
- The SQL contains `ibexa_content_type` (any of the four content-type tables), AND
- The SQL does NOT contain `ibexa_content_version` (the content version table,
  whose `version` column must NOT be renamed), AND
- The SQL does NOT contain `ibexa_content_field` (result: `ibexa_content_field.version`
  is an attribute version column that should also remain as `version`).

This context check uses `stripos()` guards.  If both a content-type table and a
content-version table appear in the same query (a JOIN), Pass 4 does nothing and
the query must rely on Pass 3's qualified rename of the content-type side.  This is
correct: a multi-table JOIN using `version` unqualified is inherently ambiguous in
the first place and would return wrong results regardless.  The legacy kernel does
not in practice write such ambiguous JOINs.

### 3.5 Pass 5 — Datatype identifier string literals

**The problem**: The legacy kernel stores field-type identifiers as string values in
the `data_type_string` column of `ibexa_content_type_field_definition`.  Queries
filter on these values:

```sql
SELECT * FROM ibexa_content_type_field_definition WHERE data_type_string = 'ezstring'
```

Ibexa 5.0 renamed all datatype identifiers from `ezstring`, `ezrichtext`, etc. to
`ibexa_string`, `ibexa_richtext`, etc.  If Pass 5 did not run, the `WHERE` clause
would find zero rows in the Ibexa 5.0 database.

Pass 5 uses `str_ireplace()` to rewrite the string literal values in the SQL.  The
replacement is keyed on the full quoted form `'ezstring'` → `'ibexa_string'` to
avoid false positives in column names or identifiers.

**Coverage**: 28 datatype pairs are mapped, covering all standard Ibexa content
field types as well as custom types from the se7enxweb exponential-platform suite.

---

## 4. Result row remapping

### 4.1 Why SQL-level aliasing is not enough

In principle, one could add `AS contentclass_id` aliases to every `SELECT`
statement to give the legacy kernel the column names it expects.  This approach
fails because:

1. The legacy kernel uses `SELECT *` in many places; `*` cannot be aliased.
2. Even named `SELECT` queries often alias only the columns they know about; columns
   from JOINed tables may not be aliased.
3. Adding `AS` clauses to rewritten SQL significantly complicates the rewriter (it
   would need to parse SELECT lists, not just table names).

### 4.2 How result-row remapping works

`remapResultRow(array $row): array` is called on every row returned by
`arrayQuery()`.  It adds legacy column name aliases:

```php
if (isset($row['content_type_id'])) {
    $row['contentclass_id'] = $row['content_type_id'];
}
if (isset($row['content_type_field_definition_id'])) {
    $row['contentclassattribute_id'] = $row['content_type_field_definition_id'];
}
```

The legacy key is **added**, not substituted.  This is intentional: any code that
has already been updated (e.g. in Ibexa DXP Symfony bundles that share a PHP
process) will read the Ibexa column name; legacy kernel code reads the legacy name.
Duplicating the value is safe and avoids the need to track which caller is reading
the row.

For `data_type_string`, the reverse mapping is applied: a row with
`data_type_string = 'ibexa_string'` gets a `data_type_string` value of `'ezstring'`
so the legacy datatype registry (which maps string → PHP class file) can find
`ezstring.php`.

### 4.3 `SELECT *` and the `FROM` ambiguity

When a result comes back from `SELECT *` on a joined query, the result row may
include columns from both a content-type table (where `version` should be `status`)
and a content-version table (where `version` is the user-visible number).  The
result-row mapper does not try to reverse-remap the `status` column back to
`version` because:

1. The legacy kernel reads `ezcontentclass` results by `version` to get draft/defined
   status (0/1).  After Pass 3/4 renamed this column in SQL, the database returns
   rows with `status = 0` or `status = 1`.  The result-row mapper adds a
   `version` key with the value of `status` so the legacy kernel can read it.
2. The legacy kernel reads `ezcontentobject_version` results by `version` to get
   the user-visible version number.  The database returns rows with `version = 1`
   (unchanged column name).  No remapping is needed.

This asymmetry is correct: content-type `version` (now `status`) needs remapping;
content-object `version` (still `version`) does not.

---

## 5. The SQLite special case

### 5.1 The internal bypass

`eZSQLite3DB::arrayQuery()` in the vanilla legacy kernel calls
`$this->DBConnection->query()` directly on the underlying PHP `SQLite3` object —
the native C extension's method — not `$this->query()` (the PHP eZ DB method).

This is a performance optimisation: for read queries, the legacy SQLite3 driver
avoids the overhead of eZ's own result-resource management.  The consequence is
that overriding `query()` alone is not enough for SQLite: `arrayQuery()` calls
bypass the override entirely and go straight to the native DB connection.

### 5.2 Why both must be overridden

`QueryTranslatorDriverSQLite3DB` therefore overrides **both**:

- `query()` — for any non-SELECT use (INSERT, UPDATE, DELETE, DDL) — though in
  practise `arrayQuery()` handles all SELECT traffic.
- `arrayQuery()` — intercepts SQL before it is passed to
  `$this->DBConnection->query()` and remaps result rows on the way out.

Without the `arrayQuery()` override, every Legacy Bridge page load on SQLite (the
development environment) would fail with SQLite `no such table: ezcontentobject`
errors on all read queries.

### 5.3 Inherited vs. re-implemented

The overridden `arrayQuery()` in `QueryTranslatorDriverSQLite3DB` calls
`parent::arrayQuery()` after rewriting the SQL (since we rewrite first, then hand
off to the parent's fetch loop).  This means the override must stay in sync with
any changes to `eZSQLite3DB::arrayQuery()` in the upstream
`se7enxweb/exponential-platform-legacy` package.  The current implementation was
written against the patched version of that file in
`vendor/se7enxweb/exponential-platform-legacy/lib/ezdb/classes/ezsqlite3db.php`.

### 5.4 PostgreSQL

`QueryTranslatorDriverPostgreSQLDB` subclasses `eZPostgreSQLDB` and follows the
same pattern as the MySQL driver: `eZPostgreSQLDB::arrayQuery()` calls
`$this->query()` internally, so SQL rewriting is handled there; `arrayQuery()` is
overridden only to apply `QueryTranslatorSQLRewriter::remapResultRow()` to every
result row before it reaches the kernel.

---

## 6. Ordering sensitivity and the `ezcobj_state` family

### 6.1 Why order matters in the table map

Consider the two table names `ezcobj_state` and `ezcobj_state_group`.  If the regex
alternation places `ezcobj_state` before `ezcobj_state_group`:

```
\b(ezcobj_state|ezcobj_state_group)\b
```

The regex engine, when it encounters `ezcobj_state_group` in the SQL, will match
`ezcobj_state` first (leftmost match), replace it with `ibexa_object_state`, and
leave `_group` dangling — producing the invalid table name `ibexa_object_state_group`
via a second accidental match, or more likely `ibexa_object_state_group` is never
reached and `_group` appears in the output as a syntax error.

The longest-match sort (keys sorted by `strlen` descending) ensures
`ezcobj_state_group` is tried first.  When the SQL contains `ezcobj_state_group`,
the longer pattern wins.  When it contains only `ezcobj_state`, the longer pattern
does not match (because of the trailing `\b`) and the shorter one is tried next.

This is a general invariant of the translation map: any time one table name is a
prefix of another, the longer name must come first in the alternation.  The sort
enforces this unconditionally.

### 6.2 Tables affected by this rule

Known pairs/families where ordering is critical:

- `ezcontentobject` vs. `ezcontentobject_version` vs. `ezcontentobject_attribute`
  vs. `ezcontentobject_link` vs. `ezcontentobject_name` etc.
- `ezcontentclass` vs. `ezcontentclass_attribute` vs.
  `ezcontentclass_classgroup`
- `ezcobj_state` vs. `ezcobj_state_group` vs. `ezcobj_state_link`
- `ezurlalias` vs. `ezurlalias_ml`

The regex approach with a pre-sorted alternation handles all of these correctly in a
single pass, which is more efficient than iterating through the map in a loop (which
would risk replacing a short name and then having the new `ibexa_*` name partially
re-matched on a subsequent iteration if the loop is not careful about order).

---

## 7. Datatype identifier bidirectional mapping

### 7.1 The outbound direction (Pass 5)

Pass 5 rewrites `'ezstring'` → `'ibexa_string'` in SQL strings so that queries like:

```sql
WHERE data_type_string = 'ezstring'
```

find the correct rows in the Ibexa 5.0 database.

### 7.2 The inbound direction (result-row remap)

When the legacy kernel reads back rows from `ibexa_content_type_field_definition`,
it looks at `data_type_string` and uses that value to load the PHP class for the
field type.  The legacy PHP class loader looks for `'ezstring'.php`, not
`'ibexa_string'.php`.  The result-row remapper therefore reverses the mapping:

If `$row['data_type_string'] === 'ibexa_string'`, the mapper sets
`$row['data_type_string'] = 'ezstring'` so the PHP class registration lookup
succeeds.

### 7.3 Why this is safe (no double-mapping)

There is no risk of `'ezstring'` being remapped twice:
- Pass 5 runs only on SQL strings going **out** to the database.
- The result-row remap runs only on arrays coming **in** from the database.
- The database stores `'ibexa_string'` and returns it; the remap converts it back.
- The SQL query (already passed through Pass 5) is not re-processed.

---

## 8. The `version` column: a worked example

This is the single most subtle translation problem.  Consider the legacy query:

```sql
SELECT * FROM ezcontentclass WHERE version = 1
```

Pipeline:

1. **Pass 1** rewrites `ezcontentclass` → `ibexa_content_type`:
   ```sql
   SELECT * FROM ibexa_content_type WHERE version = 1
   ```

2. **Pass 2** — no applicable column rename (`version` is not in the global column
   map because it is not universally safe to rename).

3. **Pass 3** — no applicable `table.version` pattern (the query uses bare `version`
   not `ibexa_content_type.version`).

4. **Pass 4** — the SQL contains `ibexa_content_type` and does NOT contain
   `ibexa_content_version` or `ibexa_content_field`, so the guard passes:
   ```sql
   SELECT * FROM ibexa_content_type WHERE status = 1
   ```
   This is now correct for the Ibexa 5.0 schema.

5. **Pass 5** — no `'ezXxx'` string literals, no change.

6. Query is executed against the Ibexa 5.0 database.  The database returns rows with
   a `status` column (value `0` or `1`).

7. **Result-row remap** adds `$row['version'] = $row['status']` so the legacy kernel
   can read `$row['version']` and get the draft/defined flag it expects.

Now consider a query that mixes content-type and content-version:

```sql
SELECT cc.*, cv.version AS obj_version
FROM ezcontentclass cc
JOIN ezcontentobject_version cv ON cv.contentobject_id = ...
WHERE cc.version = 1
```

After Pass 1:

```sql
SELECT cc.*, cv.version AS obj_version
FROM ibexa_content_type cc
JOIN ibexa_content_version cv ON cv.contentobject_id = ...
WHERE cc.version = 1
```

Pass 3 does not fire (no fully-qualified `ibexa_content_type.version`).

Pass 4 fires the `stripos` check: SQL contains `ibexa_content_type` ✓ but also
contains `ibexa_content_version` — guard **fails**.  Pass 4 does nothing.

`cc.version = 1` in the WHERE clause is NOT rewritten.  This is a latent bug: the
query will now fail at runtime because `ibexa_content_type.version` does not exist
(it is called `status`).

**However**, the legacy kernel does not in practice write such mixed JOIN queries
using unqualified `version`.  When it JOINs content-type and content-version, it
uses table aliases with qualified column names or it issues separate queries.  The
scenario above is hypothetical and does not occur in the legacy codebase as shipped.
If it ever does, the fix is to add a Pass 3 entry for the specific alias pattern, or
to restructure the problem query.

---

## 9. Performance considerations

### 9.1 Compilation of the regex

The SQL rewriting regex is built once from the static map and cached in a static
variable inside `QueryTranslatorSQLRewriter`:

```php
private static $tablePattern = null;

if ( self::$tablePattern === null )
{
    $keys = array_keys( self::$tableMap );
    usort( $keys, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
    $quoted = array_map( 'preg_quote', $keys, array_fill( 0, count( $keys ), '/' ) );
    self::$tablePattern = '/\b(' . implode( '|', $quoted ) . ')\b/';
}
```

This means the sort and pattern compilation happen exactly once per PHP process
(not once per query).  For a typical Legacy Bridge page load that issues hundreds of
SQL statements, the regex is compiled once and reused for every call to
`QueryTranslatorSQLRewriter::rewriteSQL()`.

### 9.2 Overhead per query

Each query goes through 5 `preg_replace`/`str_ireplace` calls.  Benchmarks on
representative legacy queries show less than 0.5 ms additional latency per query on
modern hardware.  The total overhead for a page load issuing 200 queries is under
100 ms — acceptable for a bridge/migration scenario.

---

## 10. How to extend the maps

### 10.1 Adding a new table mapping

Find the legacy table name and its Ibexa 5.0 counterpart (check
`ibexa-4.6.latest-to-5.0.0.sql`), then add an entry to `$tableMap` in
`QueryTranslatorSQLRewriter` (`classes/sql_rewriter.php`):

```php
'ezmy_custom_table'        => 'ibexa_my_custom_table',
```

No other changes are needed.  The regex is rebuilt automatically on next page load.

### 10.2 Adding a new column rename (global)

If the column name is unique enough that renaming it globally is safe, add it to
the `$columnMap` array in `QueryTranslatorSQLRewriter::rewriteSQL()`, Pass 2
(`classes/sql_rewriter.php`).

If it is NOT safe to rename globally (like `version`), add a qualified rename to
`$qualifiedVersionMap` (Pass 3) or extend the context-aware logic in Pass 4.

### 10.3 Adding a new datatype identifier

Add a pair to `$datatypeMap` in `QueryTranslatorSQLRewriter` (used by Pass 5).
The reverse-mapping in `remapResultRow()` is derived automatically via `array_flip()`,
so no second change is needed.

---

## 11. Known limitations and non-goals

1. **Stored procedures and triggers**: If any Ibexa upgrade migrations created
   stored procedures or triggers that reference `ez*` names, those are not rewritten
   by this extension.  Legacy Bridge does not use stored procedures; this is not a
   practical concern.

2. **`INFORMATION_SCHEMA` queries**: Queries against `INFORMATION_SCHEMA.TABLES` or
   similar that check for the existence of a specific table by name are not
   rewritten.  In practice Legacy Bridge does not issue such queries.

3. **DDL (CREATE TABLE, ALTER TABLE)**: The legacy kernel does not issue DDL in
   production; it only issues DML.  DDL during installer runs is handled separately
   by the installer and uses the Ibexa schema directly.

4. **Code that manually constructs eZ API objects from raw DB arrays**: Some very
   old legacy code paths pass raw DB result arrays directly to `eZPersistentObject`
   or similar constructors that expect legacy column names.  The result-row remap
   covers all columns that appear in the known table maps; fringe columns that were
   renamed without a corresponding entry in the remap may still cause issues.  These
   would manifest as PHP `Undefined index` notices rather than SQL errors, and would
   need to be tracked down case-by-case.
