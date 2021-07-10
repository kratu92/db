<?php

/**
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

class DB {
    
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
     * 
     * Private constructor for singleton pattern.
     * 
     * @param string $connectionName - Name of the connection in the $config variable
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
    public static function setConfigSettings($config) {
        
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
    
}