<?php

namespace Himedia\PDOTools;

/**
 * Some useful functions.
 *
 * Copyright (c) 2014 HiMedia
 * Licensed under the GNU Lesser General Public License v3 (LGPL version 3).
 *
 * @package Himedia\PDOTools
 * @copyright 2014 HiMedia
 * @license http://www.gnu.org/licenses/lgpl.html
 */
class Tools
{

    /**
     * Converts associative array to PostgreSQL hstore syntax.
     *
     * @param  array  $aData associative array
     * @return string hstore conversion of specified array
     * @see http://www.postgresql.org/docs/9.0/static/hstore.html
     */
    public static function assocArrayToHstore(array $aData)
    {
        $aHstore = array();
        foreach ($aData as $sKey => $sValue) {
            if (is_null($sValue)) {
                $aHstore[] = sprintf('"%s" => NULL', $sKey);
            } else {
                $aHstore[] = sprintf('"%s" => "%s"', $sKey, str_replace('"', "\\\"", $sValue));
            }
        }
        return sprintf("'%s'::hstore", implode(', ', $aHstore));
    }

    /**
     * Export associative result set to CSV file or string.
     *
     * @param  array  $aRows      Associative fetch all.
     * @param  string $sCsvPath   Path to export CSV. Set to '' to export into returning string.
     * @param  string $sDelimiter Field delimiter
     * @param  string $sEnclosure Field enclosure
     * @return string If no $sCsvPath, then CSV formatted string without the trailing newline, else empty string.
     */
    public static function exportToCSV(array $aRows, $sCsvPath, $sDelimiter = ',', $sEnclosure = '"')
    {
        if (empty($sCsvPath)) {
            $hFile = fopen('php://temp/maxmemory:' . (10*1024*1024), 'w');
        } else {
            $hFile = fopen($sCsvPath, 'w');
        }

        $bIsHeaderPrinted = false;
        foreach ($aRows as $aRow) {
            if (! $bIsHeaderPrinted) {
                $bIsHeaderPrinted = true;
                fputcsv($hFile, array_keys($aRow), $sDelimiter, $sEnclosure);
            }
            fputcsv($hFile, $aRow, $sDelimiter, $sEnclosure);
        }

        if (empty($sCsvPath)) {
            rewind($hFile);
            $sCSV = stream_get_contents($hFile);
        } else {
            $sCSV = '';
        }
        fclose($hFile);
        return rtrim($sCSV, "\n");
    }

    /**
     * Normalize specified query removing comments and multiple white spaces.
     *
     * @param $sRawQuery
     * @return string query normalized by removing comments and multiple white spaces.
     * @see http://stackoverflow.com/a/13823184
     */
    public static function normalizeQuery($sRawQuery)
    {
        $sSqlComments = '@(([\'"]).*?[^\\\]\2)|((?:\#|--).*?$|/\*(?:[^/*]|/(?!\*)|\*(?!/)|(?R))*\*\/)\s*|(?<=;)\s+@ms';
        $sNormalizedQuery = preg_replace('/\s+/', ' ', trim(preg_replace($sSqlComments, '$1', $sRawQuery)));
        return $sNormalizedQuery;
    }
}
