<?php
/**
 * Jasny DB - A DB layer for the masses.
 * 
 * PHP version 5.3+
 * 
 * @author  Arnold Daniels <arnold@jasny.net>
 * @license https://raw.github.com/jasny/db/master/LICENSE MIT
 * @link    https://jasny.github.io/db
 */
/** */
namespace Jasny\DB\MySQL;

/**
 * MySQL DB connection.
 * 
 * @example <br/>
 *   use Jasny\DB\MySQL\Connection as DB;<br/>
 *   new DB($host, $user, $pwd, $dbname);<br/>
 *   $result = DB::conn()->query("SELECT * FROM foo WHERE id = ?", $id);
 * 
 * @package MySQL
 */
class Connection extends \mysqli implements \Jasny\DB\Connection
{
    /**
     * Don't update existing records when saving, but ignore them instead.
     * 
     * @var boolean
     */
    const SKIP_EXISTING = false;

    /**
     * Created DB connection
     * @var DB
     */
    protected static $connection;

    /**
     * Namespace of the Record and Table classes.
     * @var string
     */
    public $modelNamespace;

    /**
     * The fixed timezone
     * @var type 
     */
    private $timezone = null;
    

    /**
     * Get the default DB connection.
     * 
     * @return DB
     */
    public static function conn()
    {
        if (!isset(self::$connection)) self::$connection = new static();
        return self::$connection;
    }

    /**
     * Class constructor.
     * 
     * @param string|array $host      Hostname or settings as assoc array
     * @param string       $username
     * @param string       $password
     * @param string       $dbname
     * @param int          $port 
     */
    public function __construct($host = 'localhost', $username = null, $password = null, $dbname = null, $port = null)
    {
        if (!is_scalar($host)) extract((array)$host, EXTR_IF_EXISTS);
        
        parent::__construct($host, $username, $password, $dbname, $port);
        if ($this->connect_error) throw new \Exception("Failed to connect to the database: " . $this->connect_error);
        
        $this->set_charset('utf8');
        
        if (!isset(self::$connection)) self::$connection = $this;
        if (!isset(\Jasny\DB\Table::$defaultConnection)) \Jasny\DB\Table::$defaultConnection = $this;
    }
    
    /**
     * Closes a previously opened database connection.
     * 
     * @return boolean
     */
    public function close()
    {
        if (self::$connection === $this) self::$connection = null;
        if (\Jasny\DB\Table::$defaultConnection === $this) \Jasny\DB\Table::$defaultConnection = null;
        
        return parent::close();
    }
    
    /**
     * Use this connection as the default DB
     */
    public function asDefault()
    {
        self::$connection = $this;
        \Jasny\DB\Table::$defaultConnection = $this;
    }

    
    /**
     * Use a fixed timezone like '00:00' instead of 'SYSTEM' or 'Europe/Amsterdam'.
     * 
     * This makes timestamp fields behave different than datetime fields, in that the time is
     * modified based on the timezone (inc day light saving).
     * 
     * Use Connection::caseTimestamp() to use the correct timezone for timestamp fields.
     */
    public function fixTimezone()
    {
        $timediff = $this->fetchValue("SELECT timediff(now(),convert_tz(now(),@@session.time_zone,'+00:00'))");
        list($h, $m) = explode(':', $timediff);
        
        $this->timezone = sprintf('%+03d:%02d', $h, $m);
        $this->query("SET TIME_ZONE = ?", $this->timezone);
    }
    
    /**
     * Cast a timestamp date string into a DateTime object with correct timezone.
     * 
     * Use this in combintation with Connection::fixTimezone().
     * Do not use this with datetime or date fields.
     * 
     * @param string $date
     * @return DateTime
     */
    public function castTimestamp($date)
    {
        $date = new \DateTime($date . $this->timezone);
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $date;
    }
    
    
    /**
     * Performs a query on the database.
     * Don't mix both types ('?' and ':key') of placeholders.
     * 
     * @example DB::conn()->query("SELECT * FROM mytable WHERE id=?", $id);
     * @example DB::conn()->query("SELECT * FROM mytable WHERE name=:name AND age>:age AND status='A'", array('id'=>$id, 'age'=>$age));
     * 
     * @param string $query  SQL Query
     * @return \mysqli_result
     */
    public function query($query)
    {
        if (func_num_args() > 1) $query = call_user_func_array(array(get_class(), 'bind'), func_get_args());

        $result = parent::query((string)$query);
        if (!$result) throw new Exception($this->error, $this->errno, $query);

        return $result;
    }
    

