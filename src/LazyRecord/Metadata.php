<?php
namespace LazyRecord;
use ArrayAccess;
use IteratorAggregate;
use LazyRecord\TableParser\TableParser;
use ArrayIterator;
use PDO;


/**
 * TODO: Extract the key-value storage methods into a KeyValueSchema to 
 *       generalize the key-value store usecase.
 */
class Metadata
    implements ArrayAccess, IteratorAggregate
{

    /**
     * @var string data source id
     */
    public $dsId;


    /**
     * @var PDO PDO connection object
     */
    public $connection;


    /**
     * @var SQLBuilder\QueryDriver QueryDriver from SQLBuilder
     */
    public $driver;


    /**
     * Users can store different metadata in the different data sources.
     * This constructor requires the first parameter to be the data source Id to 
     * initialize the metadata object.
     *
     * @param string $dsId
     */
    public function __construct($dsId) 
    {
        $this->dsId = $dsId;
        $connm = ConnectionManager::getInstance();
        $this->connection = $connm->getConnection($this->dsId);
        $this->driver = $connm->getQueryDriver($this->dsId);
        $this->init();
    }


    /**
     * This method initialize the metadata table if needed.
     */
    public function init()
    {
        $parser = TableParser::create( $this->driver, $this->connection );
        $tables = $parser->getTables();

        // if the __meta__table is not found, we should create one to prevent error.
        // this will be needed for the compatibility of the older version lazyrecord.
        if( ! in_array('__meta__',$tables) ) {
            $schema = new \LazyRecord\Model\MetadataSchema;
            $builder = \LazyRecord\SqlBuilder\SqlBuilder::create($this->driver);
            $sqls = $builder->build($schema);
            foreach($sqls as $sql) {
                $this->connection->query($sql);
            }
        }
    }

    /**
     * Get the current version number from the key-value store.
     *
     * @return integer version number
     */
    public function getVersion()
    {
        if( isset( $this['version'] ) ) {
            return $this['version'];
        }
        return $this['version'] = 0;
    }



    /**
     * Check if a key exists in the database.
     *
     * @param string $key
     */
    public function hasAttribute($key) {
        $stm = $this->connection->prepare('select * from __meta__ where name = :name');
        $stm->execute(array( ':name' => $key ));
        $data = $stm->fetch( PDO::FETCH_OBJ );
        return $data ? true : false;
    }

    /**
     * Set an attribute
     *
     * @param string $key
     * @param string $value
     */
    public function setAttribute($key, $value) {
        $stm = $this->connection->prepare('select * from __meta__ where name = :name');
        $stm->execute(array( ':name' => $key ));
        $obj = $stm->fetch( PDO::FETCH_OBJ );
        if( $obj ) {
            $stm = $this->connection->prepare('update __meta__ set value = :value where name = :name');
            $stm->execute(array( ':name' => $key, ':value' => $value ));
        } else {
            $stm = $this->connection->prepare('insert into __meta__ (name,value) values (:name,:value)');
            $stm->execute(array( ':name' => $key, ':value' => $value ));
        }
    }



    /**
     * Set a value with a key
     *
     * @param string $key    the key
     * @param string $value   the value
     */
    public function offsetSet($key,$value)
    {
        return $this->setAttribute($key, $value);
    }

    /**
     * Check if a key exists.
     */
    public function offsetExists($key)
    {
        return $this->hasAttribute($key);
    }

    public function offsetGet($name)
    {
        $stm = $this->connection->prepare('select * from __meta__ where name = :name');
        $stm->execute(array( ':name' => $name ));
        $data = $stm->fetch( PDO::FETCH_OBJ );
        if($data)
            return $data->value;
    }

    public function offsetUnset($name)
    {
        $stm = $this->connection->prepare('delete from __meta__ where name = :name');
        $stm->execute(array( ':name' => $name ));
    }

    /**
     * Get iterator for the key-value pair data.
     *
     * Please note this method does not cache the meta data, if you call 
     * this method, this method will do another SQL query to fetch all the attribute
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        $stm = $this->connection->prepare('SELECT * FROM __meta__');
        $stm->execute();
        $rows = $stm->fetchAll(PDO::FETCH_OBJ);
        $data = array();
        foreach( $rows as $row ) {
            $data[$row->name] = $row->value;
        }
        return new ArrayIterator($data);
    }
}


