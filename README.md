# db

db is a simple PHP library to interact with mysql databases.
* **Multiple databases** You may add as many different databases as you need.
* **Prepared statements** Safe queries using the built in get, insert, update and delete methods.
* **Singleton pattern** only one connection will be established for each database.

## Installation

Just download the DB.php file and locate it inside your project.

## Setting up

First you need to require the file and add the connection data.

```PHP

require_once "DB.php";

use kratu92\DB;

$config = [
    "main" => [
        "host"     => "127.0.0.1",
        "user"     => "root",
        "password" => "root",
        "database" => "myDB",
    ],
];

DB::setConfig($config);

```

## Usage

Now you will be able to use the library to interact with your database:

```PHP

$db = DB::getInstance("main");

$result = $db->get(
    "users",
    "*", 
    [ "id" => [ ">", 0], ],
    "i"
);

```

