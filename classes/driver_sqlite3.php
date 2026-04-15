<?php
/**
 * QueryTranslatorDriverSQLite3DB
 *
 * SQLite3 DB handler override for Ibexa DXP 5.0+.
 *
 * Subclasses eZSQLite3DB and intercepts every SQL query via query() and every
 * result array via arrayQuery(), delegating all translation work to
 * QueryTranslatorSQLRewriter.
 *
 * Why BOTH methods must be independently overridden (unlike MySQL):
 *   eZSQLite3DB::arrayQuery() is a performance-optimised path that calls
 *   $this->DBConnection->query() directly on the underlying PHP SQLite3 object,
 *   completely bypassing $this->query().  Without overriding arrayQuery(), all
 *   SELECT reads go through the raw connection with untranslated SQL, causing
 *   "no such table: ezcontentobject" errors on every read query.
 *
 * Activated via:
 *   DatabaseSettings.ImplementationAlias[sqlite3]=QueryTranslatorDriverSQLite3DB
 *
 * @see classes/sql_rewriter.php  QueryTranslatorSQLRewriter
 * @see doc/THEORY.md §5  SQLite special case
 */

require_once __DIR__ . '/sql_rewriter.php';

class QueryTranslatorDriverSQLite3DB extends eZSQLite3DB
{
    /**
     * {@inheritdoc}
     *
     * Rewrites legacy eZ table and column names before executing INSERT,
     * UPDATE, DELETE, or any non-SELECT statement.
     */
    function query( $sql, $server = false )
    {
        $sql = QueryTranslatorSQLRewriter::rewriteSQL( $sql );
        return parent::query( $sql, $server );
    }

    /**
     * {@inheritdoc}
     *
     * Rewrites SQL before executing SELECT queries and remaps Ibexa 5.0 column
     * names in result rows back to the legacy names.
     *
     * Must be overridden independently of query() because eZSQLite3DB::arrayQuery()
     * bypasses $this->query() entirely and calls $this->DBConnection->query()
     * directly on the underlying SQLite3 connection object.
     */
    function arrayQuery( $sql, $params = array(), $server = false )
    {
        $sql    = QueryTranslatorSQLRewriter::rewriteSQL( $sql );
        $result = parent::arrayQuery( $sql, $params, $server );

        if ( is_array( $result ) )
        {
            return array_map(
                function ( $row ) {
                    return is_array( $row )
                        ? QueryTranslatorSQLRewriter::remapResultRow( $row )
                        : $row;
                },
                $result
            );
        }

        return $result;
    }
}
