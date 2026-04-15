<?php
/**
 * QueryTranslatorSQLRewriter
 *
 * Static helper that rewrites legacy eZ Publish SQL — table names, column names,
 * and datatype identifier string literals — to the Ibexa DXP 5.0+ equivalents,
 * and reverse-maps result row column names back to the legacy names expected by
 * the unmodified Legacy kernel.
 *
 * Used by all three DB driver overrides:
 *   QueryTranslatorDriverMySQLiDB    (classes/driver_mysqli.php)
 *   QueryTranslatorDriverSQLite3DB   (classes/driver_sqlite3.php)
 *   QueryTranslatorDriverPostgreSQLDB (classes/driver_postgresql.php)
 *
 * @see doc/THEORY.md  for a full explanation of the five-pass rewriting strategy.
 * @see vendor/ibexa/installer/upgrade/db/mysql/ibexa-4.6.latest-to-5.0.0.sql
 */
class QueryTranslatorSQLRewriter
{
    /**
     * Map of legacy eZ Publish table name => Ibexa DXP 5.0+ table name.
     * All three DB drivers (MySQL, SQLite3, PostgreSQL) use the same mapping.
     *
     * Keys are sorted longest-first before building the regex alternation, so
     * a longer name (e.g. ezcontentobject_version) is always tried before any
     * shorter prefix (e.g. ezcontentobject).  See doc/THEORY.md §6 for details.
     *
     * @var array<string, string>
     */
    private static $tableMap = [

        // -----------------------------------------------------------------------
        // Content objects / versions / fields
        // -----------------------------------------------------------------------
        'ezcontentobject_attribute'         => 'ibexa_content_field',
        'ezcontentobject_link'              => 'ibexa_content_relation',
        'ezcontentobject_name'              => 'ibexa_content_name',
        'ezcontentobject_trash'             => 'ibexa_content_trash',
        'ezcontentobject_tree'              => 'ibexa_content_tree',
        'ezcontentobject_version'           => 'ibexa_content_version',
        'ezcontentobject'                   => 'ibexa_content',

        // -----------------------------------------------------------------------
        // Content classes (= Content Types in Ibexa terminology)
        // -----------------------------------------------------------------------
        'ezcontentclass_classgroup'         => 'ibexa_content_type_group_assignment',
        'ezcontentclass_attribute'          => 'ibexa_content_type_field_definition',
        'ezcontentclass_name'               => 'ibexa_content_type_name',
        'ezcontentclass'                    => 'ibexa_content_type',
        'ezcontentclassgroup'               => 'ibexa_content_type_group',

        // -----------------------------------------------------------------------
        // Content language
        // -----------------------------------------------------------------------
        'ezcontent_language'                => 'ibexa_content_language',

        // -----------------------------------------------------------------------
        // Object states
        // -----------------------------------------------------------------------
        'ezcobj_state_group_language'       => 'ibexa_object_state_group_language',
        'ezcobj_state_group'                => 'ibexa_object_state_group',
        'ezcobj_state_language'             => 'ibexa_object_state_language',
        'ezcobj_state_link'                 => 'ibexa_object_state_link',
        'ezcobj_state'                      => 'ibexa_object_state',

        // -----------------------------------------------------------------------
        // Node assignments
        // -----------------------------------------------------------------------
        'eznode_assignment'                 => 'ibexa_node_assignment',

        // -----------------------------------------------------------------------
        // Field type storage tables
        // -----------------------------------------------------------------------
        'ezbinaryfile'                      => 'ibexa_binary_file',
        'ezimagefile'                       => 'ibexa_image_file',
        'ezmedia'                           => 'ibexa_media',
        'ezkeyword_attribute_link'          => 'ibexa_keyword_field_link',
        'ezkeyword'                         => 'ibexa_keyword',
        'ezgmaplocation'                    => 'ibexa_map_location',

        // -----------------------------------------------------------------------
        // Roles, policies, limitations
        // -----------------------------------------------------------------------
        'ezpolicy_limitation_value'         => 'ibexa_policy_limitation_value',
        'ezpolicy_limitation'               => 'ibexa_policy_limitation',
        'ezpolicy'                          => 'ibexa_policy',
        'ezrole'                            => 'ibexa_role',

        // -----------------------------------------------------------------------
        // Legacy search index
        // -----------------------------------------------------------------------
        'ezsearch_object_word_link'         => 'ibexa_search_object_word_link',
        'ezsearch_word'                     => 'ibexa_search_word',

        // -----------------------------------------------------------------------
        // Sections & packages
        // -----------------------------------------------------------------------
        'ezsection'                         => 'ibexa_section',
        'ezpackage'                         => 'ibexa_package',

        // -----------------------------------------------------------------------
        // Site data
        // -----------------------------------------------------------------------
        'ezsite_data'                       => 'ibexa_site_data',

        // -----------------------------------------------------------------------
        // URLs and aliases
        // -----------------------------------------------------------------------
        'ezurl_object_link'                 => 'ibexa_url_content_link',
        'ezurlalias_ml_incr'                => 'ibexa_url_alias_ml_incr',
        'ezurlalias_ml'                     => 'ibexa_url_alias_ml',
        'ezurlalias'                        => 'ibexa_url_alias',
        'ezurlwildcard'                     => 'ibexa_url_wildcard',
        'ezurl'                             => 'ibexa_url',

        // -----------------------------------------------------------------------
        // Users
        // -----------------------------------------------------------------------
        'ezuser_accountkey'                 => 'ibexa_user_accountkey',
        'ezuser_role'                       => 'ibexa_user_role',
        'ezuser_setting'                    => 'ibexa_user_setting',
        'ezuser'                            => 'ibexa_user',

        // -----------------------------------------------------------------------
        // DFS (Distributed File System / clustering)
        // -----------------------------------------------------------------------
        'ezdfsfile'                         => 'ibexa_dfs_file',

        // -----------------------------------------------------------------------
        // Content browsing & user preferences
        // -----------------------------------------------------------------------
        'ezcontentbrowsebookmark'           => 'ibexa_content_bookmark',
        'ezpreferences'                     => 'ibexa_user_preference',

    ];

