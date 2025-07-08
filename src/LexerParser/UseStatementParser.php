<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	/**
	 * Class UseStatementParser
	 * Parses PHP use statements from a class using reflection
	 */
	class UseStatementParser {
		
		/**
		 * @var array<string, array<string, string>> Cache of imports by class
		 */
		private array $importsCache = [];
		
		/**
		 * Parse use statements from a class file using a direct regex approach
		 * @param \ReflectionClass $class
		 * @return array<string, string> Map of aliases to fully qualified class names
		 */
		private function parseUseStatements(\ReflectionClass $class): array {
			// Skip for classes defined in PHP core
			if ($class->isInternal()) {
				return [];
			}
			
			// Skip if the file doesn't exist
			$filename = $class->getFileName();
			
			if ($filename === false || !file_exists($filename)) {
				return [];
			}
			
			// Read file content
			$content = file_get_contents($filename);
			
			if ($content === false) {
				return [];
			}
			
			$imports = [];
			
			// Match all use statements in the file
			// This pattern matches: use SomeNamespace\SomeClass as Alias;
			// And also: use SomeNamespace\SomeClass;
			$pattern = '/^\s*use\s+([^;]+);/m';
			
			if (preg_match_all($pattern, $content, $matches)) {
				foreach ($matches[1] as $useStatement) {
					$useStatement = trim($useStatement);
					
					// Handle alias (use X as Y)
					if (str_contains($useStatement, ' as ')) {
						list($className, $alias) = explode(' as ', $useStatement, 2);
						$className = trim($className);
						$alias = trim($alias);
						$imports[$alias] = $className;
					} else {
						// Regular use statement (use X)
						$className = trim($useStatement);
						$shortName = $this->getShortClassName($className);
						$imports[$shortName] = $className;
					}
				}
			}
			
			return $imports;
		}
		
		/**
		 * Get the short class name from a fully qualified class name
		 * @param string $className Fully qualified class name
		 * @return string Short class name
		 */
		private function getShortClassName(string $className): string {
			// Remove any leading backslash
			$className = ltrim($className, '\\');
			
			// Handle an empty case
			if (empty($className)) {
				return '';
			}
			
			$parts = explode('\\', $className);
			return end($parts);
		}
		
		/**
		 * Get all imported class aliases from use statements in the given class
		 * @param \ReflectionClass $class
		 * @return array<string, string> Map of aliases to fully qualified class names
		 */
		public function getImportsForClass(\ReflectionClass $class): array {
			$className = $class->getName();
			
			// Return cached result if available
			if (isset($this->importsCache[$className])) {
				return $this->importsCache[$className];
			}
			
			// Get namespace and imports
			$imports = $this->parseUseStatements($class);
			
			// Cache and return
			$this->importsCache[$className] = $imports;
			return $imports;
		}
	}