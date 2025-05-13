<?php
	
	namespace Quellabs\ObjectQuel\DatabaseAdapter;
	
	use Cake\Database\Schema\Collection;
	use Cake\Database\StatementInterface;
	use Cake\Datasource\ConnectionInterface;
	use Cake\Datasource\ConnectionManager;
	use Phinx\Db\Adapter\AdapterInterface;
	use Quellabs\ObjectQuel\Configuration;
	use Phinx\Db\Adapter\AdapterFactory;
	
	/**
	 * Database adapter that ties ObjectQuel and Cakephp/Database together
	 */
	class DatabaseAdapter {
		
		protected Configuration $configuration;
		protected ConnectionInterface $connection;
		protected array $descriptions;
		protected array $columns_ex_descriptions;
		protected int $last_error;
		protected string $last_error_message;
		protected int $transaction_depth;
		protected array $indexes;
		
		/**
		 * Database Adapter constructor.
		 * This file wraps the functions of CakePHP Database
		 * @param Configuration $configuration
		 */
		public function __construct(Configuration $configuration) {
			// Store configuration object
			$this->configuration = $configuration;

			// setup ORM
			$this->descriptions = [];
			$this->columns_ex_descriptions = [];
			$this->indexes = [];
			$this->last_error = 0;
			$this->last_error_message = '';
			$this->transaction_depth = 0;

			// Check if connection already exists and drop it if needed
			if (ConnectionManager::getConfig('default')) {
				ConnectionManager::drop('default');
			}
			
			// Create the database connection
			ConnectionManager::setConfig('default', ['url' => $configuration->getDsn()]);
			$this->connection = ConnectionManager::get('default');
		}
		
		/**
		 * Returns the CakePHP connection
		 * @return ConnectionInterface
		 */
		public function getConnection(): ConnectionInterface {
			return $this->connection;
		}
		
		/**
		 * Returns the last occurred error
		 * @return int
		 */
		public function getLastError(): int {
			return $this->last_error;
		}
		
		/**
		 * Returns the last occurred error message
		 * @return string
		 */
		public function getLastErrorMessage(): string {
			return $this->last_error_message;
		}
		
		/**
		 * Execute a query
		 * @param string $query
		 * @param array $parameters Parameters for prepared statements
		 * @return StatementInterface|false
		 */
		public function execute(string $query, array $parameters = []): StatementInterface|false {
			try {
				return $this->connection->execute($query, $parameters);
			} catch (\Exception $exception) {
				$this->last_error = $exception->getCode();
				$this->last_error_message = $exception->getMessage();
				return false;
			}
		}
		
		/**
		 * Retrieves and formats column definitions from the database table
		 * @param string $tableName Name of the table to analyze
		 * @return array Associative array of column definitions indexed by column name
		 */
		public function getColumns(string $tableName): array {
			$result = [];
			
			// Fetch the Phinx adapter
			$phinxAdapter = $this->getPhinxAdapter();
			
			// Fetch the type mapper
			$typeMapper = new TypeMapper();
			
			// Get primary key columns first so we can mark them in column definitions
			$primaryKey = $phinxAdapter->getPrimaryKey($tableName);
			
			// Keep a list of decimal types for precision/scale inclusion
			// Phinx seems to sometimes return precision for integer fields which is incorrect
			$decimalTypes = ['decimal', 'numeric', 'float', 'double'];
			
			// Fetch and process each column in the table
			foreach ($phinxAdapter->getColumns($tableName) as $column) {
				$columnType = $column->getType();
				$isOfDecimalType = in_array(strtolower($columnType), $decimalTypes);
				
				$result[$column->getName()] = [
					// Basic column type (integer, string, decimal, etc.)
					'type'           => $columnType,
					
					// PHP type of this column
					'php_type'       => $typeMapper->phinxTypeToPhpType($columnType),
					
					// Maximum length for string types or display width for numeric types
					'limit'          => $column->getLimit(),
					
					// Default value for the column if not specified during insert
					'default'        => $column->getDefault(),
					
					// Whether NULL values are allowed in this column
					'nullable'       => $column->getNull(),
					
					// For numeric types: total number of digits (precision)
					'precision'      => $isOfDecimalType ? $column->getPrecision() : null,
					
					// For decimal types: number of digits after decimal point
					'scale'          => $isOfDecimalType ? $column->getScale() : null,
					
					// Whether column allows negative values (converted from signed to unsigned)
					'unsigned'       => !$column->getSigned(),
					
					// For generated columns (computed values based on expressions)
					'generated'      => $column->getGenerated(),
					
					// Whether column auto-increments (typically for primary keys)
					'identity'       => $column->getIdentity(),
					
					// Whether this column is part of the primary key
					'primary_key'    => in_array($column->getName(), $primaryKey["columns"]),
				];
			}
			
			return $result;
		}
		
		/**
		 * Returns the name of the primary key column
		 * @param string $tableName
		 * @return string
		 */
		public function getPrimaryKey(string $tableName): string {
			return $this->getOne("
                SELECT
                    `COLUMN_NAME`
                FROM `INFORMATION_SCHEMA`.`COLUMNS`
                WHERE `table_schema` IN(SELECT DATABASE()) AND
                      `table_name`=:tableName AND
                      `column_key`='PRI'
            ", [
				'tableName' => $tableName
			]);
		}
		
		/**
		 * Fetch a list of tables
		 * @return array
		 */
		public function getTables(): array {
			$schemaCollection = $this->getSchemaCollection();
			return $schemaCollection->listTablesWithoutViews();
		}
		
		/**
		 * Returns the max allowed package size
		 * @url https://stackoverflow.com/questions/5688403/how-to-check-and-set-max-allowed-packet-mysql-variable
		 * @return int
		 */
		public function getMaxPackageSize(): int {
			// Voer de query uit om de max_allowed_packet waarde op te halen
			$rs = $this->execute("SHOW VARIABLES LIKE 'max_allowed_packet'");
			
			// Als de query succesvol is en er is ten minste één record
			if ($rs) {
				$row = $rs->fetch('assoc');
				
				// Als de "Value" kolom bestaat, retourneer de waarde
				if (isset($row["Value"])) {
					return (int)$row["Value"];
				}
			}
			
			// Retourneer de standaardwaarde als de query mislukt of de "Value" kolom niet bestaat
			return 16777216;  // default value
		}
		
		/**
		 * Haalt indexinformatie op voor een gegeven tabel.
		 * @param string $tableName Naam van de tabel waarvoor indexinformatie nodig is.
		 * @return array Een array met indexinformatie voor de opgegeven tabel.
		 */
		public function getIndexes(string $tableName): array {
			// Controleer of de indexinformatie al eerder opgehaald en opgeslagen is
			if (!isset($this->indexes[$tableName])) {
				// Voer de SQL-query uit om indexinformatie van de tabel te krijgen
				$rs = $this->execute("SHOW INDEXES FROM `{$tableName}`");
				
				// Controleer of de query succesvol was en of er resultaten zijn
				if (!$rs) {
					// Geen resultaten, retourneer een lege array
					return [];
				}
				
				// Bereid de array voor om de indexinformatie op te slaan
				$this->indexes[$tableName] = [];
				
				// Verwerk elk resultaat en sla de indexgegevens op in de array
				while ($row = $rs->fetch('assoc')) {
					$this->indexes[$tableName][] = [
						'key'           => $row["Key_name"],        // Naam van de key
						'column'        => $row["Column_name"],     // Naam van de kolom
						'type'          => $row["Index_type"],      // Type van de index
						'seq_in_index'  => $row["Seq_in_index"],    // Volgorde van de kolom in de index
						'unique'        => !$row["Non_unique"],     // Geeft aan of de index uniek is
						'nullable'      => $row["Null"],            // Geeft aan of de kolom null-waarden kan hebben
						'cardinality'   => $row["Cardinality"],     // Het aantal unieke waarden in de index. Hoger is beter
						'collation'     => $row["Collation"],       // Collatie van de index
						'comment'       => $row["Comment"],         // Commentaar bij de index
						'index_comment' => $row["Index_comment"],   // Algemeen commentaar bij de index
					];
				}
			}
			
			// Retourneer de opgeslagen indexinformatie voor de opgegeven tabel
			return $this->indexes[$tableName];
		}
		
		/**
		 * Begin a new transaction.
		 * @return void
		 */
		public function beginTrans(): void {
			if ($this->transaction_depth == 0) {
				$this->connection->begin();
			}
			
			$this->transaction_depth++;
		}
		
		/**
		 * Commit the current transaction.
		 * @return void
		 */
		public function commitTrans(): void {
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->commit();
			}
		}
		
		/**
		 * Rollback the current transaction.
		 * @return void
		 */
		public function rollbackTrans(): void {
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->rollback();
			}
		}
		
		/**
		 * Fetches a single value from the database using the provided query and parameters
		 * @param string $query      The SQL query to execute
		 * @param array $parameters  Optional array of parameters to bind to the query
		 * @return mixed            Returns the first column of the first row if found, false if no results
		 */
		public function getOne(string $query, array $parameters=[]): mixed {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return false if no recordset returned
			if (!$rs) {
				return false;
			}
			
			// Fetch the first row
			$row = $rs->fetch('assoc');
			
			// Return false if no row found
			if (empty($row)) {
				return false;
			}
			
			// Return the first column value from the row
			return reset($row);
		}
		
		/**
		 * Fetches a single row from the database using the provided query and parameters
		 * @param string $query      The SQL query to execute
		 * @param array $parameters  Optional array of parameters to bind to the query
		 * @return array             Returns the first row as an associative array if found, empty array if no results
		 */
		public function getRow(string $query, array $parameters=[]): array {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return an empty array if no recordset returned
			if (!$rs) {
				return [];
			}
			
			// Return first row from recordset as an array
			$row = $rs->fetch('assoc');
			return $row ?: [];
		}
		
		/**
		 * Fetches a column from the database using the provided query and parameters
		 * @param string $query      The SQL query to execute
		 * @param array $parameters  Optional array of parameters to bind to the query
		 * @return array             Returns the values from the first column as an array
		 */
		public function getCol(string $query, array $parameters=[]): array {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return an empty array if no recordset returned
			if (!$rs) {
				return [];
			}
			
			// Fetch all rows and extract the first column
			$result = [];
			$firstCol = null;
			
			while ($row = $rs->fetch('assoc')) {
				if ($firstCol === null) {
					$keys = array_keys($row);
					$firstCol = $keys[0];
				}
				$result[] = $row[$firstCol];
			}
			
			return $result;
		}
		
		/**
		 * Fetches all rows from the database using the provided query and parameters
		 * @param string $query      The SQL query to execute
		 * @param array $parameters  Optional array of parameters to bind to the query
		 * @return array             Returns all rows as an array of associative arrays
		 */
		public function getAll(string $query, array $parameters=[]): array {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return an empty array if no recordset returned
			if (!$rs) {
				return [];
			}
			
			// Fetch all rows
			$result = [];
			while ($row = $rs->fetch('assoc')) {
				$result[] = $row;
			}
			
			return $result;
		}
		
		/**
		 * Haalt de maximale waarde van prepared statements op die toegestaan zijn in de MySQL-database.
		 * @return int De maximale hoeveelheid prepared statements die toegestaan zijn.
		 */
		public function getMaxPreparedStatementCount(): int {
			// Uitvoeren van de query om de systeemvariabele 'max_prepared_stmt_count' op te halen
			$rs = $this->execute("SHOW VARIABLES LIKE 'max_prepared_stmt_count'");
			
			// Controleer of de query succesvol was, zo niet, retourneer standaardwaarde
			if (!$rs) {
				return 16382;
			}
			
			// Ophalen van het resultaat van de query
			$row = $rs->fetch('assoc');
			
			// De opgehaalde waarde retourneren als een integer
			return isset($row['Value']) ? (int)$row['Value'] : 16382;
		}
		
		/**
		 * Retourneert 'true' als de table is gevuld met data, 'false' als dat niet zo is.
		 * @param string $tableName
		 * @return bool
		 */
		public function isPopulated(string $tableName): bool {
			$rs = $this->execute("
				SELECT
					COUNT(*) as c
				FROM `{$tableName}`
			");
			
			if (!$rs) {
				return false;
			}
			
			$row = $rs->fetch('assoc');
			return isset($row['c']) && $row['c'] > 0;
		}
		
		/**
		 * Returns a table's foreign key information
		 * @param string $tableName
		 * @return array
		 */
		public function getForeignKeys(string $tableName): array {
			return $this->getAll("
				SELECT
					COLUMN_NAME,
					CONSTRAINT_NAME,
					REFERENCED_TABLE_NAME,
					REFERENCED_COLUMN_NAME
				FROM
					INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				WHERE
				    TABLE_SCHEMA = DATABASE() AND
					TABLE_NAME = :tableName AND
					REFERENCED_TABLE_NAME IS NOT NULL;
			", [
				'tableName' => $tableName
			]);
		}
		
		/**
		 * Returns the insert id
		 * @return int|string|false
		 */
		public function getInsertId(): int|string|false {
			return $this->connection->getDriver()->lastInsertId();
		}
		
		/**
		 * Returns the schema collection of this connection
		 * @return Collection
		 */
		public function getSchemaCollection(): Collection {
			return $this->connection->getSchemaCollection();
		}
		
		/**
		 * Get a Phinx adapter instance using CakePHP's database connection
		 * @return \Phinx\Db\Adapter\AdapterInterface
		 */
		public function getPhinxAdapter(): AdapterInterface {
			// Get the CakePHP connection
			$connection = ConnectionManager::get('default');
			
			// Get the CakePHP connection config
			$config = $connection->config();

			// Map CakePHP driver to Phinx adapter name
			$driverMap = [
				'Cake\Database\Driver\Mysql' => 'mysql',
				'Cake\Database\Driver\Postgres' => 'pgsql',
				'Cake\Database\Driver\Sqlite' => 'sqlite',
				'Cake\Database\Driver\Sqlserver' => 'sqlsrv'
			];

			// Get the appropriate adapter name
			$adapter = $driverMap[$config['driver']] ?? 'mysql';

			// Convert CakePHP connection config to Phinx format
			$phinxConfig = [
				'adapter' => $adapter,
				'host'    => $config['host'] ?? 'localhost',
				'name'    => $config['database'],
				'user'    => $config['username'],
				'pass'    => $config['password'],
				'port'    => $config['port'] ?? 3306,
				'charset' => $config['encoding'] ?? 'utf8mb4',
			];
			
			// Create and return the adapter
			return AdapterFactory::instance()->getAdapter($phinxConfig['adapter'], $phinxConfig);
		}
	}