<?php
/**
 * Jasny DB - A DB layer for the masses.
 * 
 * @author  Arnold Daniels <arnold@jasny.net>
 * @license https://raw.github.com/jasny/db/master/LICENSE MIT
 * @link    https://jasny.github.io/db
 */
/** */
namespace Jasny\DB;

/**
 * Generate DB model classes.
 * 
 * <code>
 *   if (getenv('APPLICATION_ENV') == 'prod') set_include_path(get_include_path() . ':../cache/model');
 *   Jasny\DB\ModelGenerator::enable('../cache/model');
 * </code>
 */
class ModelGenerator
{
    /**
     * Namespace for base classes
     * @var string
     */
    public static $baseNamespace = 'DB';
    
    /**
     * Path to cache
     * @var string
     */
    protected static $cachePath;

    
    /**
     * Check if type is a PHP internal type.
     * 
     * @param string $type
     * @return boolean
     */
    protected static function isInternalType($type)
    {
        return in_array($type, array('bool', 'boolean', 'int', 'integer', 'float', 'string', 'array'));
    }

    /**
     * Indent each line
     * 
     * @param string $code
     * @return string
     */
    protected static function indent($code, $spaces = 4)
    {
        return preg_replace('~^~m', str_repeat(' ', $spaces), $code);
    }
    
    /**
     * Get a table gateway (ignoring custom table gateways).
     * 
     * @param string $name
     * @return Table
     */
    protected static function getTable($name)
    {
        $class = Table::getDefaultClass('Table');
        return $class::instantiate($name);
    }
    
    /**
     * Get a hash to seen if the table has been modified.
     * 
     * @param string|Table $table
     * @return string
     */
    protected static function getChecksum($table)
    {
        if (!$table instanceof Table) $table = static::getTable($table);
        
        $pk = $table->getPrimarykey();
        $defaults = $table->getFieldDefaults();
        $types = $table->getFieldTypes();
        
        return md5(serialize(compact('pk', 'defaults', 'types')));
    }
    
    /**
     * Split full class in class name and namespase
     * 
     * @param string $class
     * @param string $ns     Replace namespace
     * @return array (class name, namespace, full class)
     */
    protected static function splitClass($class, $ns=null)
    {
        $parts = explode('\\', $class);
        $classname = array_pop($parts);

        if (!isset($ns)) $ns = join('\\', $parts);
        return array($classname, $ns, ($ns ? $ns . '\\' : '') . $class);
    }
    
    
    /**
     * Generate Table class for a table.
     * 
     * @param Table|string $table
     * @param string       $ns       Replace namespace
     * @return string
     */
    public static function generateTable($table, $ns=null)
    {
        // Init and check
        if (!$table instanceof Table) $table = static::getTable($table);
        $class = $table->getRecordClass(Table::SKIP_CLASS_EXISTS) . 'Table';

        list($classname, $ns, $class) = static::splitClass($class, $ns);
        
        $checksum = static::getChecksum($table);
        
        // Generate code
        $namespace = $ns ? "namespace $ns;\n" : '';
        
        $base = ($namespace ? '\\' : '') .get_class($table);

        $getFieldDefaults = static::indent("return " . var_export($table->getFieldDefaults(), true) . ";", 8);
        $getFieldTypes = static::indent("return " . var_export($table->getFieldTypes(), true) . ";", 8);
        $getPrimarykey = static::indent("return " . var_export($table->getPrimarykey(), true) . ";", 8);
        
        $code = <<<PHP
$namespace
/**
 * Table gateway for `$table`.
 * {@internal This file is automatically generated and may be overwritten.}}
 *
 * @hash $checksum
 */
class $classname extends $base
{
    /**
     * Get all the default value for each field for this table.
     * 
     * @return array
     */
    public function getFieldDefaults()
    {
$getFieldDefaults
    }

    /**
     * Get the php type for each field of this table.
     * 
     * @return array
     */
    public function getFieldTypes()
    {
$getFieldTypes
    }
    
    /**
     * Get primary key.
     * 
     * @return string
     */
    public function getPrimarykey()
    {
$getPrimarykey
    }
}
PHP;
        
        return $code;
    }
    
