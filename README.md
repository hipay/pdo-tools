# pdo-tools

pdo-tools simplifies the build of a database for testing purposes 
and allows you to easily verify the contents of a database after operations.

Specifically pdo-tools is a small PHP class extending `PHPUnit_Framework_TestCase` (see [PHPUnit](https://phpunit.de/))
allowing to dynamically build entirely new test databases, with:

  * no database, schema or privileges prerequisites,
  * keep the last `N` built databases (customizable, for monitoring and debugging),
  * easy fixtures, 
  * additional specific assertions,
  * powerful combination of `PDO`/`PDOStatement` mock objects and callbacks,
    _e.g._ to mock a third-party database,
  * ready-to-use with [Jenkins](http://jenkins-ci.org/) and local PHPUnit.
  
## Table of Contents

  * [Installation](#installation)
  * [Usage](#usage)
    * [Instantiation](#instantiation)
    * [Simple test](#simple-test)
    * [Mocking a third-party database](#mocking-a-third-party-database)
  * [Documentation](#documentation)
  * [Change log](#change-log)
  * [Contributions](#contributions)
  * [Versioning & Git branching model](#versioning--git-branching-model)
  * [Copyrights & licensing](#copyrights--licensing)

<a name="installation"></a>
## Installation

1. Class autoloading and dependencies are managed by [Composer](http://getcomposer.org/)
    so install it following the instructions
    on [Composer: Installation - *nix](http://getcomposer.org/doc/00-intro.md#installation-nix)
    or just run the following command:

    ```bash
    $ curl -sS https://getcomposer.org/installer | php
    ```

2. Add dependency to `Himedia\PDOTools` into require section of your `composer.json`:

    ```json
    {
        "require": {
            "hi-media/pdo-tools": "1.*"
        }
    }
    ```

    and run `php composer.phar install` from the terminal into the root folder of your project.

3. Include Composer's autoloader:

    ```php
    <?php

    require_once 'vendor/autoload.php';
    …
    ```

<a name="usage"></a>
## Usage

### Instantiation

Example:

```php
class MyTestCase extends DbTestCase
{
    public function __construct($sName = null, array $aData = array(), $sDataName = '')
    {
        // BUILD_NUMBER environment variable is handled by Jenkins:
        $iSuffix = isset($_SERVER['BUILD_NUMBER']) ? $_SERVER['BUILD_NUMBER'] : floor(microtime(true));
        $sTestDbName = "tests_$iSuffix";
        $aDbBuilderDsn = array(
            'driver'   => 'pgsql',
            'hostname' => 'localhost',
            'port'     => 5432,
            'dbname'   => $sTestDbName,
            'username' => 'user',
            'password' => ''
        );
        
        $sDbBuildFile = '/path/to/buildfile.php';
        parent::__construct($aDbBuilderDsn, array(), $sDbBuildFile);
    }
}
```

where `/path/to/buildfile.php` ([example here](doc/db-build-file-example.php))
is a build file describing how to create a fresh database, roles/users and schemas, 
with listing of fixtures to load

### Simple test

Example including:

  * fixture loading,
  * comparison between result of executed SQL query, converted into CSV, and a CSV file,
  * clean up

```php
public function testSimple ()
{
    // Load SQL dump file, possibly gzipped (.gz):
    $this->loadSqlDumpFile('/path/to/dump.sql');

    // calls to tested program…

    // Asserts that SQL query result is equal to CSV file content:
    $this->assertQueryResultEqualsCsv('SELECT … FROM A', '/path/to/expected.csv');
    
    // Asserts that SQL query doesn't return any rows:
    $this->assertQueryReturnsNoRows('SELECT * FROM B');
    
    // Optional clean up:
    $this->loadSqlDumpFile('/path/to/clean_up.sql');
}
```

where `/path/to/fixture.sql` is a typical SQL dump file, gzipped or not.

Note that in CSV files, following field's values are converted:

  * `'∅'` ⇒ `null`
  * `'t'` ⇒ `true`
  * `'f'` ⇒ `false`

### Mocking a third-party database

In this example, successives calls to `PDOStatement::fetch()` method on the third-party database…

  * will return content of `/path/to/fixture.csv` file like calls to `PDOStatement::fetch(PDO::FETCH_ASSOC)`
    if initial statement of `PDO::query()` was `'SELECT … FROM A'`,
    and `false` if no more lines in specified CSV,
  * will return results of successives calls to the user callback
    if initial statement of `PDO::query()` was `'SELECT … FROM B'`

All queries are internally normalized to simplify matching.

```php
public function testWithMockDb ()
{
    /* @var $oMockPDO \Himedia\DW\Tests\Mocks\PDO|\PHPUnit_Framework_MockObject_MockObject */
    $oMockPDO = $this->getMock('Himedia\PDOTools\Mocks\PDO', array('query'));
    $that = $this;
    $oMockPDO->expects($this->any())->method('query')->will(
        $this->returnCallback(
            function ($sQuery) use ($that, $sResourcePath) {
                return $that->getPdoStmtMock(
                    $sQuery,
                    array(
                        'SELECT … FROM A' => '/path/to/fixture.csv',
                        'SELECT … FROM B' => function () {
                            static $i = 0;
                            return ++$i > 10 ? false : array('id' => $i, 'name' => md5(rand()));
                        }
                    )
                );
            }
        )
    );
    
    // injection of $oMockPDO, to mock third-party database…
    
    // calls to tested program…
    
    // assertions…
}
```

<a name="documentation"></a>
## Documentation

[API documentation](http://htmlpreview.github.io/?https://github.com/Hi-Media/pdo-tools/blob/stable/doc/api/index.html) 
is generated by [ApiGen](http://apigen.org/) in the `doc/api` folder.

```bash
$ vendor/bin/apigen -c apigen.neon
```

<a name="change-log"></a>
## Change log
See [CHANGELOG](CHANGELOG.md) file for details.

<a name="contributions"></a>
## Contributions
All suggestions for improvement or direct contributions are very welcome.
Please report bugs or ask questions using the [Issue Tracker](https://github.com/Hi-Media/pdo-tools/issues).

<a name="versioning--git-branching-model"></a>
## Versioning & Git branching model

For transparency into our release cycle and in striving to maintain backward compatibility,
Padocc's engine is maintained under [the Semantic Versioning guidelines](http://semver.org/).
We'll adhere to these rules whenever possible.

The git branching model used for development is the one described and assisted by `twgit` tool: [https://github.com/Twenga/twgit](https://github.com/Twenga/twgit).

<a name="copyrights--licensing"></a>
## Copyrights & licensing
Licensed under the GNU Lesser General Public License v3 (LGPL version 3).
See [LICENSE](LICENSE) file for details.
