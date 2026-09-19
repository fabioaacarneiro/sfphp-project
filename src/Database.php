<?php

namespace SfphpProject\src;

use PDO;
use PDOException;
use RuntimeException;

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
   * @throws RuntimeException If the connection cannot be established
   */
  public static function connect(): PDO
  {
    if (!self::$instance) {
      /*
       * The .env file is already loaded by app/config/config.php, which runs
       * from Composer's autoloader before any application code.
       */
      $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
      $customDsn = $_ENV['DB_DSN'] ?? null;
      $host   = $_ENV['DB_HOST'] ?? 'localhost';
      $port   = $_ENV['DB_PORT'] ?? null;
      $dbname = $_ENV['DB_NAME'] ?? '';
      $user   = $_ENV['DB_USER'] ?? '';
      $pass   = $_ENV['DB_PASS'] ?? '';
      $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

      try {
        $dsn = $customDsn ?: self::buildDsn(
          $driver,
          $host,
          $port,
          $dbname,
          $charset
        );

        self::$instance = new PDO($dsn, $user, $pass, [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES => false,
        ]);
      } catch (PDOException $e) {
        /*
         * The driver message carries the host, database name and user. It goes
         * to the log only; the exception raised here is deliberately generic
         * and NOT chained to $e, because PHP prints a chained exception's
         * message as part of an uncaught trace, which would put those details
         * back in front of the visitor whenever display_errors is on.
         */
        error_log("Database connection failed: " . $e->getMessage());

        throw new RuntimeException("Database connection failed.");
      }
    }

    return self::$instance;
  }

  /**
   * Start a query builder for a table.
   *
   * @param string $table
   * @return QueryBuilder
   */
  public static function table(string $table): QueryBuilder
  {
    return (new QueryBuilder(self::connect()))->from($table);
  }

  /**
   * Prepare a raw SQL query with bound values.
   *
   * @param string $sql
   * @param array $bindings
   * @return RawQuery
   */
  public static function query(string $sql, array $bindings = []): RawQuery
  {
    return new RawQuery(self::connect(), $sql, $bindings);
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

      case 'oci':
        return "oci:dbname=//$host:$port/$dbname;charset=$charset";

      case 'firebird':
        return "firebird:dbname=$host/$port:$dbname;charset=$charset";

      case 'dblib':
        return "dblib:host=$host:$port;dbname=$dbname";

      default:
        throw new \Exception(
          "Unsupported driver: $driver. Set DB_DSN for custom PDO drivers."
        );
    }
  }
}