    /** @var string|null Cached regex built from $tableMap keys */
    private static $tablePattern = null;

    /**
     * Safe global column renames.
     *
     * These column names are specific enough that replacing them everywhere
     * in SQL is safe — they do not appear as different-meaning columns in
     * any of the renamed tables.
     *
     * contentclass_id            => content_type_id
     * contentclassattribute_id   => content_type_field_definition_id
     * contentclass_version       => content_type_status
     *
     * @var array<string, string>
     */
    private static $columnMap = [
        'contentclass_id'           => 'content_type_id',
        'contentclassattribute_id'  => 'content_type_field_definition_id',
        'contentclass_version'      => 'content_type_status',
    ];

    /** @var string|null Cached regex built from $columnMap keys */
    private static $columnPattern = null;

    /**
     * Table-qualified 'version' renames for mixed-table queries.
     *
     * After Pass 1 has renamed tables, Pass 3 replaces fully-qualified
     * 'table.version' references where the column meaning changed to 'status'.
     * These are safe to rewrite regardless of what other tables appear in the
     * query because the pattern is fully literal.
     *
     * @var array<string, string>
     */
    private static $qualifiedVersionMap = [
        'ibexa_content_type_field_definition.version' => 'ibexa_content_type_field_definition.status',
        'ibexa_content_type_group_assignment.version' => 'ibexa_content_type_group_assignment.content_type_status',
        'ibexa_content_type_name.version'             => 'ibexa_content_type_name.content_type_status',
        'ibexa_content_type.version'                  => 'ibexa_content_type.status',
    ];

    /**
     * Tables where the legacy unqualified 'version' column (0=draft, 1=defined)
     * was renamed to 'status'.  Pass 4 fires only when the SQL references at
     * least one of these AND none of $versionKeepTables.
     *
     * @var string[]
     */
    private static $versionToStatusTables = [
        'ibexa_content_type',
    ];

