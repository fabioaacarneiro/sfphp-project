<?php

namespace SfphpProject\src;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

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
   * Nesting depth of the transactions currently open.
   */
  private static int $transactions = 0;

  /**
   * Run a callback inside a transaction.
   *
   * Commits when the callback returns and rolls back when it throws, then
   * re-throws so the failure is not swallowed. The callback's return value is
   * passed through.
   *
   *   Database::transaction(function (): void {
   *       $order = Order::create([...]);
   *       foreach ($items as $item) {
   *           OrderItem::create(['order_id' => $order->id, ...]);
   *       }
   *   });
   *
   * Until now nothing in the framework offered this. The only transaction
   * handling lived in a private method inside the migration runner, so an
   * application had to reach past the abstraction with Database::connect() and
   * drive the PDO handle itself. That was possible, but it meant re-writing
   * the same begin/commit/rollback in every project — including the part that
   * is easy to miss, that a failed statement can leave the driver with no
   * active transaction, so a bare rollBack() in the catch block throws and
   * hides the error that actually caused the failure.
   *
   * A nested call JOINS the transaction already open rather than starting a
   * second one, because PDO has no nested transactions. The consequence is
   * worth knowing: a failure inside the inner callback rolls back the outer
   * work too. Savepoints would avoid that, but their syntax differs between
   * drivers, and silently degrading on the ones that lack them would be worse
   * than being explicit about this.
   *
   * @template T
   * @param callable(): T $callback The work to run
   * @return T The callback's return value
   * @throws Throwable Whatever the callback threw, after rolling back
   */
  public static function transaction(callable $callback): mixed
  {
    $pdo = self::connect();

    if (self::$transactions > 0) {
      self::$transactions++;

      try {
        return $callback();
      } finally {
        self::$transactions--;
      }
    }

    $pdo->beginTransaction();
    self::$transactions = 1;

    try {
      $result = $callback();
      $pdo->commit();

      return $result;
    } catch (Throwable $throwable) {
      /*
       * A failed statement can leave the driver with no active transaction,
       * in which case rollBack() would throw and mask the real error.
       */
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      throw $throwable;
    } finally {
      self::$transactions = 0;
    }
  }

  /**
   * Check whether a transaction opened through transaction() is active.
   *
   * @return bool True while inside a transaction
   */
  public static function inTransaction(): bool
  {
    return self::$transactions > 0;
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
