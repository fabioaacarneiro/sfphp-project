<?php

namespace SfphpProject\src;

use PDO;
use PDOException;

class Database {
  private static $instance;

  /**
   * Private constructor to prevent instantiation
   */
  private function __construct() {}

  /**
   * Connect to the database and return the PDO instance
   *
   * @return PDO
   */
  public static function connect() {
    if (!self::$instance) {
      Dotenv::loadEnv(__DIR__ . "/../.env");

      try {
        self::$instance = new PDO(
          "mysql:host=" . $_ENV['DB_HOST'] 
            . ";dbname=" . $_ENV['DB_NAME'], 
          $_ENV['DB_USER'], 
          $_ENV['DB_PASS']);
        self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
      }
    }

    return self::$instance;
  }
}