    /**
     * Query and fetch all result rows as an associative array, a numeric array, or both.
     * 
     * @example DB::conn()->fetchAll("SELECT * FROM mytable");
     * @example DB::conn()->fetchAll("SELECT * FROM mytable", MYSQLI_NUM);
     * @example DB::conn()->fetchAll("SELECT * FROM mytable WHERE group=?", MYSQLI_ASSOC, $group);
     * @example DB::conn()->fetchAll("SELECT * FROM foobar WHERE group=?", 'FooBar', $group)
     *
     * @param string|\mysqli_result $query       SQL Query or DB result
     * @param int|string            $resulttype  MYSQLI_ASSOC, MYSQLI_NUM, MYSQLI_BOTH or class name
     * @return array
     */
    public function fetchAll($query, $resulttype = MYSQLI_ASSOC)
    {
        if (func_num_args() > 2) {
            $args = func_get_args();
            unset($args[1]);
            $query = call_user_func_array(array(get_class(), 'bind'), array_values($args));
        }

        $result = $query instanceof \mysqli_result ? $query : $this->query($query);

        // Using mysqlnd :)
        if (function_exists('mysqli_fetch_all') && is_int($resulttype)) {
            return $result->fetch_all($resulttype);
        }

        // We don't have it :( or we're fetching objects
        $rows = array();
        if (is_string($resulttype)) {
            while ($row = $result->fetch_object($resulttype)) $rows[] = $row;
        } else {
            while ($row = $result->fetch_array($resulttype)) $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Query and fetch all result rows as an associative array, a numeric array, or both.
     * 
     * @example DB::conn()->fetchOne("SELECT * FROM mytable");
     * @example DB::conn()->fetchOne("SELECT * FROM mytable", MYSQLI_NUM);
     * @example DB::conn()->fetchOne("SELECT * FROM mytable WHERE id=?", MYSQLI_ASSOC, $id);
     * @example DB::conn()->fetchOne("SELECT * FROM foobar WHERE id=?", 'FooBar', $id);
     *
     * @param string|\mysqli_result $query       SQL Query or DB result
     * @param int|string            $resulttype  MYSQLI_ASSOC, MYSQLI_NUM, MYSQLI_BOTH or class name
     * @return array
     */
    public function fetchOne($query, $resulttype = MYSQLI_ASSOC)
    {
        if (func_num_args() > 2) {
            $args = func_get_args();
            unset($args[1]);
            $query = call_user_func_array(array(get_class(), 'bind'), array_values($args));
        }

        $result = $query instanceof \mysqli_result ? $query : $this->query($query);

        return is_string($resulttype) ? $result->fetch_object($resulttype) : $result->fetch_array($resulttype);
    }

    /**
     * Query and fetch a single column from all result rows.
     * 
     * @example DB::conn()->fetchColumn("SELECT name FROM mytable");
     * @example DB::conn()->fetchColumn("SELECT name FROM mytable WHERE group=?", $group);
     *
     * @param string|\mysqli_result $query  SQL Query or DB result
     * @param string                $field  The field position or name to fetch
     * @return array
     */
    public function fetchColumn($query)
    {
        if (func_num_args() > 1) $query = call_user_func_array(array(get_class(), 'bind'), func_get_args());

        $result = $query instanceof \mysqli_result ? $query : $this->query($query);

        $values = array();
        while (list($value) = $result->fetch_row()) {
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Fetches all result rows and creates an associated array with the first column as key and the second as value.
     * 
     * @example DB::conn()->fetchPairs("SELECT id, name FROM mytable");
     * @example DB::conn()->fetchPairs("SELECT id, name FROM mytable WHERE group=?", $group);
     *
     * @param string|\mysqli_result $query  SQL Query or DB result
     * @return array
     */
    public function fetchPairs($query)
    {
        if (func_num_args() > 1) $query = call_user_func_array(array(get_class(), 'bind'), func_get_args());

        $result = $query instanceof \mysqli_result ? $query : $this->query($query);

        $values = array();
        while (list($key, $value) = $result->fetch_row()) {
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Query and fetch a single value.
     * 
     * @example DB::conn()->fetchValue("SELECT SUM(foo) FROM mytable");
     * @example DB::conn()->fetchValue("SELECT name FROM mytable WHERE id=?", $id);
     *
     * @param string|\mysqli_result $query  SQL Query or DB result
     * @param string                $field  The field position or name to fetch
     * @return array
     */
    public function fetchValue($query)
    {
        if (func_num_args() > 1) $query = call_user_func_array(array(get_class(), 'bind'), func_get_args());

        $result = $query instanceof \mysqli_result ? $query : $this->query($query);
        list($value) = $result->fetch_row();

        return $value;
    }

    
    /**
     * Insert or update a record.
     * All rows should have the same keys in the same order.
     * 
     * @example DB::conn()->save('mytable', $row)
     * @example DB::conn()->save('mytable', array($row1, $row2, $row3))
     * @example DB::conn()->save('mytable', array($row1, $row2, $row3), DB::SKIP_EXISTING)
     * 
     * @param string  $table
     * @param array   $values  One or multiple rows of values
     * @param boolean $update  Update on duplicate key
     * @return int  Last insert ID
     */
    public function save($table, array $values = array(), $update = true)
    {
        if (!is_array(reset($values))) $values = array($values); // Make sure that $values is a set of rows

        $fields = array();
        $query_values = array();
        $query_update = array();

        foreach (array_keys(reset($values)) as $key) {
            $field = static::backquote($key);
            $fields[$key] = $field;
            $query_update[$key] = "$field=VALUES($field)";
        }

        foreach ($values as &$row) {
            $vals = array();
            foreach (array_keys($fields) as $key) {
                $vals[$key] = static::quote($row[$key], 'DEFAULT');
            }
            $query_values[] = '(' . join(', ', $vals) . ')';
        }

        $query = "INSERT" . ($update ? '' : " IGNORE") . " INTO `" . str_replace('`', '', $table) . "` (" . join(', ', $fields) . ") VALUES " . join(', ', $query_values) .
                ($update ? " ON DUPLICATE KEY UPDATE " . join(', ', $query_update) : '');

        $this->query($query);
        return $this->insert_id;
    }

    
    /**
     * Quote a value so it can be savely used in a query.
     * 
     * @param mixed  $value
     * @param string $empty  Return $empty if $value is null
     * @return string
     */
    public static function quote($value, $empty = 'NULL')
    {
        if (is_array($value)) return '(' . join(', ', array_map(array(get_class(), 'quote'), $value)) . ')';

        if (is_null($value)) return $empty;
        if (is_bool($value)) return $value ? 'TRUE' : 'FALSE';
        if (is_int($value) || is_float($value)) return (string)$value;
        
        if ($value instanceof \DateTime) {
            $value->setTimezone(new \DateTimeZone(date_default_timezone_get())); // MySQL can't handle timezones
            $value = $value->format('c');
        }
        
        return '"' . strtr((string)$value, array('\\' => '\\\\', "\0" => '\\0', "\r" => '\\r', "\n" => '\\n', '"' => '\\"')) . '"';
    }

    /**
     * Quote a field, table or dbname so it can be savely used in a query.
     * 
     * @param string $field
     * @return string
     */
    public static function backquote($field)
    {
        return '`' . str_replace('`', '', $field) . '`';
    }

    /**
     * Insert parameters into SQL query.
     * Don't mix both types ('?' and ':key') of placeholders.
     * 
     * @example DB::bind("SELECT * FROM mytable WHERE id=?", $id);
     * @example DB::bind("SELECT * FROM mytable WHERE name=:name AND age>:age AND status='A'", array('id'=>$id, 'age'=>$age));
     * 
     * @param string $query
     * @param mixed  $params  Parameters can be passed as indifidual arguments or as array
     * @return string
     */
    public static function bind($query, $params = array())
    {
        if ($query instanceof \mysqli_result) trigger_error("Can only bind on a query not on a query result", E_USER_ERROR);
        
        if (!is_array($params) || is_int(key($params))) {
            $args = func_get_args();
            $params = array_splice($args, 1);
        }
        
        $quote = array(get_called_class(), 'quote'); // Callback
        
        $fn = function ($match) use (&$params, $quote) {
            if (!empty($match[1]) && !empty($params)) return call_user_func($quote, array_shift($params));
            if (!empty($match[2]) && array_key_exists($match[2], $params)) return call_user_func($quote, $params[$match[2]]);
            return $match[0];
        };

        return preg_replace_callback('/`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|(\?)|:(\w++)/', $fn, $query);
    }
    

    /**
     * Get a table gateway.
     * 
     * @param string $name  Table name
     * @return Table
     */
    public function table($name)
    {
        return Table::factory($name, $this);
    }

    
    /**
     * Set the model namespace.
     * 
     * @param string $ns
     */
    public function setModelNamespace($ns)
    {
        $this->modelNamespace = trim($ns, '\\');
    }

    /**
     * Get the model namespace.
     * 
     * @return string
     */
    public function getModelNamespace()
    {
        return $this->modelNamespace;
    }
}