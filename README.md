# sevenx_exponential_platform_v5_database_translator

An eZ Publish Legacy extension that makes a **fully unmodified eZ Publish Legacy
kernel** operate transparently on top of an **Ibexa DXP 5.0+ database schema** —
the database whose tables are named `ibexa_*` rather than the legacy `ez*`,
`ezcobj_*`, `ezcontentobject_*` prefixes.

It works by subclassing the three concrete DB handler classes (`eZMySQLiDB`,
`eZSQLite3DB`, and `eZPostgreSQLDB`) and intercepting every SQL statement and every
result row at the lowest possible layer, rewriting legacy names outbound and Ibexa
names inbound, so the thousands of `SELECT … FROM ezcontentobject …` queries inside
the legacy kernel never need to be touched.

---

## Directory layout

```
sevenx_exponential_platform_v5_database_translator/
├── README.md                        ← you are here
├── doc/
│   └── THEORY.md                    ← deep technical theory and design rationale
├── extension.xml                    ← extension metadata (no dependencies)
├── autoloads/
│   └── ezp_extension.php            ← eZ Publish class autoload map
├── settings/
│   └── site.ini.append.php          ← DatabaseSettings.ImplementationAlias overrides
└── classes/
    ├── sql_rewriter.php             ← QueryTranslatorSQLRewriter (shared rewriter + row remapper)
    ├── driver_mysqli.php            ← QueryTranslatorDriverMySQLiDB
    ├── driver_sqlite3.php           ← QueryTranslatorDriverSQLite3DB
    └── driver_postgresql.php        ← QueryTranslatorDriverPostgreSQLDB
```

---

## What the extension does

### The problem in one sentence

The Ibexa DXP 5.0 upgrade renamed every database table from the legacy `ez*`
naming convention to an `ibexa_*` convention (e.g. `ezcontentobject` →
`ibexa_content`, `ezcontentclass` → `ibexa_content_type`).  A large number of
column names changed too (`contentclass_id` → `content_type_id`, `version` →
`status` in some tables, etc.).  The legacy PHP kernel still generates SQL using
the old names.  Running the legacy kernel against an Ibexa 5.0 database fails with
`table not found` errors on the very first query.

### The solution in one sentence

Intercept every SQL query just before it hits the wire and every result row just
after it comes back from the wire — rewriting legacy names outbound and Ibexa names
inbound — so neither the legacy kernel nor the Ibexa application sees anything
unexpected.

---

## How activation works

eZ Publish Legacy's database layer uses a factory pattern keyed by the
`DatabaseSettings.ImplementationAlias` INI setting.  When the kernel calls
`eZDB::instance()` it reads this alias to determine which PHP class to instantiate
as the DB handler.  By overriding those aliases to point at our subclasses, we drop
our translation layer into the execution path with zero modifications to the kernel.

The override is set in `settings/site.ini.append.php` (shipped with this extension
and loaded automatically by the legacy INI system because the extension is in
`ActiveExtensions`):

```ini
[DatabaseSettings]
ImplementationAlias[ezmysqli]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[ezmysql]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[mysql]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[mysqli]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[sqlite3]=QueryTranslatorDriverSQLite3DB
ImplementationAlias[ezpostgresql]=QueryTranslatorDriverPostgreSQLDB
ImplementationAlias[postgres]=QueryTranslatorDriverPostgreSQLDB
ImplementationAlias[postgresql]=QueryTranslatorDriverPostgreSQLDB
ImplementationAlias[pgsql]=QueryTranslatorDriverPostgreSQLDB
```

This covers `MySQLi` (production), `SQLite3` (development/testing), and `PostgreSQL`.

---

## PHP classes

### `QueryTranslatorSQLRewriter` — `classes/sql_rewriter.php`

A pure static helper class shared by all three driver overrides.  It holds the
translation maps and exposes two public methods:

#### `rewriteSQL( string $sql ): string`

Runs five passes over the SQL string in order:

