<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\FetchMode;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Production\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemInstallCommand extends Command
{
    static public $defaultName = 'system:install';

    /**
     * @var string
     */
    private $projectDir;

    /**
     * @var SymfonyStyle
     */
    protected $io;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('create-database', null, InputOption::VALUE_NONE, "Create database if it doesn't exist.")
            ->addOption('drop-database', null, InputOption::VALUE_NONE, 'Drop existing database')
            ->addOption('basic-setup', null, InputOption::VALUE_NONE, 'Create storefront sales channel and admin user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);

        $dsn = trim((string)($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL')));
        if ($dsn === '' || $dsn === Kernel::PLACEHOLDER_DATABASE_URL)  {
            $output->error("Environment variable 'DATABASE_URL' not defined.");
            return 1;
        }

        $params = parse_url($dsn);
        $dbName = substr($params['path'], 1);

        $dsnWithoutDb = sprintf(
            '%s://%s%s:%s',
            $params['scheme'],
            isset($params['pass'], $params['user']) ? ($params['user'] . ':' . $params['pass'] . '@') : '',
            $params['host'],
            $params['port'] ?? 3306
        );

        $parameters = [
            'url' => $dsnWithoutDb,
            'charset' => 'utf8mb4',
        ];

        $connection = DriverManager::getConnection($parameters, new Configuration());

        $output->writeln('Prepare installation');
        $output->writeln('');

        $dropDatabase = $input->getOption('drop-database');
        if ($dropDatabase) {
            $connection->executeUpdate('DROP DATABASE IF EXISTS `' . $dbName . '`');
            $output->writeln('Drop database `' . $dbName . '`');
        }

        $createDatabase = $input->getOption('create-database') || $dropDatabase;
        if ($createDatabase) {
            $connection->executeUpdate('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_ci`');
            $output->writeln('Created database `' . $dbName . '`');
        }

        $connection->exec('USE `' . $dbName . '`');

        $tables = $connection->query('SHOW TABLES')->fetchAll(FetchMode::COLUMN);

        if (!in_array('migration', $tables, true)) {
            $output->writeln('Importing base schema.sql');
            $connection->exec($this->getBaseSchema());
        }

        $output->writeln('');

        $commands = [
            [
                'command' =>'database:migrate',
                'identifier' => 'Shopware\\',
                '--all'  => true,
            ],
            [
                'command' => 'database:migrate-destructive',
                'identifier' => 'Shopware\\',
                '--all'  => true,
            ],
            [
                'command' => 'dal:refresh:index'
            ],
            [
                'command' => 'theme:refresh'
            ],
            [
                'command' => 'theme:compile',
            ],
        ];

        if ($input->getOption('basic-setup')) {
            $commands[] = [
                'command' => 'user:create',
                'username' => 'admin',
                '--admin' => true,
                '--password' => 'admin',
            ];

            $commands[] = [
                'command' => 'sales-channel:create:storefront',
                '--name' => 'Storefront',
                '--url' => $_SERVER['APP_URL'] ?? 'http://localhost',
            ];

            $commands[] = [
                'command' => 'theme:change',
                '--all' => true,
                'theme-name' => 'Storefront'
            ];
        }

        $commands = array_merge($commands, [
                [
                    'command' => 'assets:install',
                    '--no-cleanup' => true,
                ],
        ]);

        $this->runCommands($commands, $output);

        return 0;
    }

    /**
     * @param array<string, array<string, string>> $commands
     * @return int
     */
    private function runCommands(array $commands, OutputInterface $output): int
    {
        foreach($commands as $parameters) {
            $output->writeln('');

            $command = $this->getApplication()->find($parameters['command']);
            unset($parameters['command']);
            $returnCode = $command->run(new ArrayInput($parameters, $command->getDefinition()), $output);
            if ($returnCode !== 0) {
                return $returnCode;
            }
        }

        return 0;
    }

    private function getBaseSchema(): string
    {
        $paths = [
            'vendor/shopware/core/schema.sql',
            'vendor/shopware/platform/src/Core/schema.sql'
        ];

        foreach ($paths as $path) {
            $path = rtrim($this->projectDir, '/') . '/' . $path;
            if (is_readable($path) && !is_dir($path)) {
                return file_get_contents($path);
            }
        }

        throw new \RuntimeException('schema.sql not found or readable in (' . implode(', ', $paths) . ')');
    }
}
