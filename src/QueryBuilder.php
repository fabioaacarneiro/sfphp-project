<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Builds and executes database-agnostic SQL queries through PDO.
 */
class QueryBuilder
{
    private const OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE',
    ];

    private string $table;
    private array $columns = ['*'];
    private array $joins = [];
    private array $conditions = [];
    private array $orders = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private int $bindingIndex = 0;
    private array $bindings = [];

    /**
     * Create a query builder for the provided PDO connection.
     *
     * @param PDO $pdo The database connection used to execute queries
     */
    public function __construct(private PDO $pdo) {}

    /**
     * Set the table used by the query.
     *
     * @param string $table The table name
     * @return self The current query builder
     * @throws InvalidArgumentException If the table name is not a valid identifier
     */
    public function from(string $table): self
    {
        $this->table = $this->quoteIdentifier($table);

        return $this;
    }

    /**
     * Set the columns returned by the query.
     *
     * @param string|array ...$columns Column names or an array of column names
     * @return self The current query builder
     * @throws InvalidArgumentException If no columns are provided or a column is invalid
     */
    public function select(string|array ...$columns): self
    {
        $columns = count($columns) === 1 && is_array($columns[0])
            ? $columns[0]
            : $columns;

        if ($columns === []) {
            throw new InvalidArgumentException('At least one column is required.');
        }

        $this->columns = array_map(
            fn (string $column): string => $this->quoteColumn($column),
            $columns
        );

        return $this;
    }

    /**
     * Add an AND condition to the query.
     *
     * @param string $column The column name
     * @param mixed $operatorOrValue The operator or value for the condition
     * @param mixed $value The value when an operator is provided
     * @return self The current query builder
     * @throws InvalidArgumentException If the operator or column is invalid
     */
    public function where(
        string $column,
        mixed $operatorOrValue,
        mixed $value = null
    ): self {
        if (func_num_args() === 2) {
            return $this->addWhere('AND', $column, '=', $operatorOrValue);
        }

        return $this->addWhere('AND', $column, (string) $operatorOrValue, $value);
    }

    /**
     * Add an OR condition to the query.
     *
     * @param string $column The column name
     * @param mixed $operatorOrValue The operator or value for the condition
     * @param mixed $value The value when an operator is provided
     * @return self The current query builder
     * @throws InvalidArgumentException If the operator or column is invalid
     */
    public function orWhere(
        string $column,
        mixed $operatorOrValue,
        mixed $value = null
    ): self {
        if (func_num_args() === 2) {
            return $this->addWhere('OR', $column, '=', $operatorOrValue);
        }

        return $this->addWhere('OR', $column, (string) $operatorOrValue, $value);
    }

    /**
     * Add an AND NULL condition to the query.
     *
     * @param string $column The column name
     * @return self The current query builder
     * @throws InvalidArgumentException If the column is invalid
     */
    public function whereNull(string $column): self
    {
        return $this->addCondition('AND', $this->quoteIdentifier($column) . ' IS NULL');
    }

    /**
     * Add an AND NOT NULL condition to the query.
     *
     * @param string $column The column name
     * @return self The current query builder
     * @throws InvalidArgumentException If the column is invalid
     */
    public function whereNotNull(string $column): self
    {
        return $this->addCondition('AND', $this->quoteIdentifier($column) . ' IS NOT NULL');
    }

    /**
     * Add an AND IN condition to the query.
     *
     * @param string $column The column name
     * @param array $values The values accepted by the condition
     * @return self The current query builder
     * @throws InvalidArgumentException If the column is invalid
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            return $this->addCondition('AND', '1 = 0');
        }

        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $this->bind($value);
        }

        return $this->addCondition(
            'AND',
            $this->quoteIdentifier($column) . ' IN (' . implode(', ', $placeholders) . ')'
        );
    }

    /**
     * Add an INNER or LEFT JOIN clause to the query.
     *
     * @param string $table The joined table name
     * @param string $first The first column in the join condition
     * @param string $operator The comparison operator
     * @param string $second The second column in the join condition
     * @param string $type The join type
     * @return self The current query builder
     * @throws InvalidArgumentException If an identifier, operator, or join type is invalid
     */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER'
    ): self {
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT'], true)) {
            throw new InvalidArgumentException('Join type must be INNER or LEFT.');
        }

        $operator = strtoupper($operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported join operator.');
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $type,
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($first),
            $operator,
            $this->quoteIdentifier($second)
        );

        return $this;
    }

    /**
     * Add an ORDER BY clause to the query.
     *
     * @param string $column The column name
     * @param string $direction The sort direction: ASC or DESC
     * @return self The current query builder
     * @throws InvalidArgumentException If the column or direction is invalid
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Order direction must be ASC or DESC.');
        }

        $this->orders[] = $this->quoteIdentifier($column) . ' ' . $direction;

        return $this;
    }

    /**
     * Limit the number of rows returned by the query.
     *
     * @param int $limit The maximum number of rows
     * @return self The current query builder
     * @throws InvalidArgumentException If the limit is negative
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Limit must be zero or greater.');
        }

        $this->limitValue = $limit;

        return $this;
    }

    /**
     * Skip rows before returning query results.
     *
     * @param int $offset The number of rows to skip
     * @return self The current query builder
     * @throws InvalidArgumentException If the offset is negative
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be zero or greater.');
        }

        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * Execute the query and return all matching rows.
     *
     * @return array The matching rows
     * @throws RuntimeException If the table or pagination is not supported
     */
    public function get(): array
    {
        return $this->executeSelect()->fetchAll();
    }

    /**
     * Execute the query and return the first matching row.
     *
     * @return array|null The first matching row, or null when none is found
     * @throws RuntimeException If the table or pagination is not supported
     */
    public function first(): ?array
    {
        $originalLimit = $this->limitValue;
        $this->limitValue = 1;

        try {
            $result = $this->executeSelect()->fetch();
        } finally {
            $this->limitValue = $originalLimit;
        }

        return $result === false ? null : $result;
    }

    /**
     * Count the rows the query matches.
     *
     * Runs SELECT COUNT(*) over the current table, joins and conditions.
     * Ordering and pagination are ignored: they do not change how many rows
     * match, and ORDER BY on an aggregate is rejected by some drivers.
     *
     * @return int The number of matching rows
     * @throws RuntimeException If no table was selected
     */
    public function count(): int
    {
        $originalColumns = $this->columns;
        $originalOrders = $this->orders;
        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        $this->columns = ['COUNT(*) AS ' . $this->quoteIdentifier('aggregate')];
        $this->orders = [];
        $this->limitValue = null;
        $this->offsetValue = null;

        try {
            $result = $this->executeSelect()->fetch();
        } finally {
            $this->columns = $originalColumns;
            $this->orders = $originalOrders;
            $this->limitValue = $originalLimit;
            $this->offsetValue = $originalOffset;
        }

        if ($result === false) {
            return 0;
        }

        $value = is_array($result) ? reset($result) : $result;

        return (int) $value;
    }

    /**
     * Insert a row and return its generated identifier.
     *
     * @param array $data The column values to insert
     * @return string The identifier generated by the database
     * @throws InvalidArgumentException If the data is empty or contains an invalid column
     * @throws RuntimeException If no table was selected
     */
    public function insert(array $data): string
    {
        if ($data === []) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $columns = array_keys($data);
        $placeholders = [];
        $bindings = [];
        foreach ($data as $value) {
            $placeholders[] = $this->bindValue($value, $bindings);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->getTable(),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', $placeholders)
        );

        $this->execute($sql, $bindings);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows matching the configured conditions.
     *
     * @param array $data The column values to update
     * @return int The number of affected rows
     * @throws InvalidArgumentException If the data is empty or contains an invalid column
     * @throws RuntimeException If no table was selected
     */
    public function update(array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Update data cannot be empty.');
        }

        $assignments = [];
        $bindings = $this->bindings();
        foreach ($data as $column => $value) {
            $assignments[] = $this->quoteIdentifier($column) . ' = '
                . $this->bindValue($value, $bindings);
        }

        $statement = $this->execute(
            'UPDATE ' . $this->getTable() . ' SET ' . implode(', ', $assignments)
            . $this->compileWhere(),
            $bindings
        );

        return $statement->rowCount();
    }

    /**
     * Delete rows matching the configured conditions.
     *
     * @return int The number of affected rows
     * @throws RuntimeException If no table was selected
     */
    public function delete(): int
    {
        $statement = $this->execute(
            'DELETE FROM ' . $this->getTable() . $this->compileWhere(),
            $this->bindings()
        );

        return $statement->rowCount();
    }

    /**
     * Build the SELECT SQL statement without executing it.
     *
     * @return string The generated SQL statement
     * @throws RuntimeException If the table or pagination is not supported
     */
    public function toSql(): string
    {
        $this->getTable();

        $select = 'SELECT';
        $driver = $this->driver();
        if ($this->limitValue !== null && $this->offsetValue === null) {
            if ($driver === 'sqlsrv' || $driver === 'dblib') {
                $select .= ' TOP (' . $this->limitValue . ')';
            } elseif ($driver === 'firebird') {
                $select .= ' FIRST ' . $this->limitValue;
            }
        }

        $sql = $select . ' ' . implode(', ', $this->columns)
            . ' FROM ' . $this->table;

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->compileWhere();

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        return $sql . $this->compilePagination($driver);
    }

    /**
     * Get the values bound to the generated SQL statement.
     *
     * @return array The bindings indexed by their named placeholders
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * Build a condition with a validated comparison operator.
     *
     * @param string $boolean The condition connector
     * @param string $column The column name
     * @param string $operator The comparison operator
     * @param mixed $value The value to bind
     * @return self The current query builder
     * @throws InvalidArgumentException If the operator or column is invalid
     */
    private function addWhere(
        string $boolean,
        string $column,
        string $operator,
        mixed $value
    ): self {
        $operator = strtoupper($operator);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported where operator.');
        }

        $quotedColumn = $this->quoteIdentifier($column);
        if ($value === null) {
            if ($operator === '=') {
                return $this->addCondition($boolean, $quotedColumn . ' IS NULL');
            }

            if ($operator === '!=' || $operator === '<>') {
                return $this->addCondition($boolean, $quotedColumn . ' IS NOT NULL');
            }

            throw new InvalidArgumentException('Null values only support =, !=, or <> operators.');
        }

        $placeholder = $this->bind($value);

        return $this->addCondition(
            $boolean,
            $quotedColumn . ' ' . $operator . ' ' . $placeholder
        );
    }

    /**
     * Add a compiled condition to the query.
     *
     * @param string $boolean The condition connector
     * @param string $sql The compiled condition SQL
     * @return self The current query builder
     */
    private function addCondition(string $boolean, string $sql): self
    {
        $this->conditions[] = [
            'boolean' => $boolean,
            'sql' => $sql,
        ];

        return $this;
    }

    /**
     * Bind a value and return its placeholder.
     *
     * @param mixed $value The value to bind
     * @return string The generated named placeholder
     */
    private function bind(mixed $value): string
    {
        return $this->bindValue($value, $this->bindings);
    }

    /**
     * Bind a value to an operation-specific collection of placeholders.
     *
     * @param mixed $value The value to bind
     * @param array $bindings The bindings updated by reference
     * @return string The generated named placeholder
     */
    private function bindValue(mixed $value, array &$bindings): string
    {
        $name = ':binding_' . $this->bindingIndex++;
        $bindings[$name] = $value;

        return $name;
    }

    /**
     * Execute the generated SELECT statement.
     *
     * @return PDOStatement The executed statement
     * @throws RuntimeException If the table or pagination is not supported
     */
    private function executeSelect(): PDOStatement
    {
        return $this->execute($this->toSql(), $this->bindings());
    }

    /**
     * Prepare and execute an SQL statement with query bindings.
     *
     * @param string $sql The SQL statement to execute
     * @param array $bindings The values bound to the statement
     * @return PDOStatement The executed statement
     */
    private function execute(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($bindings as $name => $value) {
            $statement->bindValue($name, $value, $this->pdoType($value));
        }
        $statement->execute();

        return $statement;
    }

    /**
     * Compile all configured WHERE conditions.
     *
     * @return string The WHERE clause, or an empty string when no conditions exist
     */
    private function compileWhere(): string
    {
        if ($this->conditions === []) {
            return '';
        }

        $sql = ' WHERE ';
        foreach ($this->conditions as $index => $condition) {
            $sql .= $index === 0
                ? $condition['sql']
                : ' ' . $condition['boolean'] . ' ' . $condition['sql'];
        }

        return $sql;
    }

    /**
     * Compile pagination syntax for the active PDO driver.
     *
     * @param string $driver The PDO driver name
     * @return string The pagination clause
     * @throws RuntimeException If the driver does not support the requested pagination
     */
    private function compilePagination(string $driver): string
    {
        if ($this->limitValue === null && $this->offsetValue === null) {
            return '';
        }

        $limit = $this->limitValue;
        $offset = $this->offsetValue;

        if ($driver === 'sqlsrv') {
            if ($offset === null) {
                return '';
            }

            if ($this->orders === []) {
                throw new RuntimeException('SQL Server requires orderBy() when using offset().');
            }

            $sql = ' OFFSET ' . ($offset ?? 0) . ' ROWS';
            return $limit === null ? $sql : $sql . ' FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        if ($driver === 'firebird') {
            if ($offset === null) {
                return '';
            }

            $first = $offset + 1;
            $last = $limit === null ? 2147483647 : $offset + $limit;

            return ' ROWS ' . $first . ' TO ' . $last;
        }

        if ($driver === 'dblib') {
            if ($offset !== null) {
                throw new RuntimeException('The dblib driver does not support offset(); use a raw query instead.');
            }

            return '';
        }

        if ($driver === 'oci') {
            $sql = $offset === null ? '' : ' OFFSET ' . $offset . ' ROWS';
            return $limit === null ? $sql : $sql . ' FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true) && $limit !== null) {
            $sql = ' LIMIT ' . $limit;
            return $offset === null ? $sql : $sql . ' OFFSET ' . $offset;
        }

        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            return ' OFFSET ' . $offset;
        }

        throw new RuntimeException(
            "Pagination is not supported by the $driver driver; use a raw query instead."
        );
    }

    /**
     * Quote a selected column and optional alias.
     *
     * @param string $column The column name, wildcard, or aliased column
     * @return string The quoted column expression
     * @throws InvalidArgumentException If the column is not a valid identifier
     */
    private function quoteColumn(string $column): string
    {
        if ($column === '*') {
            return $column;
        }

        if (preg_match('/^(.+)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $column, $matches)) {
            return $this->quoteIdentifier($matches[1])
                . ' AS ' . $this->quoteIdentifier($matches[2]);
        }

        return $this->quoteIdentifier($column);
    }

    /**
     * Validate and quote an SQL identifier for the active PDO driver.
     *
     * @param string $identifier The table or column identifier
     * @return string The quoted identifier
     * @throws InvalidArgumentException If the identifier is invalid
     */
    private function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        $quote = $this->identifierQuote();

        foreach ($parts as $index => $part) {
            if ($part === '*' && $index === count($parts) - 1) {
                continue;
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new InvalidArgumentException("Invalid SQL identifier: $identifier");
            }

            $parts[$index] = $quote['open'] . $part . $quote['close'];
        }

        return implode('.', $parts);
    }

    /**
     * Get the identifier delimiters for the active PDO driver.
     *
     * @return array The opening and closing identifier delimiters
     */
    private function identifierQuote(): array
    {
        return match ($this->driver()) {
            'mysql' => ['open' => '`', 'close' => '`'],
            'sqlsrv', 'dblib' => ['open' => '[', 'close' => ']'],
            default => ['open' => '"', 'close' => '"'],
        };
    }

    /**
     * Get the current PDO driver name.
     *
     * @return string The PDO driver name
     */
    private function driver(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Get the selected table name.
     *
     * @return string The quoted table name
     * @throws RuntimeException If no table was selected
     */
    private function getTable(): string
    {
        if (!isset($this->table)) {
            throw new RuntimeException('Call from() before building a query.');
        }

        return $this->table;
    }

    /**
     * Get the PDO binding type for a value.
     *
     * @param mixed $value The value to bind
     * @return int The matching PDO parameter type
     */
    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
