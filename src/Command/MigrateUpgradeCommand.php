<?php

namespace LazyRecord\Command;

use LazyRecord\Migration\MigrationRunner;


class MigrateUpgradeCommand extends MigrateBaseCommand
{
    public function brief()
    {
        return 'Run upgrade migration scripts.';
    }

    public function aliases()
    {
        return array('u', 'up');
    }

    public function execute()
    {
        if ($this->options->backup) {
            $connection = $this->getCurrentConnection();
            $driver = $this->getCurrentQueryDriver();
            if (!$driver instanceof PDOMySQLDriver) {
                $this->logger->error('backup is only supported for MySQL');

                return false;
            }
            $this->logger->info('Backing up database...');
            $backup = new MySQLBackup();
            if ($dbname = $backup->incrementalBackup($connection)) {
                $this->logger->info("Backup at $dbname");
            }
        }

        $dsId = $this->getCurrentDataSourceId();
        $runner = new MigrationRunner($this->logger, $dsId);
        $runner->load($this->options->{'script-dir'} ?: 'db/migrations');
        $this->logger->info('Running migration scripts to upgrade...');
        $runner->runUpgrade();
        $this->logger->info('Done.');
    }
}
