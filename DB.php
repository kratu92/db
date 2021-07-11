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
	 * 
	 * Private constructor for singleton pattern.
	 * 
	 * @param string $connectionName Name of the connection in the $config variable
	 * 
	 * @return void
	 * 
	 * @throws OutOfRangeException if connection does not exist in config
	 * @throws RuntimeException if the mysql connection cannot be established
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
	 *                                       Comma separated columns to fetch.
	 *                                       Eg: $columns = "id,name"
	 *                                    Option 2 (array):
	 *                                       Array of strings the columns to fetch
	 *                                       Eg: $columns = [ "id", name" ]
	 * @param  array         $conditions  Array to define the query conditions
	 *                                    The results will satisfy all conditions
	 *                                    Examples:
	 *                                    [
	 *                                      "id"      => 1,             // Equals (option 1)
	 *                                      "name"    => ["=", "test"], // Equals (option 2)
	 *                                      "user_id" => [">", 3],      // Other operations
	 *                                      "title"   => "IS NOT NULL", // NULL operations
	 *                                    ]
	 * @param  array         $paramTypes  String with the param types
	 *                                    One per condition.
	 *                                    Exclude the type for IS (NOT) NULL conditions
	 * @param  array         $orderBy     Array to define the order of the query
	 * @param  integer       $limit       Number of results to retrieve.
	 *                                    Default: all results
	 * @param  integer       $offset      Offset from where to retrieve the results
	 *                                    Default: 0
	 *
	 * @return array
	 *
	 * @throws InvalidArgumentException if the table or columns are not provided
	 *
	 * @access public
	 */
	public function get($table, $columns="*", $conditions=[], $paramTypes="", $orderBy=[], $limit=0, $offset=0) {

		if ( empty($table) || empty($columns) )  {
			throw new \InvalidArgumentException("Table or columns not selected.");
		}

		$columns = is_array($columns) ? implode(",",$columns) : $columns;
		$columns = self::sanitizeName($columns);

		$this->resetQuery();

		$whereClause   = $this->getWhereClause($conditions, $paramTypes);
		$orderByClause = $this->getOrderByClause($orderBy);
		$limitClause   = $this->getLimitClause($limit, $offset);

		$query = "SELECT {$columns} FROM {$table} WHERE {$whereClause}
			 {$orderByClause} {$limitClause}";

		$stmt = $this->mysqli->prepare($query);

		if ( empty($stmt) ) {
			throw new RuntimeException("Invalid query");
		}
		
		if ( !empty($this->queryParams) ) {
			$stmt->bind_param(...$this->queryParams);
		}

		$stmt->execute();

		$res = $stmt->get_result();

		if ( !$res ) {
			return [];
		}

		$results = [];

		while ( $row = $res->fetch_assoc() ) {
			$results[] = $row;
		}

		$stmt->close();

		return $results;
	}

	/**
	 * 
	 * Returns an array with the results of a select query
	 * 
	 * @param string $selectQuery Query to retrieve. Must start with SELECT
	 * 
	 * @return array
	 * 
	 * @throws UnexpectedValueException if the type of the query is not SELECT
	 * @throws RuntimeException if the query results in a mysql error
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
	 * 
	 * Returns the first result for a query.
	 * 
	 * Common use: when you expect just one result.
	 * 
	 * @param string $selectQuery Query to retrieve. Must start with SELECT
	 * 
	 * @return array
	 * 
	 * @throws UnexpectedValueException if the type of the query is not SELECT
	 * @throws RuntimeException if the query results in a mysql error
	 * 
	 * @access public
	 * 
	 */
	public function getFirstResult($selectQuery) {
		$results = $this->getResults($selectQuery);
		return $results[0] ?? null;
	}

	/**
	 * 
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
	 * 
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
	 * TO DO
	 */
	private function resetQuery() {
		$this->queryParams = [];
	}

	/**
	 * TO DO
	 */
	private function getWhereClause($conditions, $paramTypes) {

		$conditions = is_array($conditions) ? $conditions : [];

		$currentParam      = 0;
		$queryParamTypes   = "";
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
	 * TO DO
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
				$orderByClause .= " {$column} {$ordBy} ";
			}
		}

		return $orderByClause;
	}

	/**
	 * TO DO
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
	 * 
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
	 * @throws ErrorException if the config is already set
	 * @throws InvalidArgumentException 
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
	 * 
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
	 * TO DO Comment
	 */
	private static function isValidOperator($operator) {
		return !in_array($operator, self::ALLOWED_OPERATORS);
	}

	/**
	 * TO DO Comment
	 */
	private static function isNullOperator($operator) {
		return in_array($operator, self::NULL_OPERATORS);
	}

	/**
	 * TO DO Comment
	 */
	private static function sanitizeName($name) {
		return preg_replace(self::SANITIZE_REGEX, "", $name);
	}
}