<?php

namespace SfphpProject\src;

use PDO;
use PDOException;

class Database
{
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
  public static function connect(): PDO
  {
    if (!self::$instance) {
      Dotenv::loadEnv(__DIR__ . "/../.env");

      $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
      $host   = $_ENV['DB_HOST'] ?? 'localhost';
      $port   = $_ENV['DB_PORT'] ?? null;
      $dbname = $_ENV['DB_NAME'] ?? '';
      $user   = $_ENV['DB_USER'] ?? '';
      $pass   = $_ENV['DB_PASS'] ?? '';
      $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

      try {

        $dsn = self::buildDsn($driver, $host, $port, $dbname, $charset);

        self::$instance = new PDO($dsn, $user, $pass, [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        error_log("Database connection established successfully");
        fwrite(STDERR, "Database connection established successfully\n");
      } catch (PDOException $e) {
        fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed: " . $e->getMessage());
      }
    }

    return self::$instance;
  }

  /**
   * Build the DSN string based on the driver and connection parameters
   *
   * @param string $driver
   * @param string $host
   * @param string|null $port
   * @param string $dbname
   * @param string $charset
   * @return string
   * @throws \Exception
   */
  private static function buildDsn(
    string $driver,
    string $host,
    ?string $port,
    string $dbname,
    string $charset
  ): string {

    switch ($driver) {
      case 'mysql':
        return "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

      case 'pgsql':
        return "pgsql:host=$host;port=$port;dbname=$dbname";

      case 'sqlite':
        return "sqlite:$dbname";

      case 'sqlsrv':
        return "sqlsrv:Server=$host,$port;Database=$dbname";

      default:
        throw new \Exception("Unsupported driver: $driver");
    }
  }
}
