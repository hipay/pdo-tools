<?php

namespace Himedia\PDOTools;

use GAubry\Helpers\Helpers;
use GAubry\Logger\MinimalLogger;
use Psr\Log\LogLevel;

/**
 * Base class for test classes requiring a temporary database.
 */
abstract class DbTestCase extends \PHPUnit_Framework_TestCase
{

    /**
     * List of instances indexed by DSN, to ensure we build DB only once time.
     * @var PDOAdapter[]
     */
    private static $aPDOInstances = array();

    protected $sQueryLogPath = '';

    /**
     *
     * @var \Himedia\PDOTools\DBAdapterInterface
     */
    protected $oDB;

    protected $aDSN;

    protected $iMaxDBToKeep;

    /**
     * Constructs a test case with the given name.
     *
     * @param array $aDSN Database source name: array(
     * 		'DRIVER'   => (string) e.g. 'pdo_mysql' or 'pdo_pgsql',
     * 		'HOSTNAME' => (string),
     * 		'PORT'     => (int),
     *		'DB_NAME'  => (string),
     *		'USERNAME' => (string),
     *		'PASSWORD' => (string)
     * );
     * @param  string $sName
     * @param  array  $aData
     * @param  string $sDataName
     */
    public function __construct(
        array $aDSN,
        $sInitDBScript,
        $sQueryLogPath,
        $iMaxDBToKeep = 3,
        $sName = null,
        array $aData = array(),
        $sDataName = ''
    ) {
        parent::__construct($sName, $aData, $sDataName);
        $this->aDSN = $aDSN;

        // 1 is min to not drop current database:
        $this->iMaxDBToKeep = max(1, (int)$iMaxDBToKeep);
        $this->oLogger = new MinimalLogger(LogLevel::DEBUG);

        // pré-requis :
        // $ psql -U postgres template1
        //     CREATE ROLE dw WITH LOGIN;
        //     ALTER ROLE dw SET client_min_messages TO WARNING;
        $this->sQueryLogPath = $sQueryLogPath;
        $sKey = implode('|', $this->aDSN);
        if (! isset(self::$aPDOInstances[$sKey])) {
            $this->oDB = PDOAdapter::getInstance($this->aDSN, $this->sQueryLogPath);
            try {
                $this->loadSQLFromFile($sInitDBScript);
                $this->dropOldDb();
            } catch (\RuntimeException $oException) {
                var_dump($oException);
                $this->fail('Test DB\'s build failed! ' . $oException->getMessage());
            }
            self::$aPDOInstances[$sKey] = $this->oDB;
        }
        $this->oDB = self::$aPDOInstances[$sKey];
        $this->oDB->setQueryLogPath($this->sQueryLogPath);
    }

    /**
     * Destructor.
     */
    public function __destruct ()
    {
        if (! empty($this->sQueryLogPath) && file_exists($this->sQueryLogPath)) {
            unlink($this->sQueryLogPath);
        }
    }

    protected function loadRawSQLFromFile ($sFilePath)
    {
        $aSQLToProcess = array(
            array(
                $this->aDSN['USERNAME'],
                $this->aDSN['DB_NAME'],
                $sFilePath
            ),
        );
        $this->loadSQLFromArray($aSQLToProcess);
    }

    /**
     * Build a temporary DB to execute tests.
     *
     * @param string $sInitDbFile SQL commands to initialize test DB
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @throws \RuntimeException if error when loading SQL file
     */
    protected function loadSQLFromFile ($sInitDbFile)
    {
        /** @var $sDbName string DB name injected into DB initialization file via below include(). */
        /** @noinspection PhpUnusedLocalVariableInspection */
        $sDbName = (isset($this->aDSN['DB_NAME']) ? $this->aDSN['DB_NAME'] : '');

        /** @var $sDbUser string Username injected into DB initialization file via below include(). */
        /** @noinspection PhpUnusedLocalVariableInspection */
        $sDbUser = (isset($this->aDSN['USERNAME']) ? $this->aDSN['USERNAME'] : '');

        /** @noinspection PhpIncludeInspection */
        $aSQLToProcess = include($sInitDbFile);
        $this->loadSQLFromArray($aSQLToProcess);
    }

