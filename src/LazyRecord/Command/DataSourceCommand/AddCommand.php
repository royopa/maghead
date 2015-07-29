<?php
namespace LazyRecord\Command\DataSourceCommand;
use CLIFramework\Command;
use LazyRecord\Command\BaseCommand;
use LazyRecord\ConfigLoader;
use LazyRecord\DSN\DSNParser;
use SQLBuilder\Driver\PDODriverFactory;
use SQLBuilder\ArgumentArray;
use SQLBuilder\Universal\Query\CreateDatabaseQuery;
use Exception;
use PDO;

class AddCommand extends BaseCommand
{
    public function brief()
    {
        return 'Add data source to config file.';
    }

    public function options($opts)
    {
        parent::options($opts);
        $opts->add('host:', 'host for database');
        $opts->add('port:', 'port for database');
        $opts->add('user:', 'user id for database connection');
        $opts->add('password:', 'password for database connection');
    }

    public function arguments($args)
    {
        $args->add('data-source-id');
        $args->add('dsn');
    }

    public function execute($dataSourceId, $dsnStr)
    {
        // force loading data source
        $configLoader = $this->getConfigLoader(true);

        // The data source array to be added to the config array
        $dataSource = array();

        $dsnParser = new DSNParser;
        $dsn = $dsnParser->parse($dsnStr);

        if ($host = $this->options->host) {
            $dsn->setAttribute('host', $host);
        }
        if ($port = $this->options->port) {
            $dsn->setAttribute('port', $port);
        }
        $dataSource['dsn'] = $dsn->__toString();

        if ($user = $this->options->user) {
            $dataSource['user'] = $user;
        }
        if ($password = $this->options->password) {
            $dataSource['pass'] = $password;
        }

        $config = $configLoader->getConfigStash();
        $config['data_source']['nodes'][$dataSourceId] = $dataSource;

        $configLoader->setConfigStash($config);
        $configLoader->writeToSymbol();
        return true;
    }
}


