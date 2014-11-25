<?php

namespace Himedia\PDOTools;

use GAubry\Helpers\Helpers;

class Tools
{
    public static function assocArrayToHstore (array $aData)
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

    public static function exportToCSV (DBAdapterInterface $oDB, $sQuery, $sPath, $sDelimiter, $sEnclosure)
    {
        $aRows = $oDB->fetchAll($sQuery);
        if (empty($sPath)) {
            $hFile = fopen('php://temp/maxmemory:' . (10*1024*1024), 'w');
        } else {
            $hFile = fopen($sPath, 'w');
        }

        $bIsHeaderPrinted = false;
        foreach ($aRows as $aRow) {
            if (! $bIsHeaderPrinted) {
                $bIsHeaderPrinted = true;
                fputcsv($hFile, array_keys($aRow), $sDelimiter, $sEnclosure);
            }
            fputcsv($hFile, array_values($aRow), $sDelimiter, $sEnclosure);
        }

        if (empty($sPath)) {
            rewind($hFile);
            $sCSV = stream_get_contents($hFile);
        } else {
            $sCSV = '';
        }
        fclose($hFile);
        return $sCSV;
    }

    public static function convertAssocRows2Csv (array $aRows)
    {
        $aCsv = array();
        foreach ($aRows as $idx => $aRow) {
            if ($idx == 0) {
                $aCsv[] = Helpers::strPutCSV(array_keys($aRow), ',', '"');
            }
            $aCsv[] = Helpers::strPutCSV($aRow, ',', '"');
        }
        return implode("\n", $aCsv);
    }

    // See http://stackoverflow.com/a/13823184:
    public static function normalizeQuery ($sRawQuery)
    {
        $sSqlComments = '@(([\'"]).*?[^\\\]\2)|((?:\#|--).*?$|/\*(?:[^/*]|/(?!\*)|\*(?!/)|(?R))*\*\/)\s*|(?<=;)\s+@ms';
        $sNormalizedQuery = preg_replace('/\s+/', ' ', trim(preg_replace($sSqlComments, '$1', $sRawQuery)));
        return $sNormalizedQuery;
    }
}