    private function loadSQLCommands ($sDbUser, $sDbName, $sCommands)
    {
        if ($this->aDSN['DRIVER'] == 'pdo_pgsql') {
            $sHostname = $this->aDSN['HOSTNAME'];
            $iPort = (int) $this->aDSN['PORT'];
            $sPSQL = "psql -v ON_ERROR_STOP=1 -h $sHostname -p $iPort";

            if (preg_match('/^\/[a-z0-9_.\/ -]+\.sql(\.gz)?$/i', $sCommands) === 1) {
                if (preg_match('/^\/[a-z0-9_.\/ -]+\.sql$/i', $sCommands) === 1 && filesize($sCommands) < 1024*1024) {
                    $sQueries = file_get_contents($sCommands);
                    $this->oDB->exec($sQueries);
                } else {
                    $sTmpFilename = tempnam(sys_get_temp_dir(), 'dw-db-builder_');
                    $sCmd = "zcat -f '$sCommands' > '$sTmpFilename'"
                        . " && $sPSQL -U $sDbUser $sDbName --file '$sTmpFilename'"
                        . " && rm -f '$sTmpFilename'";
                    $this->oLogger->debug('[DEBUG] shell# ' . trim($sCmd, " \t"));
                    try {
                        Helpers::exec($sCmd);
                    } catch (\RuntimeException $oException) {
                        if (file_exists($sTmpFilename)) {
                            unlink($sTmpFilename);
                        }
                        throw $oException;
                    }
                }
            } else {
                if (substr($sCommands, -1) != ';') {
                    $sCommands .= ';';
                }
                $sCmd = "$sPSQL -U $sDbUser $sDbName -c \"$sCommands\"";
                $this->oLogger->debug('[DEBUG] shell# ' . trim($sCmd, " \t"));
                Helpers::exec($sCmd);
            }
        } else {
            $sErrMsg = "Driver type '{$this->aDSN['DRIVER']}' not handled for loading SQL commands!";
            throw new \UnexpectedValueException($sErrMsg, 1);
        }
    }

    /**
     * Build a temporary DB to execute tests.
     *
     * @param array $aSQL SQL commands to initialize test DB
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @throws \RuntimeException if error when loading SQL file
     */
    protected function loadSQLFromArray (array $aSQL)
    {
        foreach ($aSQL as $aStatement) {
            list($sDbUser, $sDbName, $sCommands) = $aStatement;
            $this->loadSQLCommands($sDbUser, $sDbName, $sCommands);
        }
    }

    private function getAllDb ($sPrefixDb)
    {
        if ($this->aDSN['DRIVER'] == 'pdo_pgsql') {
            $sQuery = "
                    SELECT datname AS dbname FROM pg_database
                    WHERE datname ~ '^{$sPrefixDb}_[0-9]+$' ORDER BY datname ASC;";
        } else {
            $sErrMsg = "Driver type '{$this->aDSN['DRIVER']}' not handled for listing all databases!";
            throw new \UnexpectedValueException($sErrMsg, 1);
        }
        $aAllDb = $this->oDB->fetchAll($sQuery);
        return $aAllDb;
    }

    /**
     * Drop old test DBs.
     */
    private function dropOldDb ()
    {
        $sDbName = $this->aDSN['DB_NAME'];
        if (preg_match('/^(.*)_[0-9]+$/i', $sDbName, $aMatches) === 1) {
            $aAllDb = $this->getAllDb($aMatches[1]);
            if (count($aAllDb) > $this->iMaxDBToKeep) {
                $aOldDb = array_slice($aAllDb, 0, -$this->iMaxDBToKeep);
                foreach ($aOldDb as $aDb) {
                    $sOldDb = $aDb['dbname'];
                    $sQuery = "DROP DATABASE IF EXISTS $sOldDb;";
                    $this->oLogger->debug("[DEBUG] SQL# $sQuery");
                    $this->oDB->exec($sQuery);
                }
            }
        }
    }

    protected function assertQueryReturnsNothing ($sQuery)
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
        $aRows = $this->oDB->fetchAll($sQuery);
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
