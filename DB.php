<?php

/**
 *
 * A simple library to manage mysql connections.
 *
 * To start using this library first call the method setConfig()
 * with all your connection settings.
 *
 * After that you may get the instance for a connection with the
 * getInstance method.
 *
 * PHP Version 7
 *
 * @package   kratu92/db
 * @author    kratu92 Carlos Ortego Casado <kratux92@gmail.com>
 * @license   MIT License
 * @copyright 2021
 * @version   1.0
 *
 */

namespace kratu92;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class DB {
	
	/**
	 * Allowed operators for WHERE conditions
	 */
	private const ALLOWED_OPERATORS = [ "=", "!=", "<", "<=", ">", ">=",
		"IN", "NOT IN", "LIKE", "NOT LIKE" ];

	/**
	 * Allowed Null operators for WHERE conditions
	 */
	private const NULL_OPERATORS = [ "IS NULL", "IS NOT NULL" ];

	/**
	 * Used to remove unwanted characters from table and column names
	 */
	private const SANITIZE_REGEX = "/[^a-zñÑ\_\*\-0-9\,]|--/i";

	/**
	 * Allowed directions to order results
	 */
	private const ORDER_DIRECTIONS = [ "ASC", "DESC" ];

	/**
	 * Stores the connection data
	 */
	private static $config;

	/**
	 * Stores all the instances (singleton pattern)
	 */
	private static $instance = [];
	
	/**
	 * Mysql connection
	 */
	private $mysqli;

	/**
	 * Stores the paramss to bind to the prepared statement
	 */
	private $queryParams;

	/**
	 * Stores the prepared statement
	 */
	private $stmt;

