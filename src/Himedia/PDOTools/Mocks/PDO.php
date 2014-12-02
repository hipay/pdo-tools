<?php

namespace Himedia\PDOTools\Mocks;

/**
 * Mock for PDO to avoid "PDOException: You cannot serialize or unserialize PDO instances".
 */
class PDO extends \PDO
{
    /**
     * Empty constructor…
     */
    public function __construct()
    {
    }
}