    /**
     * Tables that still carry a meaningful 'version' column (user-visible version
     * number, not a draft/defined flag).  Pass 4 is suppressed if any of these
     * appear in the same SQL to avoid corrupting version-number columns.
     *
     * @var string[]
     */
    private static $versionKeepTables = [
        'ibexa_content_version',
        'ibexa_content_field',
    ];

    /**
     * Bidirectional datatype identifier map.
     *
     * In Ibexa DXP 5.0 the field-type identifiers stored in
     * ibexa_content_type_field_definition.data_type_string were renamed from
     * the legacy ez* names to ibexa_* names.
     *
     * Used in TWO directions:
     *   rewriteSQL()       — outbound: replaces 'ezstring' with 'ibexa_string' in
     *                        quoted SQL literals so WHERE/IN clauses match.
     *   remapResultRow()   — inbound: replaces 'ibexa_string' back to 'ezstring'
     *                        in result rows so the legacy datatype registry can
     *                        find PHP files like ezstring.php.
     *
     * @var array<string, string>  legacyName => ibexaName
     */
    private static $datatypeMap = [
        'ezstring'             => 'ibexa_string',
        'ezrichtext'           => 'ibexa_richtext',
        'eztext'               => 'ibexa_text',
        'ezinteger'            => 'ibexa_integer',
        'ezfloat'              => 'ibexa_float',
        'ezemail'              => 'ibexa_email',
        'ezurl'                => 'ibexa_url',
        'ezboolean'            => 'ibexa_boolean',
        'ezdate'               => 'ibexa_date',
        'ezdatetime'           => 'ibexa_datetime',
        'eztime'               => 'ibexa_time',
        'ezimage'              => 'ibexa_image',
        'ezbinaryfile'         => 'ibexa_binaryfile',
        'ezmedia'              => 'ibexa_media',
        'ezkeyword'            => 'ibexa_keyword',
        'ezselection'          => 'ibexa_selection',
        'ezuser'               => 'ibexa_user',
        'ezauthor'             => 'ibexa_author',
        'ezcountry'            => 'ibexa_country',
        'ezobjectrelation'     => 'ibexa_object_relation',
        'ezobjectrelationlist' => 'ibexa_object_relation_list',
        'ezmatrix'             => 'ibexa_matrix',
        'ezxmltext'            => 'ibexa_xmltext',
        'ezgmaplocation'       => 'ibexa_gmap_location',
        'ezidentifier'         => 'ibexa_identifier',
        'ezenum'               => 'ibexa_enum',
        'ezisbn'               => 'ibexa_isbn',
        'ezpage'               => 'ibexa_page',
    ];

    /** @var array<string, string>|null Cached reverse of $datatypeMap (ibexa_* => ez*) */
    private static $datatypeReverseMap = null;