| Pass | What it does | Technique |
|------|-------------|-----------|
| 1 | Table name rewriting (`ezcontentobject` → `ibexa_content`, etc.) | `preg_replace_callback` with `\b` word boundaries, longest-match sorted alternation |
| 2 | Safe global column renames (`contentclass_id` → `content_type_id`, `contentclassattribute_id` → `content_type_field_definition_id`, `contentclass_version` → `content_type_status`) | Same regex approach |
| 3 | Table-qualified `version` renames for mixed-table queries (`ibexa_content_type.version` → `ibexa_content_type.status`, `ibexa_content_type_field_definition.version` → `.status`, etc.) | `str_ireplace` on fully-qualified `table.column` strings |
| 4 | Context-aware unqualified `\bversion\b` → `status` rename, only when SQL references a content-type table but not any version-number table | `preg_replace` guarded by `stripos` checks |
| 5 | Datatype identifier value rewrites (`'ezstring'` → `'ibexa_string'` in quoted SQL literals) so `WHERE data_type_string = 'ezstring'` still finds the right rows | `str_ireplace` on `'ezXxx'` quoted literals |

#### `remapResultRow( array $row ): array`

Reverse-maps column names in a result row returned by `arrayQuery()`.  Because the
legacy kernel often uses `SELECT *` and then reads result array keys by the old
column names, we must add the legacy key as an alias alongside the new key rather
than replacing it.  It also reverses the datatype identifier values in the
`data_type_string` column so the legacy kernel's datatype registry (which looks up
files like `ezstring.php`) can find the right class.

### `QueryTranslatorDriverMySQLiDB extends eZMySQLiDB` — `classes/driver_mysqli.php`

Overrides:
- `query()` — rewrites SQL before executing any statement (INSERT, UPDATE, DELETE,
  SELECT).
- `arrayQuery()` — rewrites SQL **and** remaps result rows.  Although
  `eZMySQLiDB::arrayQuery()` delegates to `$this->query()` internally (so SQL is
  already rewritten), the result-row remapping must still happen here because
  `query()` returns a raw result resource, not an array.

### `QueryTranslatorDriverSQLite3DB extends eZSQLite3DB` — `classes/driver_sqlite3.php`

Overrides both `query()` and `arrayQuery()`.  Unlike MySQL, `eZSQLite3DB::arrayQuery()`
calls `$this->DBConnection->query()` **directly** on the underlying `SQLite3` object,
completely bypassing `$this->query()`.  If we only override `query()`, all SELECT
reads go through the raw SQLite3 connection unmodified with untranslated SQL.
Both methods must be overridden independently.  See `doc/THEORY.md §5`.

### `QueryTranslatorDriverPostgreSQLDB extends eZPostgreSQLDB` — `classes/driver_postgresql.php`

Overrides:
- `query()` — rewrites SQL before executing any statement.
- `arrayQuery()` — remaps result row column names.  `eZPostgreSQLDB::arrayQuery()`
  calls `$this->query()` internally (same pattern as MySQL), so SQL is already
  rewritten there; `arrayQuery()` is overridden only for result-row remapping.

---

## Translation maps

### Table map (excerpt)

| Legacy name | Ibexa DXP 5.0 name |
|---|---|
| `ezcontentobject` | `ibexa_content` |
| `ezcontentobject_version` | `ibexa_content_version` |
| `ezcontentobject_attribute` | `ibexa_content_field` |
| `ezcontentclass` | `ibexa_content_type` |
| `ezcontentclass_attribute` | `ibexa_content_type_field_definition` |
| `ezcontentclassgroup` | `ibexa_content_type_group` |
| `ezcontentclass_classgroup` | `ibexa_content_type_group_assignment` |
| `ezpolicy` | `ibexa_policy` |
| `ezpolicy_limitation` | `ibexa_policy_limitation` |
| `ezpolicy_limitation_value` | `ibexa_policy_limitation_value` |
| `ezrole` | `ibexa_role` |
| `ezuser` | `ibexa_user` |
| `ezuser_role` | `ibexa_user_role` |
| `ezcobj_state` | `ibexa_object_state` |
| `ezcobj_state_group` | `ibexa_object_state_group` |
| `ezurlalias_ml` | `ibexa_url_alias_ml` |
| `eznode_assignment` | `ibexa_node_assignment` |
| `ezsection` | `ibexa_section` |
| `ezdfsfile` | `ibexa_dfs_file` |
| `ezpreferences` | `ibexa_user_preference` |
| `ezcontentbrowsebookmark` | `ibexa_content_bookmark` |

