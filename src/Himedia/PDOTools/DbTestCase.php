<?php

namespace Himedia\PDOTools;

use GAubry\Helpers\Helpers;
use GAubry\Logger\MinimalLogger;
use Psr\Log\LogLevel;
use PDO;

/**
 * Base class to build databases to execute tests.
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

    /**
     * PDO driver name, e.g. 'pgsql' or 'mysql'.
     * @var string
     */
    protected $sPdoDriverName;

    /**
     * Test DB hostname.
     * @var string
     */
    protected $sDbHostname;

    /**
     * Test DB port.
     * @var int
     */
    protected $iDbPort;

    /**
     * Test DB name.
     * @var string
     */
    protected $sDbName;

    /**
     * Test DB username.
     * @var string
     */
    protected $sDbUser;

    /**
     * Test DB password.
     * @var string
     */
    protected $sDbPassword;

    /**
     * Default PDO options on new instantiation.
     * @var array
     */
    protected $aPdoOptions = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::ATTR_TIMEOUT            => 5
    );

    /**
     * Max number of test DBs to keep.
     * @var int
     */
    protected $iMaxDbToKeep;

    /**
     * Build a database for tests and drop too old ones.
     *
     * @param array $aDsn Data source name of DB to build.
     * Structure: array(
     *     'driver'   => 'pgsql|mysql|…',
     *     'hostname' => '…',
     *     'port'     => '…',
     *     'dbname'   => '…',
     *     'username' => '…',
     *     'password' => '…',
     * )
     * @param array  $aPdoOptions  Driver-specific options for PDO connection.
     * @param int    $iMaxDbToKeep Max number of test databases to keep.
     * @param string $sDbBuildFile SQL directives to build database (@see loadSqlBuildFile())
     *
     * @param string $sTCName      PHPUnit_Framework_TestCase's name.
     * @param array  $aTCData      PHPUnit_Framework_TestCase's data.
     * @param string $sTCDataName  PHPUnit_Framework_TestCase's dataName parameter.
     */
    public function __construct(
        array $aDsn,
        array $aPdoOptions,
        $sDbBuildFile,
        $iMaxDbToKeep  = 3,
        $sTCName       = null,
        array $aTCData = array(),
        $sTCDataName   = ''
    ) {
        parent::__construct($sTCName, $aTCData, $sTCDataName);

        $this->sPdoDriverName = $aDsn['driver'];
        $this->sDbHostname    = $aDsn['hostname'];
        $this->iDbPort        = (int) $aDsn['port'];
        $this->sDbName        = $aDsn['dbname'];
        $this->sDbUser        = $aDsn['username'];
        $this->sDbPassword    = $aDsn['password'];
        $this->aPdoOptions    = array_replace($this->aPdoOptions, $aPdoOptions);

        // 1 is min to not drop current database:
        $this->iMaxDbToKeep   = max(1, (int)$iMaxDbToKeep);
        $this->oLogger        = new MinimalLogger(LogLevel::DEBUG);

        // PHPUnit_Framework_TestCase are instantiated more than once…
        if (! in_array($this->sDbName, self::$aBuiltDbs)) {
            try {
                $this->loadSqlBuildFile($sDbBuildFile, $this->sDbUser, $this->sDbName);
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
     * Returns a new PDO instance.
     *
     * @param string $sDbUser
     * @param string $sDbName
     * @return PDO
     */
    private function getNewPdoInstance ($sDbUser, $sDbName)
    {
        $sDsn = "$this->sPdoDriverName:host=$this->sDbHostname;port=$this->iDbPort;"
              . "dbname=$sDbName;user=$sDbUser;password=";
        return new PDO($sDsn, null, null, $this->aPdoOptions);
    }

    /**
     * Load SQL directives from config/build file.
     *
     * Config/build file is a PHP file returning a list of array('db-user', 'db-name', 'SQL commands or SQL filename').
     * All filenames must match following regexp: /(\.sql|\.gz)$/i.
     * Available/injected variables: $sDbUser, $sDbName.
     *
     * Build file example here: /doc/db-build-file-example.php
     *
     * @param string $sDbBuildFile SQL directives
     * @param string $sDbUser      Optional username injected into $sInitDbFile
     * @param string $sDbName      Optional DB name injected into $sInitDbFile
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function loadSqlBuildFile (
        $sDbBuildFile,
        /** @noinspection PhpUnusedParameterInspection */
        $sDbUser = '',
        /** @noinspection PhpUnusedParameterInspection */
        $sDbName = ''
    ) {
        /** @noinspection PhpIncludeInspection */
        $aSQLDirectives = include($sDbBuildFile);
        $this->loadSqlBuildArray($aSQLDirectives);
    }

    /**
     * Executes specified SQL statement.
     *
     * @param string $sSql    One or more SQL statements
     * @param string $sDbUser
     * @param string $sDbName
     */
    private function execSql ($sSql, $sDbUser, $sDbName)
    {
        $this->getNewPdoInstance($sDbUser, $sDbName)
             ->exec($sSql);
    }

    /**
     * Load SQL dump content for file exceeding 1 Mio.
     *
     * @param string $sFilePath Path to raw or gzipped SQL file.
     * @param string $sDbUser
     * @param string $sDbName
     * @see loadSqlDumpFile()
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

    /**
     * Load SQL dump file, possibly gzipped (.gz).
     *
     * @param string $sFilePath Dump file
     * @param string $sDbUser By default the user specified in constructor.
     * @param string $sDbName By default the DB name specified in constructor.
     */
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

            // recursive call with uncompressed file:
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
     * Load SQL directives in specified array.
     *
     * SQL directives are a list of array('db-user', 'db-name', 'SQL commands or SQL filename').
     * All filenames must match following regexp: /(\.sql|\.gz)$/i.
     *
     * Example here: /doc/db-build-file-example.php
     *
     * @param array $aSQLDirectives SQL directives to build database
     * @see loadSqlBuildFile()
     */
    protected function loadSqlBuildArray (array $aSQLDirectives)
    {
        foreach ($aSQLDirectives as $aStatement) {
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

    /**
     * List all test DBs created with following name pattern: '^{$sPrefixDb}_[0-9]+$'.
     *
     * @param string $sPrefixDb
     * @return array list of all test DBs
     */
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
     * @param  string $sQuery
     * @param  array  $aValues Values to bind to the SQL statement.
     * @return array  an associative array
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
            if (count($aAllDb) > $this->iMaxDbToKeep) {
                $aTooOldDbs = array_slice($aAllDb, 0, -$this->iMaxDbToKeep);
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

    /**
     * Asserts that SQL query doesn't return any rows.
     *
     * @param string $sQuery
     * @throws \PHPUnit_Framework_AssertionFailedError
     */
    protected function assertQueryReturnsNoRows ($sQuery)
    {
        $oPdoStatement = $this->oBuiltDbPdo->query($sQuery);
        $aRow = $oPdoStatement->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue($aRow === false || $aRow === null);
    }

    /**
     * Asserts that SQL query result is equal to CSV file content.
     *
     * @param string $sQuery
     * @param string $sCsvPath   CSV file whose first line contains headers
     * Sample content:
     *   id,name,description
     *   250,FR,France
     *   826,GB,United Kingdom
     *   840,US,United States
     * @param string $sDelimiter CSV field delimiter
     * @param string $sEnclosure CSV field enclosure
     * @throws \PHPUnit_Framework_AssertionFailedError
     */
    protected function assertQueryResultEqualsCsv ($sQuery, $sCsvPath, $sDelimiter = ',', $sEnclosure = '"')
    {
        $sResultCsv = $this->convertQuery2Csv($sQuery, $sDelimiter, $sEnclosure);
        $sExpectedCsv = trim(file_get_contents($sCsvPath));
        $this->assertSame($sExpectedCsv, $sResultCsv);
    }

    /**
     * Converts SQL query result to CSV string.
     * First CSV line contains headers.
     *
     * Returned CSV example:
     *   id,name,description
     *   250,FR,France
     *   826,GB,United Kingdom
     *   840,US,United States
     *
     * @param  string $sQuery SQL query, typically SELECT… FROM…
     * @param  string $sDelimiter CSV field delimiter
     * @param  string $sEnclosure CSV field enclosure
     * @return string
     */
    protected function convertQuery2Csv ($sQuery, $sDelimiter = ',', $sEnclosure = '"')
    {
        $aRows = $this->pdoFetchAll($sQuery);
        return Tools::exportToCSV($aRows, '', $sDelimiter, $sEnclosure);
    }

    /**
     * Returns a mocked \PDOStatement instance according to specified query,
     * whose fetch method returns either a user callback
     * or a callback consuming line per line a user specified CSV file.
     *
     * Allows to mock a PDO instance for several queries.
     * Each query should be a key of $aData array parameter. Values are callbacks or CSV filenames.
     *
     * All queries are internally normalized to simplify matching.
     *
     * In CSV files, following field's values are converted:
     *   '∅' ⇒ null
     *   't' ⇒ true
     *   'f' ⇒ false
     *
     * Example usage:
     * $oPDOMock->expects($this->any())->method('query')->will(
     *     $this->returnCallback(
     *         function ($sQuery) use ($that) {
     *             return $that->getPdoStmtMock(
     *                 $sQuery,
     *                 array(
     *                     'SELECT … FROM A' => '/path/to/csv',
     *                     'SELECT … FROM B' => function () {
     *                         static $i = 0;
     *                         return ++$i > 10 ? false : array('id' => $i, 'name' => md5(rand()));
     *                     }
     *                 )
     *             );
     *         }
     *     )
     * );
     *
     * @param string $sQuery SQL query behind \PDOStatement instance
     * @param array  $aData  Associative array describing for each PDO query which callback to execute
     * @return \PHPUnit_Framework_MockObject_MockObject|\PDOStatement
     * @see Tools::normalizeQuery()
     */
    public function getPdoStmtMock ($sQuery, array $aData)
    {
        // Normalize queries (keys of $aData):
        foreach ($aData as $sRawQuery => $mValue) {
            $sNormalizedQuery = Tools::normalizeQuery($sRawQuery);
            unset($aData[$sRawQuery]);
            $aData[$sNormalizedQuery] = $mValue;
        }
        $sNormalizedQuery = Tools::normalizeQuery($sQuery);

        if (! isset($aData[$sNormalizedQuery])) {
            throw new \RuntimeException("Query not handled: '$sNormalizedQuery'!");

        } elseif (is_callable($aData[$sNormalizedQuery])) {
            $callback = $aData[$sNormalizedQuery];

        } elseif (file_exists($aData[$sNormalizedQuery])) {
            $aCsv = array_filter(file($aData[$sNormalizedQuery]));
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
            $sMsg = "Value of key '$sNormalizedQuery' misformed: '{$aData[$sNormalizedQuery]}'.";
            $callback = function () use ($sMsg) {
                throw new \RuntimeException($sMsg);
            };
        }

        // Doesn't work if full chain pattern:
        $oPdoStmt = $this->getMock('\PDOStatement');
        $oPdoStmt
            ->expects($this->any())->method('fetch')
            ->will($this->returnCallback($callback));
        return $oPdoStmt;
    }
}
