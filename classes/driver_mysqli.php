<?php
/**
 * QueryTranslatorDriverMySQLiDB
 *
 * MySQL (MySQLi) DB handler override for Ibexa DXP 5.0+.
 *
 * Subclasses eZMySQLiDB and intercepts every SQL query via query() and every
 * result array via arrayQuery(), delegating all translation work to
 * QueryTranslatorSQLRewriter.
 *
 * Why both methods are overridden:
 *   query()      — rewrites SQL for INSERT, UPDATE, DELETE, and SELECT.
 *   arrayQuery() — eZMySQLiDB::arrayQuery() calls $this->query() internally so
 *                  SQL is already rewritten by the time it reaches the wire;
 *                  arrayQuery() is overridden here solely to remap Ibexa 5.0
 *                  column names in result rows back to the legacy names the
 *                  kernel expects (query() returns a resource, not an array).
 *
 * Activated via:
 *   DatabaseSettings.ImplementationAlias[ezmysqli]=QueryTranslatorDriverMySQLiDB
 *   (and aliases: ezmysql, mysql, mysqli)
 *
 * @see classes/sql_rewriter.php  QueryTranslatorSQLRewriter
 * @see doc/THEORY.md
 */

require_once __DIR__ . '/sql_rewriter.php';

class QueryTranslatorDriverMySQLiDB extends eZMySQLiDB
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
     * Rewrites SQL (already handled by query() internally, but kept here for
     * safety when arrayQuery() is called directly), then remaps Ibexa 5.0
     * column names in result rows back to the legacy names.
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