    /**
     * Generate a Record class for a table.
     * 
     * @param Table|string $table
     * @param string       $ns       Replace namespace
     * @return string|boolean
     */
    public static function generateRecord($table, $ns=null)
    {
        // Init and check
        if (!$table instanceof Table) $table = static::getTable($table);
        $class = $table->getRecordClass(Table::SKIP_CLASS_EXISTS);

        list($classname, $ns, $class) = static::splitClass($class, $ns);
        
        // Get information
        $defaults = $table->getFieldDefaults();
        $types = $table->getFieldTypes();
        
        $checksum = static::getChecksum($table);
        
        // Generate code
        $namespace = $ns ? "namespace $ns;\n" : '';

        $base = Table::getDefaultClass('Record', $table->db()) ?: __NAMESPACE__ . '\\Record';
        if ($namespace) $base = '\\' . $base;

        $properties = "";
        $cast = "";
        foreach ($defaults as $field=>$value) {
            if (preg_match('/\W/', $field)) throw new \Exception("Can't create a property for field '$field'");
            
            $type = (static::isInternalType($types[$field]) ? '' : '\\') . $types[$field];
            $isVal = isset($value) ? ' = ' . var_export($table->castValue($value, $types[$field], false), true) : '';
            
            $properties .= "    /** @var $type */\n    public \${$field}{$isVal};\n\n";
            
            if (static::isInternalType($type)) {
                $cast .= "        if (isset(\$this->$field)) \$this->$field = ($type)\$this->$field;\n";
            } else {
                $cast .= "        if (isset(\$this->$field) && !\$this->$field instanceof $type)"
                    ." \$this->$field = new $type(\$this->$field);\n";
            }
        }

        $code = <<<PHP
$namespace 
/**
 * Record of table `$table`.
 * {@internal This file is automatically generated and may be overwritten.}}
 *
 * @checksum $checksum
 */
class $classname extends $base
{
$properties
    
    /**
     * Class constructor
     */
    public function __construct()
    {
        \$this->cast();
    }
                    
    /**
     * Cast all properties to a type based on the field types.
     * 
     * @return $classname \$this
     */
    public function cast()
    {
$cast
        return \$this;
    }
                
    /**
     * Set the table gateway.
     * @ignore
     * 
     * @param {$classname}Table \$table
     */
    public function _setDBTable(\$table)
    {
        if (!isset(\$this->_dbtable)) \$this->_dbtable = \$table;
    }
}

PHP;

        return $code;
    }
    
    
    /**
     * See if there is a valid file in cache and include it.
     * 
     * @param string $class
     * @return boolean
     */
    protected static function loadFromCache($class)
    {
        if (!isset(self::$cachePath)) return false;
        
        $filename = self::$cachePath . '/' . strtr($class, '\\_', '//') . '.php';
        if (!file_exists($filename)) return false;
        
        // Check if table definition hasn't changed
        list($classname) = self::splitClass($class);
        $name = Table::uncamelcase(preg_replace('/Table$/i', '', $classname));
        $hash = self::getChecksum($name);

        $code = file_get_contents($filename);
        if (!strpos($code, "@checksum $hash")) return false;
        
        include $filename;
        return true;
    }
    
    /**
     * Save the generated code to cache and include it
     * 
     * @param string $class
     * @param string $code
     * @return boolean
     */
    protected static function cacheAndLoad($class, $code)
    {
        if (!isset(self::$cachePath)) return false;
        
        $filename = self::$cachePath . '/' . strtr($class, '\\_', '//') . '.php';

        if (!file_exists(dirname($filename))) mkdir(dirname($filename), 0777, true);
        if (!file_put_contents($filename, $code)) return false;
        
        include $filename;
        return true;
    }
    
    /**
     * Save the autogenerated classes in cache.
     * 
     * @param string  $cache_path  Directory to save the cache files
     */
    public static function enable($cache_path=null)
    {
        static::$cachePath = $cache_path;
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    /**
     * Automatically create classes for table gateways and records
     * 
     * @param string $class
     */
    protected static function autoload($class)
    {
        list($classname, $ns) = static::splitClass($class);
        $model_ns = Table::getDefaultConnection()->getModelNamespace();
        if (preg_replace('/(^|\\\\)' . static::$baseNamespace . '$/', '', $ns) != $model_ns) return;
        
        if (self::loadFromCache($class)) return;
        
        $name = Table::uncamelcase(preg_replace('/Table$/i', '', $classname));
        if (empty($name) || !Table::getDefaultConnection()->tableExists($name)) return;
        
        $code = substr($classname, -5) == 'Table' ?
            static::generateTable($name, $ns) :
            static::generateRecord($name, $ns);
        
        self::cacheAndLoad($class, "<?php\n" . $code) or eval($code);
    }
    
    
    /**
     * Create all classes of the model
     */
    public static function warmCache()
    {
        $tables = Table::getDefaultConnection()->getAllTables();
        $ns = Table::getDefaultConnection()->getModelNamespace();
        if ($ns) $ns .= '\\';
        
        foreach ($tables as $table) {
            $class = Table::camelcase($table);
            
            self::autoload($ns . $class);
            self::autoload($ns . $class . 'Table');
        }
    }
}
