<?php
/**
 * QueryTranslatorDriverPostgreSQLDB
 *
 * PostgreSQL DB handler override for Ibexa DXP 5.0+.
 *
 * Subclasses eZPostgreSQLDB and intercepts every SQL query via query() and every
 * result array via arrayQuery(), delegating all translation work to
 * QueryTranslatorSQLRewriter.
 *
 * Why both methods are overridden:
 *   query()      — rewrites SQL for INSERT, UPDATE, DELETE, and SELECT.
 *   arrayQuery() — eZPostgreSQLDB::arrayQuery() calls $this->query() internally
 *                  (same pattern as MySQL) so SQL is already rewritten; arrayQuery()
 *                  is overridden here solely to remap Ibexa 5.0 column names in
 *                  result rows back to the legacy names the kernel expects.
 *
 * Activated via:
 *   DatabaseSettings.ImplementationAlias[ezpostgresql]=QueryTranslatorDriverPostgreSQLDB
 *   (and aliases: postgres, postgresql, pgsql)
 *
 * @see classes/sql_rewriter.php  QueryTranslatorSQLRewriter
 * @see doc/THEORY.md
 */

require_once __DIR__ . '/sql_rewriter.php';

class QueryTranslatorDriverPostgreSQLDB extends eZPostgreSQLDB
{
    /**
     * {@inheritdoc}
     *
     * Rewrites legacy eZ table and column names before executing any statement.
     */
    function query( $sql, $server = false )
    {
        $sql = QueryTranslatorSQLRewriter::rewriteSQL( $sql );
        return parent::query( $sql, $server );
    }

    /**
     * {@inheritdoc}
     *
     * eZPostgreSQLDB::arrayQuery() calls $this->query() internally so SQL
     * rewriting is handled there; this override adds result-row remapping so
     * Ibexa 5.0 column names are aliased back to the legacy names.
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