Full list in `classes/sql_rewriter.php` → `QueryTranslatorSQLRewriter::$tableMap`.

### Column map

| Legacy column | Ibexa DXP 5.0 column |
|---|---|
| `contentclass_id` | `content_type_id` |
| `contentclassattribute_id` | `content_type_field_definition_id` |
| `contentclass_version` | `content_type_status` |
| `version` *(in content-type tables only)* | `status` |

### Datatype identifier map (excerpt)

| Legacy value | Ibexa DXP 5.0 value |
|---|---|
| `ezstring` | `ibexa_string` |
| `ezrichtext` | `ibexa_richtext` |
| `ezinteger` | `ibexa_integer` |
| `ezimage` | `ibexa_image` |
| `ezbinaryfile` | `ibexa_binaryfile` |
| `ezgmaplocation` | `ibexa_gmap_location` |
| `ezobjectrelationlist` | `ibexa_object_relation_list` |

---

## Activation in `site.ini.append.php` override files

The legacy kernel loads INI files in priority order.  The extension's own
`settings/site.ini.append.php` sets the `ImplementationAlias` entries.  The
site-level override file(s) must list this extension in `ActiveExtensions` **before**
any extension that uses the database, so the subclasses are registered before any
`eZDB::instance()` call fires.

`src/LegacySettings/override/site.ini.append.php` (site-level override):

```ini
[ExtensionSettings]
ActiveExtensions[]
ActiveExtensions[]=sevenx_exponential_platform_v5_database_translator
ActiveExtensions[]=app
ActiveExtensions[]=ngsite
...
```

The extension must be **first** in `ActiveExtensions` for the alias override to
take effect before any other extension (such as `ngsymfonytools`) opens a DB
connection.

---

## Symlink setup

The extension lives in the Symfony project's `src/` tree (so it is tracked in the
project's git repository and deployed alongside application code):

```
src/ezpublish_legacy/sevenx_exponential_platform_v5_database_translator/
```

The eZ Publish Legacy kernel discovers extensions only from its own `extension/`
directory.  A symlink bridges the gap:

```
ezpublish_legacy/extension/sevenx_exponential_platform_v5_database_translator
  → ../../src/ezpublish_legacy/sevenx_exponential_platform_v5_database_translator
```

To recreate the symlink after a fresh checkout:

```bash
ln -s ../../src/ezpublish_legacy/sevenx_exponential_platform_v5_database_translator \
      ezpublish_legacy/extension/sevenx_exponential_platform_v5_database_translator
```

---

## Supported databases

| Driver alias | Class activated | File |
|---|---|---|
| `ezmysqli`, `ezmysql`, `mysql`, `mysqli` | `QueryTranslatorDriverMySQLiDB` | `classes/driver_mysqli.php` |
| `sqlite3` | `QueryTranslatorDriverSQLite3DB` | `classes/driver_sqlite3.php` |
| `ezpostgresql`, `postgres`, `postgresql`, `pgsql` | `QueryTranslatorDriverPostgreSQLDB` | `classes/driver_postgresql.php` |

---

## See also

- `doc/THEORY.md` — deep technical theory, design decisions, edge cases, and a
  worked example tracing a query end-to-end through the translation pipeline.
- `autoloads/ezp_extension.php` — eZ Publish class autoload map; maps each class
  name to its file path for the eZ Publish Legacy autoloader.
- `vendor/ibexa/installer/upgrade/db/mysql/ibexa-4.6.latest-to-5.0.0.sql` — the
  upstream migration script that defines the authoritative table rename mapping.
- `vendor/se7enxweb/exponential-platform-legacy/lib/ezdb/classes/ezsqlite3db.php` —
  the patched SQLite3 DB handler (adds `mkdir` recursive flag for DB directory
  creation) that `QueryTranslatorDriverSQLite3DB` subclasses.
- `vendor/se7enxweb/exponential-platform-legacy/lib/ezdb/classes/ezmysqlidb.php` —
  the MySQLi DB handler that `QueryTranslatorDriverMySQLiDB` subclasses.
- `vendor/se7enxweb/exponential-platform-legacy/lib/ezdb/classes/ezpostgresqldb.php` —
  the PostgreSQL DB handler that `QueryTranslatorDriverPostgreSQLDB` subclasses.
