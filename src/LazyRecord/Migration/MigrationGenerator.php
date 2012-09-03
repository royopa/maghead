<?php
namespace LazyRecord\Migration;
use Exception;
use ReflectionClass;
use ReflectionObject;
use CLIFramework\Command;
use LazyRecord\Schema;
use LazyRecord\CodeGen\ClassTemplate;
use LazyRecord\CodeGen\MethodCall;
use LazyRecord\Schema\SchemaFinder;
use LazyRecord\ConfigLoader;
use LazyRecord\TableParser\TableParser;
use LazyRecord\Inflector;

class MigrationGenerator
{
    public $migrationDir;

    function __construct($migrationDir)
    {
        $this->migrationDir = $migrationDir;
        if( ! file_exists($this->migrationDir) ) {
            mkdir($this->migrationDir,0755,true);
        }
    }

    /**
     * Returns code template directory
     */
    protected function getTemplateDirs()
    {
        $refl = new ReflectionClass('LazyRecord\Schema\SchemaGenerator');
        $path = $refl->getFilename();
        return dirname($path) . DIRECTORY_SEPARATOR . 'Templates';
    }

    public function generateFilename($taskName, $time = null)
    {
        $date = date('Ymd');
        if(is_integer($time)) {
            $date = date('Ymd',$time);
        }
        elseif( is_string($time) ) {
            $date = $time;
        }
        $inflector = Inflector::getInstance();
        $name = $inflector->underscore($taskName);
        return sprintf('%s_%s.php', $date, $taskName);
    }

    public function createClassTemplate($taskName,$time = null) 
    {
        if(!$time) {
            $time = time();
        } elseif( is_string($time) ) {
            $time = strtotime($time);
        }
        $className = $taskName . '_' . $time;
        // $filename
        $template = new ClassTemplate($className,array(
            'template' => 'Class.php.twig',
            'template_dirs' => $this->getTemplateDirs(),
        ));
        $template->extendClass('LazyRecord\Migration\Migration');
        return $template;
    }

    public function generate($taskName,$time = null)
    {
        $template = $this->createClassTemplate($taskName,$time);
        $template->addMethod('public','upgrade',array(),'');
        $template->addMethod('public','downgrade',array(),'');
        $filename = $this->generateFilename($taskName,$time);
        $path = $this->migrationDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path , $template->render() );
        return array( $template->class->name,$path );
    }

    public function generateWithDiff($taskName,$dataSourceId,$schemas,$time = null)
    {
        $template = $this->createClassTemplate($taskName,$time);
        $upgradeMethod = $template->addMethod('public','upgrade',array(),'');
        $downgradeMethod = $template->addMethod('public','downgrade',array(),'');


        $connectionManager = \LazyRecord\ConnectionManager::getInstance();
        $connection  = $connectionManager->getConnection($dataSourceId);
        $driver      = $connectionManager->getQueryDriver($dataSourceId);


        $parser = TableParser::create( $driver, $connection );
        $tableSchemas = array();

        // database schemas
        $tables = $parser->getTables();
        foreach(  $tables as $table ) {
            $tableSchemas[ $table ] = $parser->getTableSchema( $table );
        }

        $comparator = new \LazyRecord\Schema\Comparator;

        // schema from runtime
        foreach( $schemas as $b ) {
            $t = $b->getTable();
            $foundTable = isset( $tableSchemas[ $t ] );
            if( $foundTable ) {
                $a = $tableSchemas[ $t ];
                $diffs = $comparator->compare( $a , $b );

                // generate alter table statement.
                foreach( $diffs as $diff ) {
                    $call = new MethodCall;
                    if( $diff->flag == '+' ) {
                        $call->method('addColumn');
                        $call->addArgument('"'. $t . '"'); // table

                        // filter out useless columns
                        $data = array();
                        foreach( $diff->column->toArray() as $key => $value ) {
                            if( is_object($value) )
                                continue;
                            if( is_array($value) )
                                continue;

                            if( in_array($key,array(
                                'type','primary','name','unique','default','notNull','null','autoIncrement',)
                            )) {
                                $data[ $key ] = $value;
                            }
                        }
                        $call->addArgument($data);
                    }
                    elseif( $diff->flag == '-' ) {
                        $call->method('dropColumn');
                        $call->addArgument($t); // table
                        $call->addArgument($diff->name);
                    }
                    elseif( $diff->flag == '=' ) {
                        echo "** column flag = is not supported yet.\n";
                    }
                    $upgradeMethod->code .= $call->render() . "\n";
                }

            } else {
                // generate create table statement.
                // use sqlbuilder to build schema sql
                $call = new MethodCall;
                $call->method('importSchema');
                $call->addArgument( 'new ' . $b ); // for dynamic schema declare, it casts to the model class name.
                $upgradeMethod->code .= $call->render() . "\n";
            }
        }

        $filename = $this->generateFilename($taskName,$time);
        $path = $this->migrationDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path , $template->render());
        return array( $template->class->name,$path );
    }
}


