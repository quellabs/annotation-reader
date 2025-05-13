<?php
	
	namespace Quellabs\ObjectQuel\CommandRunner\Commands;
	
	/**
	 * Import required classes for entity management and console interaction
	 */
	
	use Phinx\Db\Adapter\AdapterInterface;
	use Quellabs\ObjectQuel\CommandRunner\Command;
	use Quellabs\ObjectQuel\CommandRunner\ConsoleInput;
	use Quellabs\ObjectQuel\CommandRunner\ConsoleOutput;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\TableInfo;
	
	/**
	 * MakeEntityFromTableCommand - CLI command for creating or updating entity classes
	 *
	 * This command allows users to interactively create or update entity classes
	 * through a command-line interface, collecting properties with their types
	 * and constraints, including relationship definitions with primary key selection.
	 */
	class MakeEntityFromTableCommand extends Command {
		private DatabaseAdapter $connection;
		private AdapterInterface $phinxAdapter;
		private string $entityNamespace;
		
		/**
		 * Constructor
		 * @param ConsoleInput $input Command line input interface
		 * @param ConsoleOutput $output Command line output interface
		 * @param Configuration $configuration Application configuration
		 */
		public function __construct(
			ConsoleInput  $input,
			ConsoleOutput $output,
			Configuration $configuration
		) {
			parent::__construct($input, $output, $configuration);
			$this->connection = new DatabaseAdapter($configuration);
			$this->phinxAdapter = $this->connection->getPhinxAdapter();
			$this->entityNamespace = $configuration->getEntityNameSpace();
		}
		
		/**
		 * Convert a string to camelcase
		 * @param string $input
		 * @param string $separator
		 * @return string
		 */
		private function camelCase(string $input, string $separator = '_'): string {
			$array = explode($separator, $input);
			$parts = array_map('ucfirst', $array);
			return implode('', $parts);
		}
		
		/**
		 * Generate the namespace section of the entity class
		 * @return string
		 */
		private function generateNamespace(): string {
			return "    namespace {$this->entityNamespace};\n\n";
		}
		
		/**
		 * Generate the imports section of the entity class
		 * @return string The imports code
		 */
		private function generateImports(): string {
			$output = "";
			$output .= "    use Quellabs\\ObjectQuel\\Annotations\Orm\Table;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Annotations\Orm\Column;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Annotations\Orm\PrimaryKeyStrategy;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Annotations\Orm\OneToOne;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Annotations\Orm\OneToMany;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Annotations\Orm\ManyToOne;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Collections\\Collection;\n";
			$output .= "    use Quellabs\\ObjectQuel\\Collections\\CollectionInterface;\n";
			$output .= "\n";
			
			return $output;
		}
		
		/**
		 * Generate the class docblock with ORM annotations
		 * @param string $table The table name
		 * @param string $tableCamelCase The camelCase version of the table name
		 * @return string The class docblock
		 */
		private function generateClassDocBlock(string $table, string $tableCamelCase): string {
			$output = "";
			$output .= "    /**\n";
			$output .= "     * Class {$tableCamelCase}Entity\n";
			$output .= "     * @package {$this->entityNamespace}\n";
			$output .= "     * @Orm\Table(name=\"{$table}\")\n";
			$output .= "     */\n";
			
			return $output;
		}
		
		/**
		 * Prompt the user to select a database table
		 * @return string The selected table name
		 */
		private function promptForTable(): string {
			return $this->input->choice(
				"Select a database table to generate an entity class from:",
				$this->connection->getTables()
			);
		}
		
		/**
		 * Generate the entity class code (the content between class braces)
		 * @param string $table The table name
		 * @param string $tableCamelCase The camelCase version of the table name
		 * @param array $tableDescription The table description
		 * @return string The generated entity class code
		 */
		private function generateEntityCode(string $table, string $tableCamelCase, array $tableDescription): string {
			$output = "    class {$tableCamelCase}Entity {\n";
			$output .= $this->generateMemberVariables($tableDescription);
			$output .= $this->generateGettersAndSetters($tableDescription, $tableCamelCase);
			$output .= "    }\n"; // Class closing brace
			
			return $output;
		}
		
		/**
		 * Generate the member variables for the entity class
		 * @param array $tableDescription The table description
		 * @return string The generated member variables code
		 */
		private function generateMemberVariables(array $tableDescription): string {
			$output = "";
			
			foreach($tableDescription as $columnName => $column) {
				$columnCamelCase = lcfirst($this->camelCase($columnName));
				$acceptType = $this->getColumnType($column);
				
				$output .= "        /**\n";
				$output .= "         * @Orm\Column(name=\"{$columnName}\", type=\"{$column["type"]}\"";
				$output .= $this->getColumnAnnotationDetails($column);
				$output .= ")\n";
				
				if ($column["primary_key"] && $column["identity"]) {
					$output .= "         * @Orm\PrimaryKeyStrategy(strategy=\"identity\")\n";
				}

				$output .= "         */\n";
				$output .= "        private {$acceptType} \${$columnCamelCase}";
				$output .= $this->getColumnDefaultValue($column);
				$output .= ";\n\n";
			}
			
			return $output;
		}
		
		/**
		 * Get the column type for PHP
		 * @param array $column The column description
		 * @return string The PHP type for the column
		 */
		private function getColumnType(array $column): string {
			if ($column["nullable"] && $column["php_type"] !== 'mixed') {
				return "?{$column["php_type"]}";
			} else {
				return $column["php_type"];
			}
		}
		
		/**
		 * Get the column annotation details
		 * @param array $column The column description
		 * @return string The column annotation details
		 */
		private function getColumnAnnotationDetails(array $column): string {
			// Initialize an empty array to collect annotation details
			$details = [];
			
			// Add limit annotation if specified
			if (!empty($column["limit"])) {
				if (is_numeric($column["limit"])) {
					// For numeric limits, don't use quotes
					$details[] = "limit={$column["limit"]}";
				} else {
					// For non-numeric limits, use quotes
					$details[] = "limit=\"{$column["limit"]}\"";
				}
			}
			
			// Add nullable annotation if the column is nullable
			if ($column["nullable"]) {
				$details[] = "nullable=true";
			}
			
			// Add primary key annotation if the column is a primary key
			if ($column["primary_key"]) {
				$details[] = "primary_key=true";
			}
			
			// Add default value annotation if specified
			if (!empty($column["default"])) {
				$details[] = "default=\"{$column["default"]}\"";
			}
			
			// Add precision annotation for decimal/numeric columns if specified
			if (!empty($column["precision"])) {
				$details[] = "precision={$column["precision"]}";
			}
			
			// Add scale annotation for decimal/numeric columns if specified
			if (!empty($column["scale"])) {
				$details[] = "scale={$column["scale"]}";
			}
			
			// Implode the array with comma separator and prepend a comma if details exist
			// If no details found, return an empty string
			return !empty($details) ? ", " . implode(", ", $details) : "";
		}
		
		/**
		 * Get the default value for a column
		 * @param array $column The column description
		 * @return string The default value expression
		 */
		private function getColumnDefaultValue(array $column): string {
			if (empty($column["default"])) {
				return "";
			}
			
			if (is_numeric($column["default"])) {
				return " = {$column["default"]}";
			} else {
				return " = \"{$column["default"]}\"";
			}
		}
		
		/**
		 * Generate the getters and setters for the entity class
		 * @param array $tableDescription The table description
		 * @param string $tableCamelCase The camelCase version of the table name
		 * @return string The generated getters and setters code
		 */
		private function generateGettersAndSetters(array $tableDescription, string $tableCamelCase): string {
			$output = "";
			
			foreach($tableDescription as $columnName => $column) {
				$fieldCamelCase = $this->camelCase($columnName);
				$variableCamelCase = lcfirst($fieldCamelCase);
				$acceptType = $this->getColumnType($column);
				
				$output .= $this->generateGetter($fieldCamelCase, $variableCamelCase, $acceptType);
				$output .= $this->generateSetter($column, $fieldCamelCase, $variableCamelCase, $acceptType, $tableCamelCase);
			}
			
			return $output;
		}
		
		/**
		 * Generate a getter method for a column
		 * @param string $fieldCamelCase The camelCase field name
		 * @param string $variableCamelCase The camelCase variable name
		 * @param string $acceptType The PHP type for the column
		 * @return string The generated getter method
		 */
		private function generateGetter(string $fieldCamelCase, string $variableCamelCase, string $acceptType): string {
			$output = "\n";
			
			if ($acceptType !== '') {
				$output .= "        public function get{$fieldCamelCase}() : {$acceptType} {\n";
			} else {
				$output .= "        public function get{$fieldCamelCase}() {\n";
			}
			
			$output .= "            return \$this->{$variableCamelCase};\n";
			$output .= "        }\n";
			
			return $output;
		}
		
		/**
		 * Generate a setter method for a column
		 * @param array $column The column description
		 * @param string $fieldCamelCase The camelCase field name
		 * @param string $variableCamelCase The camelCase variable name
		 * @param string $acceptType The PHP type for the column
		 * @param string $tableCamelCase The camelCase table name
		 * @return string The generated setter method
		 */
		private function generateSetter(array $column, string $fieldCamelCase, string $variableCamelCase, string $acceptType, string $tableCamelCase): string {
			$output = "\n";
			
			// Only generate setters for non-autoincrement primary keys
			if (!$column["primary_key"] || !$column["identity"]) {
				$output .= "        public function set{$fieldCamelCase}({$acceptType} \$value): {$tableCamelCase}Entity {\n";
				$output .= "            \$this->{$variableCamelCase} = \$value;\n";
				$output .= "            return \$this;\n";
				$output .= "        }\n";
			}
			
			return $output;
		}
		
		/**
		 * Save the entity file to disk
		 * @param string $tableCamelCase The camelCase version of the table name
		 * @param string $entityCode The generated entity class code
		 * @return void
		 */
		private function saveEntityFile(string $tableCamelCase, string $entityCode): void {
			$path = $this->configuration->getEntityPath();
			$filename = "{$path}/{$tableCamelCase}Entity.php";
			file_put_contents($filename, $entityCode);
		}
		
		/**
		 * Execute the command
		 * @param array $parameters Optional parameters passed to the command
		 * @return int Exit code (0 for success)
		 */
		public function execute(array $parameters = []): int {
			// Prompt the user to select which database table they would like to create a new entity for
			$table = $this->promptForTable();
			
			if (empty($table)) {
				return 0;
			}
			
			// Extract all necessary data from the table
			$tableCamelCase = $this->camelCase($table);
			$tableDescription = $this->connection->getColumns($table);
			
			if (empty($tableDescription)) {
				$this->output->writeLn("Could not extract table description for {$table}.");
				return 0;
			}
			
			// Generate namespace and imports
			$entityCode = "<?php\n";
			$entityCode .= $this->generateNamespace();
			$entityCode .= $this->generateImports();
			$entityCode .= $this->generateClassDocBlock($table, $tableCamelCase);
			$entityCode .= $this->generateEntityCode($table, $tableCamelCase, $tableDescription);
			
			// Store the file
			$this->saveEntityFile($tableCamelCase, $entityCode);
			
			// Output message
			$this->output->writeLn("Entity class {$tableCamelCase}Entity successfully created.");
			return 0;
		}
		
		/**
		 * Get the command signature/name for registration in the CLI
		 * @return string Command signature
		 */
		public static function getSignature(): string {
			return "make:entity-from-table";
		}
		
		/**
		 * Get a short description of what the command does
		 * @return string Command description
		 */
		public static function getDescription(): string {
			return "Generate entity classes from existing database table structures";
		}
		
		/**
		 * Get detailed help information for the command
		 * @return string Command help text
		 */
		public static function getHelp(): string {
			return "Generates entity classes by mapping database tables to object-oriented entities.";
		}
	}