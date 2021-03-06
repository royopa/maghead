<?php

namespace Maghead;

use ConfigKit\ConfigCompiler;
use Exception;
use PDO;
use Maghead\DSN\DSN;
use Symfony\Component\Yaml\Yaml;
use Maghead\Schema\SchemaFinder;

/**
 * ConfigLoader provides methods for loading the config file.
 *
 * We made ConfigLoader with instance methods because we have to save the
 * latest loaded config object for DeclareSchema to check some configuration
 * values.
 *
 * You may use ConfigLoader::getInstance() to get the singleton instance.
 */
class ConfigLoader
{
    const ANCHOR_FILENAME = '.lazy.yml';

    public static $inlineLevel = 4;

    public static $indentSpaces = 2;

    public static $currentConfig;

    public static function writeToSymbol(Config $config, $targetFile = null)
    {
        if (!$targetFile) {
            if (!file_exists(self::ANCHOR_FILENAME)) {
                throw new Exception('symbol link '.self::ANCHOR_FILENAME.' does not exist.');
            }
            $targetFile = readlink(self::ANCHOR_FILENAME);
        }
        if (!$targetFile || !file_exists($targetFile)) {
            throw new Exception('Missing target config file. incorrect symbol link.');
        }

        $yaml = Yaml::dump($config->stash, self::$inlineLevel, self::$indentSpaces);
        if (false === file_put_contents($targetFile, "---\n".$yaml)) {
            throw new Exception("YAML config update failed: $targetFile");
        }

        return true;
    }


    /**
     * This is used when running command line application
     */
    public static function loadFromSymbol($force = false)
    {
        if (file_exists(self::ANCHOR_FILENAME)) {
            return self::loadFromFile(realpath(self::ANCHOR_FILENAME), $force);
        }
    }

    /**
     * Load config from array directly.
     *
     * @param array $config
     */
    public static function loadFromArray(array $config)
    {
        return self::$currentConfig = new Config(self::preprocessConfig($config));
    }

    /**
     * Load config from the YAML config file...
     *
     * @param string $file
     */
    public static function loadFromFile($sourceFile, $force = false)
    {
        return self::$currentConfig = new Config(self::compile($sourceFile, $force));
    }

    public static function getCurrentConfig()
    {
        return self::$currentConfig;
    }

    public static function compile($sourceFile, $force = false)
    {
        $compiledFile = ConfigCompiler::compiled_filename($sourceFile);
        if ($force || ConfigCompiler::test($sourceFile, $compiledFile)) {
            $config = ConfigCompiler::parse($sourceFile);
            $config = self::preprocessConfig($config);
            ConfigCompiler::write($compiledFile, $config);
            return $config;
        } else {
            return require $compiledFile;
        }
    }

    /**
     * Convert data source config to DSN object.
     *
     * @param array data source config
     *
     * @return Maghead\DSN\DSN
     */
    public static function buildDSNObject(array $config)
    {
        // Build DSN connection string for PDO
        $dsn = new DSN($config['driver']);
        foreach (array('database', 'dbname') as $key) {
            if (isset($config[$key])) {
                $dsn->setAttribute('dbname', $config[$key]);
                break;
            }
        }
        if (isset($config['host'])) {
            $dsn->setAttribute('host', $config['host']);
        }
        if (isset($config['port'])) {
            $dsn->setAttribute('port', $config['port']);
        }

        return $dsn;
    }

    public static function preprocessConfig(array $config)
    {
        if (isset($config['data_source']['nodes'])) {
            $config['data_source']['nodes'] = self::preprocessDataSourceConfig($config['data_source']['nodes']);
        }

        return $config;
    }

    /**
     * This method is used for compiling config array.
     *
     * @param array PHP array from yaml config file
     */
    public static function preprocessDataSourceConfig(array $dbconfig)
    {
        foreach ($dbconfig as &$config) {
            if (!isset($config['driver'])) {
                list($driverType) = explode(':', $config['dsn'], 2);
                $config['driver'] = $driverType;
            }

            // compatible keys for username and password
            if (isset($config['username']) && $config['username']) {
                $config['user'] = $config['username'];
            }

            if (isset($config['password']) && $config['password']) {
                $config['pass'] = $config['password'];
            }
            if (!isset($config['user'])) {
                $config['user'] = null;
            }
            if (!isset($config['pass'])) {
                $config['pass'] = null;
            }

            // build dsn string for PDO
            if (!isset($config['dsn'])) {
                // Build DSN connection string for PDO
                $dsn = self::buildDSNObject($config);
                $config['dsn'] = $dsn->__toString();
            }

            if (!isset($config['query_options'])) {
                $config['query_options'] = array();
            }

            if (!isset($config['connection_options'])) {
                $config['connection_options'] = array();
            }

            if ('mysql' === $config['driver']) {
                $config['connection_options'][ PDO::MYSQL_ATTR_INIT_COMMAND ] = 'SET NAMES utf8';
            }
        }

        return $dbconfig;
    }


    public static function getInstance()
    {
        static $instance;
        if ($instance) {
            return $instance;
        }
        return $instance = new self();
    }
}
