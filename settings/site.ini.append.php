<?php /* #?ini charset="utf-8"?

[DatabaseSettings]
## Override the default eZ Publish DB handler aliases with QueryTranslator driver
## subclasses that transparently rewrite legacy ez_/ezcobj_/ezcontentobject_ table
## and column names to Ibexa DXP 5.0+ ibexa_* names on every SQL query execution,
## and reverse-map Ibexa column names back to legacy names in result rows.
##
## QueryTranslatorDriverMySQLiDB   -- overrides query() and arrayQuery()
##   query() rewrites SQL; arrayQuery() additionally remaps result row column names.
##   eZMySQLiDB::arrayQuery() calls $this->query() internally so SQL is rewritten
##   there; arrayQuery() override is needed only for result-row remapping.
##
## QueryTranslatorDriverSQLite3DB  -- overrides query() and arrayQuery() independently
##   eZSQLite3DB::arrayQuery() calls $this->DBConnection->query() directly,
##   bypassing $this->query() entirely, so both methods must be overridden.
##
## QueryTranslatorDriverPostgreSQLDB -- overrides query() and arrayQuery()
##   eZPostgreSQLDB::arrayQuery() calls $this->query() internally (same as MySQL);
##   arrayQuery() override is needed only for result-row remapping.
##
## Class files: extension/sevenx_exponential_platform_v5_database_translator/classes/
##   sql_rewriter.php       -- QueryTranslatorSQLRewriter (shared rewriter/remapper)
##   driver_mysqli.php      -- QueryTranslatorDriverMySQLiDB
##   driver_sqlite3.php     -- QueryTranslatorDriverSQLite3DB
##   driver_postgresql.php  -- QueryTranslatorDriverPostgreSQLDB

ImplementationAlias[ezmysqli]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[ezmysql]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[mysql]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[mysqli]=QueryTranslatorDriverMySQLiDB
ImplementationAlias[sqlite3]=QueryTranslatorDriverSQLite3DB
ImplementationAlias[ezpostgresql]=QueryTranslatorDriverPostgreSQLDB
ImplementationAlias[postgres]=QueryTranslatorDriverPostgreSQLDB
ImplementationAlias[postgresql]=QueryTranslatorDriverPostgreSQLDB
ImplementationAlias[pgsql]=QueryTranslatorDriverPostgreSQLDB

*/ ?>