    /**
     * Rewrite legacy eZ Publish table and column names in a SQL string.
     *
     * Five passes, in order:
     *   Pass 1 — Table names: word-boundary regex, longest-match sorted alternation.
     *   Pass 2 — Safe global column renames: contentclass_id, contentclassattribute_id,
     *             contentclass_version.
     *   Pass 3 — Table-qualified version renames: ibexa_content_type.version → .status
     *             etc., safe for mixed-table queries.
     *   Pass 4 — Context-aware unqualified version → status: fires only when SQL
     *             references a content-type table but not a content-version table.
     *   Pass 5 — Datatype identifier string literals: 'ezstring' → 'ibexa_string' etc.
     *
     * @see doc/THEORY.md §3 for full rationale of each pass.
     *
     * @param  string $sql  Raw SQL from the legacy kernel.
     * @return string       Rewritten SQL ready for the Ibexa 5.0 database.
     */
    public static function rewriteSQL( $sql )
    {
        // Build regex patterns once per PHP process.
        if ( self::$tablePattern === null )
        {
            $keys = array_keys( self::$tableMap );
            usort( $keys, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
            $quoted = array_map( 'preg_quote', $keys, array_fill( 0, count( $keys ), '/' ) );
            self::$tablePattern = '/\b(' . implode( '|', $quoted ) . ')\b/';
        }

        if ( self::$columnPattern === null )
        {
            $keys = array_keys( self::$columnMap );
            usort( $keys, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
            $quoted = array_map( 'preg_quote', $keys, array_fill( 0, count( $keys ), '/' ) );
            self::$columnPattern = '/\b(' . implode( '|', $quoted ) . ')\b/';
        }

        // Pass 1: table name rewrites.
        $tableMap = self::$tableMap;
        $sql = preg_replace_callback(
            self::$tablePattern,
            function ( $m ) use ( $tableMap ) { return $tableMap[ $m[1] ]; },
            $sql
        );

        // Pass 2: safe global column renames.
        $columnMap = self::$columnMap;
        $sql = preg_replace_callback(
            self::$columnPattern,
            function ( $m ) use ( $columnMap ) { return $columnMap[ $m[1] ]; },
            $sql
        );

        // Pass 3: table-qualified version renames (handles mixed-table JOINs).
        foreach ( self::$qualifiedVersionMap as $old => $new )
        {
            $sql = str_ireplace( $old, $new, $sql );
        }

        // Pass 4: context-aware unqualified version → status.
        // Fires only when SQL references a content-type table but none of the
        // tables that still carry a version-number column.
        $needsVersionRename = false;
        foreach ( self::$versionToStatusTables as $t )
        {
            if ( stripos( $sql, $t ) !== false )
            {
                $needsVersionRename = true;
                break;
            }
        }
        if ( $needsVersionRename )
        {
            foreach ( self::$versionKeepTables as $t )
            {
                if ( stripos( $sql, $t ) !== false )
                {
                    $needsVersionRename = false;
                    break;
                }
            }
        }
        if ( $needsVersionRename )
        {
            $sql = preg_replace( '/\bversion\b/i', 'status', $sql );
        }

        // Pass 5: datatype identifier string-literal rewrites.
        // Wraps legacy quoted literals e.g. 'ezstring' → 'ibexa_string' so
        // WHERE/IN clauses that filter by data_type_string still match.
        foreach ( self::$datatypeMap as $legacy => $ibexa )
        {
            $sql = str_ireplace( "'" . $legacy . "'", "'" . $ibexa . "'", $sql );
        }

        return $sql;
    }

    /**
     * Add legacy column-name aliases to a result row returned by arrayQuery().
     *
     * The legacy kernel reads result arrays by old column names (contentclass_id,
     * version, etc.) but the Ibexa 5.0 schema uses new names (content_type_id,
     * status, etc.).  SQL-level aliasing cannot fix SELECT * queries, so we fix
     * the rows here instead.
     *
     * Legacy keys are ADDED alongside the new keys, not substituted, so any code
     * that already reads the Ibexa names continues to work unaffected.
     *
     * The data_type_string value is reverse-mapped from ibexa_string → ezstring
     * etc. so the legacy datatype registry can resolve PHP class file names.
     *
     * @param  array<string, mixed> $row  One result row from arrayQuery().
     * @return array<string, mixed>       The same row with legacy key aliases added.
     */
    public static function remapResultRow( array $row ): array
    {
        static $reverseColumns = [
            'content_type_id'                  => 'contentclass_id',
            'content_type_field_definition_id' => 'contentclassattribute_id',
            'content_type_status'              => 'contentclass_version',
            'status'                           => 'version',
        ];

        foreach ( $reverseColumns as $newKey => $oldKey )
        {
            if ( array_key_exists( $newKey, $row ) && !array_key_exists( $oldKey, $row ) )
            {
                $row[ $oldKey ] = $row[ $newKey ];
            }
        }

        // Reverse-map data_type_string: ibexa_string → ezstring etc.
        if ( isset( $row['data_type_string'] ) )
        {
            if ( self::$datatypeReverseMap === null )
            {
                self::$datatypeReverseMap = array_flip( self::$datatypeMap );
            }
            $ibexa = $row['data_type_string'];
            if ( isset( self::$datatypeReverseMap[ $ibexa ] ) )
            {
                $row['data_type_string'] = self::$datatypeReverseMap[ $ibexa ];
            }
        }

        return $row;
    }
}
