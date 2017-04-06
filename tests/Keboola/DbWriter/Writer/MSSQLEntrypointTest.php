<?php
namespace Keboola\DbWriter\Writer;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Test\BaseTest;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class MSSQLEntrypointTest extends BaseTest
{
    const DRIVER = 'mssql';

    private $tmpDataPath = '/tmp/wr-db-mssql/data';

    public function setUp()
    {
        $config= $this->getConfig(self::DRIVER);
        $config['parameters']['writer_class'] = 'MSSQL';

        // create test database
        $dbParams = $config['parameters']['db'];
        $dsn = sprintf("dblib:host=%s;charset=UTF-8", $dbParams['host']);
        $conn = new \PDO($dsn, $dbParams['user'], $dbParams['#password']);
        $conn->exec("USE master");
        $conn->exec(sprintf("
            IF EXISTS(select * from sys.databases where name='%s') 
            DROP DATABASE %s
        ", $dbParams['database'], $dbParams['database']));
        $conn->exec(sprintf("CREATE DATABASE %s COLLATE CZECH_CI_AS", $dbParams['database']));
        $conn->exec(sprintf("USE %s", $dbParams['database']));

        $this->cleanup($config);
    }

    private function cleanup($config)
    {
        $writer = $this->getWriter($config['parameters']);
        $tables = $config['parameters']['tables'];
        $conn = $writer->getConnection();
        foreach ($tables as $table) {
            $conn->exec(sprintf("IF OBJECT_ID('%s', 'U') IS NOT NULL DROP TABLE %s", $table['dbName'], $table['dbName']));
        }
    }

    public function testRunAction()
    {
        // cleanup
        $config = Yaml::parse(file_get_contents(ROOT_PATH . 'tests/data/runBCP/config.yml'));
        $config['parameters']['writer_class'] = 'MSSQL';
        $this->cleanup($config);

        // run entrypoint
        $process = new Process('php ' . ROOT_PATH . 'run.php --data=' . ROOT_PATH . 'tests/data/runBCP 2>&1');
        $process->setTimeout(300);
        $process->run();

        $this->assertEquals(0, $process->getExitCode(), $process->getOutput());

        $expectedFilename = ROOT_PATH . 'tests/data/runBCP/in/tables/simple.csv';
        $resFilename = $this->writeCsvFromDB($config, 'simple');
        $this->assertFileEquals($expectedFilename, $resFilename);

        $expectedFilename = ROOT_PATH . 'tests/data/runBCP/in/tables/special.csv';
        $resFilename = $this->writeCsvFromDB($config, 'special');
        $this->assertFileEquals($expectedFilename, $resFilename);
    }

    public function testRunActionIncremental()
    {
        $config = Yaml::parse(file_get_contents(ROOT_PATH . 'tests/data/runActionIncremental/config_default.yml'));
        $config['parameters']['writer_class'] = 'MSSQL';
        $tables = $config['parameters']['tables'];
        $table = $tables[0];
        $table['items'] = array_reverse($table['items']);
        $tables[0] = $table;
        $config['parameters']['tables'] = $tables;

        $this->cleanup($config);
        $this->initInputFiles('runActionIncremental', $config);

        // run entrypoint
        $process = new Process('php ' . ROOT_PATH . 'run.php --data=' . $this->tmpDataPath . '/runActionIncremental 2>&1');
        $process->mustRun();

        $writer = $this->getWriter($config['parameters']);
        $stmt = $writer->getConnection()->query("SELECT * FROM simple");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["id", "name", "glasses"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $expectedFilename = ROOT_PATH . 'tests/data/runActionIncremental/simple_merged.csv';

        $this->assertFileEquals($expectedFilename, $resFilename);
        $this->assertEquals(0, $process->getExitCode());
    }

    public function testConnectionAction()
    {
        $process = new Process('php ' . ROOT_PATH . 'run.php --data=' . ROOT_PATH . 'tests/data/testConnection 2>&1');
        $process->mustRun();

        $this->assertEquals(0, $process->getExitCode());
        $data = json_decode($process->getOutput(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('success', $data['status']);
    }

    private function initInputFiles($folderName, $config)
    {
        (new Process('rm -rf ' . $this->tmpDataPath . '/' . $folderName))->mustRun();
        mkdir($this->tmpDataPath . '/' . $folderName . '/in/tables', 0777, true);
        file_put_contents($this->tmpDataPath . '/' . $folderName . '/config.yml', Yaml::dump($config));

        foreach ($config['parameters']['tables'] as $table) {
            copy(
                ROOT_PATH . 'tests/data/' . $folderName . '/in/tables/' . $table['tableId'] . '.csv',
                $this->tmpDataPath . '/' . $folderName . '/in/tables/' . $table['tableId'] . '.csv'
            );
        }
    }

    private function writeCsvFromDB($config, $tableId)
    {
        $writer = $this->getWriter($config['parameters']);
        $tableArr = array_filter($config['parameters']['tables'], function ($item) use ($tableId) {
            return $item['tableId'] == $tableId;
        });
        $table = array_shift($tableArr);

        $stmt = $writer->getConnection()->query(sprintf("SELECT * FROM [%s]", $table['dbName']));
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(array_map(function ($item) {
            return $item['dbName'];
        }, $table['items']));
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        return $resFilename;
    }
}
