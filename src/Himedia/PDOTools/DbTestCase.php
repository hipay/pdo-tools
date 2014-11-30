<?php

namespace Himedia\PDOTools;

use GAubry\Helpers\Helpers;
use GAubry\Logger\MinimalLogger;
use Psr\Log\LogLevel;
use PDO;

/**
 * Base class to build a temporary DB to execute tests.
 */
abstract class DbTestCase extends \PHPUnit_Framework_TestCase
{

    /**
     * List of DB's name already built (PHPUnit_Framework_TestCase are instantiated more than once).
     * @var array
     */
    private static $aBuiltDbs = array();

    /**
     * Backend PDO instance of built DB.
     * @var PDO
     */
    protected $oBuiltDbPdo;

    protected $sPdoDriverName;

    protected $sDbHostname;
    protected $iDbPort;

    protected $iMaxDBToKeep;

    protected $aPdoOptions = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::ATTR_TIMEOUT            => 5
    );

    /**
     * pgsql:host=localhost;dbname=template1
     * mysql:host=localhost
     *
     * @param string $sInitDBScript
     * @param array $aDsn array(
     *     'driver' => 'pgsql', 'mysql', …
     *     'hostname' =>
     *     'port' =>
     *     'dbname' =>
     *     'username' =>
     *     'password' =>
     * )
     * @param array $aPdoOptions  Driver-specific options for PDO connection.
     * @param int $iMaxDBToKeep
     *
     * @param string $sName     PHPUnit_Framework_TestCase's name.
     * @param array  $aData     PHPUnit_Framework_TestCase's data.
     * @param string $sDataName PHPUnit_Framework_TestCase's dataName parameter.
     */
    public function __construct(
        $sInitDBScript,
        array $aDsn,
        array $aPdoOptions,
        $iMaxDBToKeep = 3,
        $sName = null,
        array $aData = array(),
        $sDataName = ''
    ) {
        parent::__construct($sName, $aData, $sDataName);

        $this->sPdoDriverName = $aDsn['driver'];
        $this->sDbHostname    = $aDsn['hostname'];
        $this->iDbPort        = (int) $aDsn['port'];
        $this->sDbName        = $aDsn['dbname'];
        $this->sDbUser        = $aDsn['username'];
        $this->sDbPassword    = $aDsn['password'];
        $this->aPdoOptions    = array_replace($this->aPdoOptions, $aPdoOptions);

        // 1 is min to not drop current database:
        $this->iMaxDBToKeep   = max(1, (int)$iMaxDBToKeep);
        $this->oLogger        = new MinimalLogger(LogLevel::DEBUG);

        // TODO prerequisite ?
        // $ psql -U postgres template1
        //     CREATE ROLE dw WITH LOGIN;
        //     ALTER ROLE dw SET client_min_messages TO WARNING;

        // PHPUnit_Framework_TestCase are instantiated more than once…
        if (! in_array($this->sDbName, self::$aBuiltDbs)) {
            try {
                $this->loadSqlFromConfigFile($sInitDBScript, $this->sDbUser, $this->sDbName);
            } catch (\RuntimeException $oException) {
                var_dump($oException);
                $this->fail('DB\'s building failed! ' . $oException->getMessage());
            }
        }

        $this->oBuiltDbPdo = $this->getNewPdoInstance($this->sDbUser, $this->sDbName);

        if (! in_array($this->sDbName, self::$aBuiltDbs)) {
            $this->dropOldDbs($this->sDbName);
            self::$aBuiltDbs[$this->sDbName] = true;
        }
    }

    /**
     * @param $sDbUser
     * @param $sDbName
     * @return PDO
     */
    private function getNewPdoInstance ($sDbUser, $sDbName)
    {
        $sDsn = "$this->sPdoDriverName:host=$this->sDbHostname;port=$this->iDbPort;"
            . "dbname=$sDbName;user=$sDbUser;password=";
        return new PDO($sDsn, null, null, $this->aPdoOptions);
    }

    /**
     * Build a temporary DB to execute tests.
     *
     * @param string $sInitDbFile SQL commands to initialize test DB
     * @param string $sDbUser
     * @param string $sDbName
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function loadSqlFromConfigFile (
        $sInitDbFile,
        /** @noinspection PhpUnusedParameterInspection */
        $sDbUser = '',
        /** @noinspection PhpUnusedParameterInspection */
        $sDbName = ''
    ) {
        /** @noinspection PhpIncludeInspection */
        $aSQLToProcess = include($sInitDbFile);
        $this->loadSqlFromConfigArray($aSQLToProcess);
    }

    /**
     * @param string $sSql One or more SQL statements
     * @param $sDbUser
     * @param $sDbName
     */
    private function execSql ($sSql, $sDbUser, $sDbName)
    {
        $this->getNewPdoInstance($sDbUser, $sDbName)
            ->exec($sSql);
    }

    /**
     * @param string $sFilename Path to raw or gzipped SQL file.
     * @param $sDbUser
     * @param $sDbName
     */
    private function loadBigSqlDumpFile ($sFilePath, $sDbUser, $sDbName)
    {
        if ($this->sPdoDriverName == 'pgsql') {
            $sCmd = "psql -v ON_ERROR_STOP=1 -h $this->sDbHostname -p $this->iDbPort -U $sDbUser $sDbName "
                . "--file '$sFilePath'";
            $this->oLogger->debug("[DEBUG] shell# $sCmd");
            Helpers::exec($sCmd);

        } else {
            $sErrMsg = "Driver type '$this->sPdoDriverName' not handled for loading SQL commands!";
            throw new \UnexpectedValueException($sErrMsg, 1);
        }
    }

    protected function loadSqlDumpFile ($sFilePath, $sDbUser = '', $sDbName = '')
    {
        $sDbUser = $sDbUser ?: $this->sDbUser;
        $sDbName = $sDbName ?: $this->sDbName;

        // If gzipped dump file:
        if (preg_match('/\.gz$/i', $sFilePath)) {
            $sTmpFilePath = tempnam(sys_get_temp_dir(), 'dw-db-builder_');
            $sCmd = "zcat -f '$sFilePath' > '$sTmpFilePath'";
            $this->oLogger->debug("[DEBUG] shell# $sCmd");
            try {
                Helpers::exec($sCmd);
            } catch (\RuntimeException $oException) {
                if (file_exists($sTmpFilePath)) {
                    unlink($sTmpFilePath);
                }
                throw $oException;
            }

            $this->loadSqlDumpFile($sTmpFilePath, $sDbUser, $sDbName);

            $sCmd = "rm -f '$sTmpFilePath'";
            $this->oLogger->debug("[DEBUG] shell# $sCmd");
            Helpers::exec($sCmd);

            // If <1 Mio uncompressed dump file:
        } elseif (filesize($sFilePath) < 1024*1024) {
            $sSql = file_get_contents($sFilePath);
            $this->execSql($sSql, $sDbUser, $sDbName);

            // If ≥1 Mio uncompressed dump file:
        } else {
            $this->loadBigSqlDumpFile($sFilePath, $sDbUser, $sDbName);
        }
    }

    /**
     *
     *
     * @param array $aSQL SQL commands to initialize test DB
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @throws \RuntimeException if error when loading SQL file
     */
    protected function loadSqlFromConfigArray (array $aSQL)
    {
        foreach ($aSQL as $aStatement) {
            list($sDbUser, $sDbName, $sCommands) = $aStatement;
            if (preg_match('/(\.sql|\.gz)$/i', $sCommands) === 1) {
                $this->loadSqlDumpFile($sCommands, $sDbUser, $sDbName);
            } else {
                if (substr($sCommands, -1) != ';') {
                    $sCommands .= ';';
                }
                $this->execSql($sCommands, $sDbUser, $sDbName);
            }
        }
    }

    private function getAllDb ($sPrefixDb)
    {
        if ($this->sPdoDriverName == 'pgsql') {
            $sQuery = "
                    SELECT datname AS dbname FROM pg_database
                    WHERE datname ~ '^{$sPrefixDb}_[0-9]+$' ORDER BY datname ASC";
        } else {
            $sErrMsg = "Driver type '$this->sPdoDriverName' not handled for listing all databases!";
            throw new \UnexpectedValueException($sErrMsg, 1);
        }
        $aAllDb = $this->pdoFetchAll($sQuery);
        return $aAllDb;
    }

    /**
     * Fetch all rows.
     *
     * @param string $sQuery
     * @param array $aValues Values to bind to the SQL statement.
     * @return array an associative array
     */
    protected function pdoFetchAll($sQuery, array $aValues = array())
    {
        $oPdoStatement = $this->oBuiltDbPdo->prepare($sQuery);
        $oPdoStatement->execute($aValues);
        return $oPdoStatement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Drop old test DBs.
     *
     * @param string $sCurrentDbName Current DB name, not to remove.
     */
    private function dropOldDbs ($sCurrentDbName)
    {
        if (preg_match('/^(.*)_[0-9]+$/i', $sCurrentDbName, $aMatches) === 1) {
            $this->oLogger->info('Searching too old databases…');
            $aAllDb = $this->getAllDb($aMatches[1]);
            if (count($aAllDb) > $this->iMaxDBToKeep) {
                $aTooOldDbs = array_slice($aAllDb, 0, -$this->iMaxDBToKeep);
                foreach ($aTooOldDbs as $aDb) {
                    $sTooOldDb = $aDb['dbname'];
                    $this->oLogger->info("    Dropping '$sTooOldDb' database…");
                    $this->oBuiltDbPdo->exec("DROP DATABASE IF EXISTS $sTooOldDb;");
                }
                $this->oLogger->info('    Done.');
            } else {
                $this->oLogger->info('    No DB to delete.');
            }
        }
    }

    protected function assertQueryReturnsNoRows ($sQuery)
    {
        $sResultCsv = $this->convertQuery2Csv($sQuery);
        $this->assertEmpty($sResultCsv);
    }

    protected function assertQueryEqualsCsv ($sQuery, $sCsvPath)
    {
        $sResultCsv = $this->convertQuery2Csv($sQuery);
        $sExpectedCsv = trim(file_get_contents($sCsvPath));
        $this->assertSame($sExpectedCsv, $sResultCsv);
    }

    protected function convertQuery2Csv ($sQuery)
    {
        $aRows = $this->pdoFetchAll($sQuery);
        return Tools::convertAssocRows2Csv($aRows);
    }

    /**
     * Exemple de callback :
     * $sQ1 => function () {
     *     static $i = 0;
     *     return ++$i > 200 ? false : array(
     *         'iso_a3' => $i,
     *         'name' => '',
     *         'iso_num' => 0,
     *         'is_expired' => false,
     *         'effective_date' => '2000-01-01 00:00:00+00',
     *         'expiration_date' => '2100-01-01 00:00:00+00'
     *     );
     * },
     *
     * @param $sQuery
     * @param array $aData
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    public function cbPdoQuery ($sQuery, array $aData)
    {
        // Normalize queries (keys of $aData):
        foreach ($aData as $sRawQuery => $mValue) {
            $sNormalizedQuery = Tools::normalizeQuery($sRawQuery);
            unset($aData[$sRawQuery]);
            $aData[$sNormalizedQuery] = $mValue;
        }
        $sNormalizedQuery = Tools::normalizeQuery($sQuery);

        $oPDOStatement = $this->getMock('\PDOStatement');
        if (! isset($aData[$sNormalizedQuery])) {
            throw new \RuntimeException("Query not handled: '$sNormalizedQuery'!");

        } elseif (is_callable($aData[$sNormalizedQuery])) {
            $callback = $aData[$sNormalizedQuery];

        } elseif (file_exists($aData[$sNormalizedQuery])) {
            $aCsv = file($aData[$sNormalizedQuery]);
            $aCsv = array_filter($aCsv);
            $callback = function () use ($aCsv) {
                static $idx = 0, $aHeaders, $iCount;
                if ($idx == 0) {
                    $aHeaders = str_getcsv($aCsv[0], ',', '"', "\\");
                    $iCount = count($aCsv);
                }
                if (++$idx < $iCount) {
                    $aRow = array_combine($aHeaders, str_getcsv($aCsv[$idx], ',', '"', "\\"));
                    foreach ($aRow as $sKey => $sValue) {
                        if ($sValue == '∅') {
                            $aRow[$sKey] = null;
                        } elseif ($sValue == 't') {
                            $aRow[$sKey] = true;
                        } elseif ($sValue == 'f') {
                            $aRow[$sKey] = false;
                        }
                    }
                    return $aRow;
                } else {
                    return false;
                }
            };

        } else {
            $sMsg = "Value of key '$sNormalizedQuery' of data misformed: '{$aData[$sNormalizedQuery]}'.";
            $callback = function () use ($sMsg) {
                throw new \RuntimeException($sMsg);
            };
        }
        $oPDOStatement
            ->expects($this->any())->method('fetch')
            ->will($this->returnCallback($callback));
        return $oPDOStatement;
    }
}
