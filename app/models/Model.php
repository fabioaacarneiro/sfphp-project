<?php

namespace sfphp\Models;

use sfphp\System\Database;

abstract class Model
{
    public $db;

    public function db_connect()
    {
        $options = [
            "host" => MYSQL_HOST,
            "database" => MYSQL_DATABASE,
            "username" => MYSQL_USER,
            "password" => MYSQL_PASSWORD
        ];

        $this->db = new Database($options);
    }

    public function query($sql = "", $params = [])
    {
        return $this->db->execute_query($sql, $params);
    }
}