	/**
	 * Private constructor for singleton pattern.
	 *
	 * @param string $connectionName Name of the connection in the $config variable
	 *
	 * @return void
	 *
	 * @throws \OutOfRangeException if connection does not exist in config
	 * @throws \RuntimeException if the mysql connection cannot be established
	 *
	 * @access private
	 *
	 */
	private function __construct($connectionName) {

		if ( !array_key_exists($connectionName, self::$config) ) {
			throw new \OutOfRangeException("Connection not found in config.");
		}

		[
			"host"     => $host,
			"database" => $database,
			"user"     => $user,
			"password" => $password,
		] = self::$config[$connectionName];

		$this->mysqli = new \mysqli($host, $user, $password, $database);

		if ( $this->mysqli->connect_error ) {
			throw new \RuntimeException("Mysql connection error:
				{$this->mysqli->connect_error}");
		}
		
		$this->mysqli->set_charset("utf8");
	}

	/**
	 * Returns an array with the result of a SELECT query.
	 *
	 * WARNING: table and column names should not come from an application user
	 * or it may end up causing an SQL Injection.
	 *
	 * @param  string        $table       Table to fetch
	 * @param  string/array  $columns     Option 1 (string):
	 *                                    	Comma separated columns to fetch.
	 *                                    	Eg: $columns = "id,name"
	 *                                    Option 2 (array):
	 *                                      Array of strings the columns to fetch
	 *                                      Eg: $columns = [ "id", name" ]
	 * @param  array         $conditions  Array to define the query conditions
	 *                                    The results will satisfy all conditions
	 *                                    Eg:
	 *                                    [
	 *                                      "id"      => 1,             // Equals (option 1)
	 *                                      "name"    => ["=", "test"], // Equals (option 2)
	 *                                      "user_id" => [">", 3],      // Other operations
	 *                                      "title"   => "IS NOT NULL", // NULL operations
	 *                                    ]
	 * @param  array         $paramTypes  String with the param types
	 *                                    One per condition.
	 *                                    	i = integer
	 *                                    	d = double
	 *                                    	s = strings/text/dates...
	 *                                    Eg: $paramTypes = "iis"
	 *                                    Exclude the type for IS (NOT) NULL conditions
	 * @param  array         $orderBy     Array to define the order of the query
	 * @param  integer       $limit       Number of results to retrieve.
	 *                                    Default: all results
	 * @param  integer       $offset      Offset from where to retrieve the results
	 *                                    Default: 0
	 *
	 * @return array
	 *
	 * @throws \InvalidArgumentException if the table or columns are not provided
	 * @throws \RuntimeException if there is an error with the query
	 *
	 * @access public
	 */
	public function get($table, $columns="*", $conditions=[], $paramTypes="", 
		$orderBy=[], $limit=0, $offset=0) {

		if ( empty($table) || empty($columns) )  {
			throw new \InvalidArgumentException("Table or columns not selected.");
		}

		if ( $columns != "*" ) {
			$columns = is_array($columns) ? $columns : explode(",", $columns);
			$columns = implode(",", $this->formatColumns($columns));
		}

		$this->resetQuery();

		$table = self::sanitizeName($table);

		$whereClause   = $this->getWhereClause($conditions, $paramTypes);
		$orderByClause = $this->getOrderByClause($orderBy);
		$limitClause   = $this->getLimitClause($limit, $offset);

		$sql = "SELECT {$columns} FROM `{$table}` WHERE {$whereClause}
			 {$orderByClause} {$limitClause}";

		$this->runStmt($sql);

		$dbResult = $this->stmt->get_result();

		$results = [];

		if ( $dbResult ) {
			while ( $row = $dbResult->fetch_assoc() ) {
				$results[] = $row;
			}
		}

		$this->stmt->close();

		return $results;
	}

	/**
	 * Inserts a row to the selected table
	 *
	 * @param string $table          Table where the data is going to be inserted
	 * @param array  $columns        Associative array with the name of the column
	 *                               and the value to insert.
	 *                               $columns = [ "columnName" => "value", ... ]
	 * @param  array $paramTypes     String with the param types
	 *                               One per condition.
	 *                               	i = integer
	 *                               	d = double
	 *                               	s = strings/text/dates...
	 *                               Eg: $paramTypes = "iis"
	 * @param array  $ODKUColumns    On duplicate Key values to update.
	 *                               Same format as $columns.
	 *                               Default: No fields will be updated
	 * @param string $ODKUParamTypes String with param types for $ODKUColumns
	 *                               Same format as $paramTypes.
	 *
	 * @return int                   Returns the id of the inserted/updated row
	 *
	 * @throws \InvalidArgumentException if the table or columns are not provided.
	 * @throws \UnexpectedValueException if there is a parameter mismatch.
	 *                               	
	 *
	 * @access public
	 *
	 */

	public function insert($table, $columns=[], $paramTypes="", 
		$ODKUColumns=[], $ODKUParamTypes="") {

		if ( 
			empty($table) 
			|| !is_array($columns) 
			|| ( !empty($ODKUColumns) && !is_array($ODKUColumns) )  
		) {
			throw new \InvalidArgumentException("Invalid parameters.");
		}

		if ( 
			count($columns) != strlen($paramTypes) 
			|| ( !empty($ODKUColumns) 
				&& ( count($ODKUColumns) != strlen($ODKUParamTypes) ) )
		) {
			throw new \UnexpectedValueException("Parameters mismatch.");
		}

		$this->resetQuery();

		$table = self::sanitizeName($table);

		$columnNames = implode(",", $this->formatColumns(array_keys($columns)));
		$values      = implode(',', array_fill(0, count($columns), '?'));
		
		$this->queryParams = [ &$paramTypes ];

		foreach ( $columns as $column => $value ) {
			$this->queryParams[] = &$columns[$column];
		}

		$sql = "INSERT INTO `{$table}` ({$columnNames}) VALUES ({$values})
			 ON DUPLICATE KEY UPDATE ";

		$ODKUColumns = is_array($ODKUColumns) ? $ODKUColumns : [];

		if ( empty($ODKUColumns) ) {

			// When trying to insert an element with a unique id no action is done.
			$sql .= " `id` = `id`";

		} else {

			$paramTypes .= !empty($ODKUParamTypes) ? $ODKUParamTypes : "";

			foreach ( $ODKUColumns as $column => $value ) {
				$queryParams[] = &$ODKUColumns[$column];
				$column = $this->sanitizeName($column);
				$sql .= " `{$column}` = ?, ";
			}

			$sql .= " id = LAST_INSERT_ID(id) "; // Needed to get the insert_id afterwards
		}

		$this->runStmt($sql);
		$this->stmt->store_result();

		return $this->stmt->insert_id;
	}

	/**
	 * Updates data from a table
	 *
	 * @param string $table       Table to update
	 * @param array  $columns     Associative array with the name of the column
	 *                            to update and the new value.
	 *                            $columns = [ "columnName" => "value", ... ]
	 * @param array  $conditions  Array to define the query conditions
	 *                            The updated columns need to satisfy all conditions
	 *                            Eg:
	 *                            [
	 *                              "id"      => 1,             // Equals (option 1)
	 *                              "name"    => ["=", "test"], // Equals (option 2)
	 *                              "user_id" => [">", 3],      // Other operations
	 *                              "title"   => "IS NOT NULL", // NULL operations
	 *                            ]
	 * @param string $paramTypes  String with the param types. 
	 *                            It must include types for both $columns and $conditions
	 *                            One per column/condition.
	 *                            	i = integer
	 *                            	d = double
	 *                            	s = strings/text/dates...
	 *                            Eg: $paramTypes = "iis"
	 *                            Exclude the type for IS (NOT) NULL conditions
	 * @return boolean
	 *
	 * @throws \InvalidArgumentException if table or columns are not selected
	 * @throws \RuntimeException if a query error occurs
	 *
	 * @access public
	 *
	 */
	function update($table, $columns, $conditions, $paramTypes) {

		if ( empty($table) || empty($columns) )  {
			throw new \InvalidArgumentException("Table or columns not selected.");
		}

		$this->resetQuery();

		$table = self::sanitizeName($table);

		$columnsClause = $this->formatColumns(array_keys($columns), true);
		$columnsClause = implode(",", $columnsClause);

		$columnTypes       = substr($paramTypes, 0, count($columns));
		$this->queryParams = [ &$columnTypes ];

		foreach ( $columns as $columnName => $value ) {
			$this->queryParams[] = &$columns[$columnName];
		}

		$whereClause = $this->getWhereClause(
			$conditions, 
			substr($paramTypes, count($columns))
		);

		$sql  = "UPDATE `{$table}` SET {$columnsClause} WHERE {$whereClause}";

		$this->runStmt($sql);

		return true;
	}


	/**
	 * Deletes rows from the chosen table
	 *
	 * @param string $table      Table in which the rows are going to be deleted.
	 * @param array  $conditions Array to define the query conditions
	 *                           The deleted columns need to satisfy all conditions
	 *                           Eg:
	 *                           [
	 *                             "id"      => 1,             // Equals (option 1)
	 *                             "name"    => ["=", "test"], // Equals (option 2)
	 *                             "user_id" => [">", 3],      // Other operations
	 *                             "title"   => "IS NOT NULL", // NULL operations
	 *                           ]
	 * @param string $paramTypes String with the param types. 
	 *                            One per condition.
	 *                            	i = integer
	 *                            	d = double
	 *                            	s = strings/text/dates...
	 *                            Eg: $paramTypes = "iis"
	 *
	 * @return boolean
	 *
	 * @throws \InvalidArgumentException if a table is not selected
	 * @throws \UnexpectedValueException if columns and param types are mismatched
	 * @throws \UnexpectedValueException if columns and param types are mismatched
	 *
	 * @access public
	 *
	 */
	function delete($table, $conditions, $paramTypes) {

		if ( empty($table) ) {
			throw new \InvalidArgumentException("No table was selected.");
		}

		if ( 
			!is_array($conditions)
			|| count($conditions) != strlen($paramTypes) 
		) {
			throw new \UnexpectedValueException("Params mismatch.");
		}
		
		$this->resetQuery();
		
		$table       = self::sanitizeName($table);
		$whereClause = $this->getWhereClause($conditions, $paramTypes);

		$sql = "DELETE FROM `{$table}` WHERE {$whereClause}";

		$this->runStmt($sql);

		return true;
	}
	
	/**
	 * Returns an array with the results of a select query
	 *
	 * @param string $selectQuery Query to retrieve. Must start with SELECT
	 *
	 * @return array
	 *
	 * @throws \UnexpectedValueException if the type of the query is not SELECT
	 * @throws \RuntimeException if the query results in a mysql error
	 *
	 * @access public
	 *
	 */
	public function getResults($selectQuery) {

		if ( stripos($selectQuery, "SELECT") !== 0 ) {
			throw new \UnexpectedValueException("The query is not a SELECT query.");
		}

		$queryResult = $this->mysqli->query($selectQuery);
		
		if ( !empty($this->mysqli->error) ) {
			throw new \RuntimeException("Mysql error:
				{$this->mysqli->connect_error}");
		}

		$result  = [];

		while ( $row = $queryResult->fetch_assoc() ) {
			$result[] = $row;
		}

		$queryResult->close();

		return $result;
	}
	
	/**
	 * Returns the first result for a query.
	 *
	 * Common use: when you expect just one result.
	 *
	 * @param string $selectQuery Query to retrieve. Must start with SELECT
	 *
	 * @return array
	 *
	 * @throws \UnexpectedValueException if the type of the query is not SELECT
	 * @throws \RuntimeException if the query results in a mysql error
	 *
	 * @access public
	 *
	 */
	public function getFirstResult($selectQuery) {
		$results = $this->getResults($selectQuery);
		return $results[0] ?? null;
	}

	/**
	 * Executes a query as in mysqli::query
	 *
	 * @param string $query 
	 *
	 * @return \mysqli_result
	 *
	 * @access public
	 *
	 */
	public function query($query) {
		return $this->mysqli->query($query);
	}
	
	/**
	 * Prepares an SQL statement for execution as in mysqli::prepare
	 *
	 * @param string $query
	 *
	 * @return \mysqli_stmt
	 *
	 * @access public
	 *
	 */
	public function prepare($query) {
		return $this->mysqli->prepare($query);
	}

	/**
	 * Resets the class attributes needed for the query.
	 *
	 * @return void 
	 *
	 * @access private
	 *
	 */
	private function resetQuery() {
		$this->stmt        = null;
		$this->queryParams = [];
	}

	/**
	 * Prepares the statement, binds the query params and executes
	 * it to query the database securely.
	 * 
	 * @param string $sql The query to prepare
	 * 
	 * @return void
	 * 
	 * @throws \RuntimeException if prepare statement fails 
	 * 
	 * @access private
	 * 
	 */
	private function runStmt($sql) {

		$this->stmt = $this->mysqli->prepare($sql);

		if ( empty($stmt) ) {
			throw new \RuntimeException("Invalid query.");
		}

		if ( !empty($this->queryParams) ) {
			$this->stmt->bind_param(...$this->queryParams);
		}

		$this->stmt->execute();

		if ( !empty($this->stmt->error) ) {
			throw new \RuntimeException("An error ocurred: {$stmt->error}.");
		}
	}

	/**
	 * Removes unexpected chars and adds backticks to the column names.
	 * If the query is for an UPDATE query, it adds the "= ?"
	 * portion of the query.
	 *
	 * @param array    $columns     Array of strings with the column names.
	 * @param boolean  $isForUpdate Determine whether it is for an UPDATE query
	 *                              or not. If it is "= ?" will be added.
	 *
	 * @return array  Array with the formatted columns
	 *
	 * @access private
	 */
	private function formatColumns($columns, $isForUpdate=false) {

		return array_map(
			function ($col) use ($isForUpdate) {
				$col = DB::sanitizeName($col);
				return "`{$col}`" . ( $isForUpdate ? " = ?" : "" );
			}, 
			$columns
		);
	}

	/**
	 * Builds the WHERE Clause from the given conditions.
	 *
	 * @param array   $conditions Array with the conditions.
	 *                            See params for get, update or delete.
	 * @param string  $paramTypes String with the param types for the 
	 *                            given $conditions.
	 *                            See params for get, update or delete.
	 *
	 * @return string
	 *
	 * @access private
	 *
	 */
	private function getWhereClause($conditions, $paramTypes) {

		$conditions = is_array($conditions) ? $conditions : [];

		$currentParam      = 0;
		$queryParamTypes   = $this->queryParams[0] ?? "";
		$this->queryParams = [ &$queryParamTypes ];

		$whereClause  = "";

		foreach ( $conditions as $column => $value ) {

			$column = self::sanitizeName($column);

			$whereClause .= !empty($whereClause) ? " AND " : "";

			// Case: [ "column" => "IS NULL" ]
			if ( self::isNullOperator($value) ) {
				$whereClause .= " `{$column}` {$value} ";
				continue;
			}

			// Case: [ "column" => $value ]
			if ( !is_array($value) ) {
				$conditions[$column] = $value = [ "=", $value ];
			}

			// Case: [ "column" => [$operator, $value] ]
			[ $operator, $value, ] = $value + [ null, null ];

			if ( self::isValidOperator($operator) ) {
				throw new \UnexpectedValueException("Invalid SQL operator.");
			}

			$paramType = $paramTypes[$currentParam++];

			// Case: [ "column" => [ "(NOT) IN" => [...] ] ]
			if ( in_array($operator, ["IN", "NOT IN"]) ) {

				if ( !is_array($value) ) {
					throw new \UnexpectedValueException("Expected array.");
				}

				$whereClause .= " `{$column}` {$operator} ("
					. implode(',', array_fill(0, count($value), '?'))
				. ")";

				$currentParam++;
				for ( $i = 0; $i < count($value); $i++ ) {
					$queryParamTypes    .= $paramType;
					$this->queryParams[] = &$conditions[$column][1][$i];
				}
				
				continue;
			}

			$whereClause .= " `{$column}` {$operator} ? ";

			$queryParamTypes    .= $paramType;
			$this->queryParams[] = &$conditions[$column][1];
		}

		return !empty($whereClause) ? $whereClause : 1;
	}

	/**
	 * Builds the ORDER BY clause with the given conditions.
	 *
	 * @param array $orderBy Array with the order conditions.
	 *                       See params for the get function.
	 *
	 * @return string
	 *
	 * @access private
	 *
	 */
	private function getOrderByClause($orderBy) {

		$orderByClause = "";

		if ( !empty($orderBy) && is_array($orderBy) ) {

			foreach ( $orderBy as $column => $ordBy ) {

				$column = self::sanitizeName($column);

				if ( !in_array($ordBy, self::ORDER_DIRECTIONS) ) {
					throw new \UnexpectedValueException("Invalid order value.");
				}

				$orderByClause .= empty($orderBy) ? " ORDER BY " : ", ";
				$orderByClause .= " `{$column}` {$ordBy} ";
			}
		}

		return $orderByClause;
	}

	/**
	 * Builds the LIMIT clause with the given limit and offset.
	 *
	 * @param integer $limit   Number of results.
	 * @param integer $offset  Offset from which the results will be retrieved.
	 *
	 * @return string
	 *
	 * @access private
	 *
	 */
	private function getLimitClause($limit, $offset) {

		$limit   = intval($limit);
		$offset  = intval($offset);

		$limitClause = "";

		if ( !empty($limit) ) {
			$limitClause .= " LIMIT {$limit} ";
			$limitClause .= !empty($offset) ? " OFFSET $offset " : "";
		}

		return $limitClause;
	}

	/**
	 * Sets the connection settings.
	 *
	 * This method should be called only once, before any other methods are called.
	 *
	 * @param array $config Array containing the connection data
	 *                      You can add as many connections as needed.
	 *
	 *                      $config = [
	 *                          "connectionName" => [
	 *                              "host"     => "...",
	 *                              "database" => "...",
	 *                              "user"     => "...",
	 *                              "password" => "...",
	 *                          ],
	 *                          "connection2Name" => [ ... ],
	 *                          ...
	 *                      ];
	 *
	 * @return boolean
	 *
	 * @throws \ErrorException if the config is already set
	 * @throws \InvalidArgumentException 
	 *
	 * @access public
	 * @static
	 *
	 */
	public static function setConfig($config) {
		
		if ( !empty(self::$config) ) {
			throw new \ErrorException("Config is already set");
		}

		if ( !is_array($config) ) {
			throw new \InvalidArgumentException("Must be an array");
		}

		$requiredSettings = [ "host", "database", "user", "password" ];

		foreach ( $config as $connectionSettings ) {
			
			$missingSettings = array_diff(
				$requiredSettings,
				array_keys($connectionSettings)
			);

			if ( !empty($missingSettings) ) {
				throw new \InvalidArgumentException("Invalid format.");
			}
		}

		self::$config = $config;

		return true;
	}

	/**
	 * Obtains the instance for the requested connection
	 *
	 * @param string $connectionName Connection name set in config
	 *
	 * @return DB
	 *
	 * @access public
	 * @static
	 *
	 */
	public static function getInstance($connectionName) {

		if ( empty(self::$instance[$connectionName]) ) {
			self::$instance[$connectionName] = new self($connectionName);
		}

		return self::$instance[$connectionName];
	}

	/**
	 * Determines whether the operator is valid or not
	 * 
	 * @param string $operator Operator to check.
	 *                         IS NULL/IS NOT NULL Excluded.
	 * 
	 * @return boolean
	 * 
	 * @access private
	 * 
	 */
	private static function isValidOperator($operator) {
		$operator = strtoupper($operator);
		return !in_array($operator, self::ALLOWED_OPERATORS);
	}

	/**
	 * Determines whether the null operator is valid or not
	 * 
	 * @param string $operator Operator matches IS NULL or IS NOT NULL.
	 * 
	 * @return boolean
	 * 
	 * @access private
	 */
	private static function isNullOperator($operator) {
		$operator = strtoupper($operator);
		return in_array($operator, self::NULL_OPERATORS);
	}

	/**
	 * Removes unwanted character from the given name.
	 * Used for table and column names.
	 * 
	 * @param string $name Name to sanitize
	 * 
	 * @return string
	 * 
	 * @access private
	 * 
	 */
	private static function sanitizeName($name) {
		return preg_replace(self::SANITIZE_REGEX, "", $name);
	}
}