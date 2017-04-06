<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 04/11/16
 * Time: 16:34
 */
namespace Keboola\DbWriter\Writer;

use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriter\Logger;
use Symfony\Component\Process\Process;

/**
 * Class BCP
 * @package Keboola\DbWriter\Writer
 *
 * Wrapper for Bulk Copy `bcp` command line utility
 */
class BCP
{
    private $conn;

    private $dbParams;

    /** @var Logger */
    private $logger;

    private $delimiter = '<~|~>';

    public function __construct(\PDO $conn, $dbParams, $logger)
    {
        $this->conn = $conn;
        $this->dbParams = $dbParams;
        $this->logger = $logger;
    }

    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
    }

    public function import($filename, $table)
    {
        $formatFile = $this->createFormatFile($table);
        $process = new Process($this->createBcpCommand($filename, $table, $formatFile));
        $process->setTimeout(3600*2);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new UserException(sprintf(
                "Import process failed. Output: %s. Error Output: %s",
                $process->getOutput(),
                $process->getErrorOutput()
            ));
        }

        @unlink($formatFile);
    }

    private function createBcpCommand($filename, $table, $formatFile)
    {
        return sprintf(
            'bcp %s in %s -t, -f %s -S "%s" -U %s -P "%s" -d %s -k -F 2',
            $table['dbName'],
            $filename,
            $formatFile,
            $this->dbParams['host'] . "," . $this->dbParams['port'],
            $this->dbParams['user'],
            $this->dbParams['#password'],
            $this->dbParams['database']
        );
    }

    private function createFormatFile($table)
    {
        $collation = $this->getCollation();
        $driverVersion = "{$this->getVersion()}";
        $columnsCount = count($table['items']) + 1;
        $prefixLength = 0;
        $sourceType = "SQLCHAR";

        $delimiter = '"\""';

        $formatData = $driverVersion . PHP_EOL;
        $formatData .= $columnsCount . PHP_EOL;

        // dummy column for the quote hack
        $formatData .= "1       {$sourceType}     {$prefixLength}       0       {$delimiter}       0       dummy       {$collation}" . PHP_EOL;

        $cnt = 1;
        foreach ($table['items'] as $column) {
            $cnt++;
            $dstCnt = $cnt - 1;

            $length = '255';
            if (strstr(strtolower($column['type']), 'char') !== false && !empty($column['size'])) {
                $length = $column['size'] * 2;
            }

            $delimiter = '"\"' . $this->delimiter . '\""';

            if ($cnt >= $columnsCount) {
                $delimiter = '"\"\n"';
            }

            $formatData .= "{$cnt}      {$sourceType}     {$prefixLength}       {$length}       {$delimiter}       {$dstCnt}       {$column['dbName']}       {$collation}" . PHP_EOL;
        }

        $this->logger->info("Format file: " . PHP_EOL . $formatData);

        $filename = ROOT_PATH . '/' . uniqid("format_file_{$table['dbName']}_");
        file_put_contents($filename, $formatData);

        return $filename;
    }

    private function getVersion()
    {
        $stmt = $this->conn->query("SELECT CONVERT (varchar, SERVERPROPERTY('ProductMajorVersion'))");
        $res = $stmt->fetchAll();
        $version = $res[0][0];
        if (intval($version) > 13) {
            $version = 13;
        }
        if (empty($version)) {
            $version = 12;
        }
        return $version . '.0';
    }

    private function getCollation()
    {
        $stmt = $this->conn->query("SELECT CONVERT (varchar, SERVERPROPERTY('collation'))");
        $res = $stmt->fetchAll();
        return $res[0][0];
    }
}
