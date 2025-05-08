<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	
	class ProxyGenerator {
		
		protected EntityStore $entityStore;
		protected ReflectionHandler $reflectionHandler;
		protected AnnotationReader $annotationReader;
		protected string|bool $servicesPath;
		protected string|false $proxyPath;
		protected string|false $proxyNamespace;
		protected array $types;
		protected array $runtimeProxies = [];
		
		/**
		 * ProxyGenerator constructor
		 * @param EntityStore $entityStore
		 * @param Configuration $configuration
		 */
		public function __construct(EntityStore $entityStore, Configuration $configuration) {
			$this->entityStore = $entityStore;
			$this->reflectionHandler = $entityStore->getReflectionHandler();
			$this->annotationReader = $entityStore->getAnnotationReader();
			$this->servicesPath = realpath($configuration->getEntityPath());
			$this->proxyPath =  $configuration->getProxyDir() ? realpath($configuration->getProxyDir()) : false;
			$this->proxyNamespace = $configuration->getProxyNamespace() ?: 'Quellabs\\ObjectQuel\\Proxy\\Runtime';
			$this->types = ["int", "float", "bool", "string", "array", "object", "resource", "null", "callable", "iterable", "mixed", "false", "void", "static"];
			
			// Only initialize proxies if a proxy path is set
			if ($this->proxyPath !== false) {
				$this->initializeProxies();
			}
		}
		
		/**
		 * Deze functie initialiseert alle entiteiten in de "Entity"-directory.
		 * @return void
		 */
		private function initializeProxies(): void {
			$entityFiles = scandir($this->servicesPath);
			
			foreach ($entityFiles as $fileName) {
				// Controleer of het bestand een php bestand is. Zoniet, ga naar volgende bestand
				if (!$this->isPHPFile($fileName)) {
					continue;
				}
				
				// Controleer of het bestand een entity is. Zoniet, ga naar volgende bestand
				$entityName = $this->constructEntityName($fileName);
				
				if (!$this->isEntity($entityName)) {
					continue;
				}
				
				// Check of we moeten updaten
				if ($this->isOutdated($fileName)) {
					// Maak een lock file aan om race conditions te voorkomen
					$lockFile = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName . '.lock';
					$lockHandle = fopen($lockFile, 'c+');
					
					if ($lockHandle === false) {
						error_log("Kon geen lock file aanmaken voor entity: {$fileName}");
						continue;
					}
					
					try {
						if (flock($lockHandle, LOCK_EX)) {
							// Double-check of een ander proces het inmiddels niet al heeft gedaan
							if ($this->isOutdated($fileName)) {
								$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName;
								$proxyContents = $this->makeProxy($entityName);
								file_put_contents($proxyFilePath, $proxyContents);
							}
							
							flock($lockHandle, LOCK_UN);
						}
					} finally {
						fclose($lockHandle);
						@unlink($lockFile);
					}
				}
			}
		}
		
		/**
		 * Controleert of het opgegeven bestand een PHP-bestand is.
		 * @param string $fileName Naam van het bestand.
		 * @return bool True als het een PHP-bestand is, anders false.
		 */
		private function isPHPFile(string $fileName): bool {
			$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
			return ($fileExtension === 'php');
		}
		
		/**
		 * Checks if the entity is an ORM table
		 * @param string $entityName
		 * @return bool
		 */
		private function isEntity(string $entityName): bool {
			$annotations = $this->annotationReader->getClassAnnotations($entityName);
			return array_key_exists("Orm\\Table", $annotations);
		}
		
		/**
		 * Returns true if the file is outdated, false if not
		 * @param string $fileName
		 * @return bool
		 */
		private function isOutdated(string $fileName): bool {
			$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName;
			$entityFilePath = $this->servicesPath . DIRECTORY_SEPARATOR . $fileName;
			return !file_exists($proxyFilePath) || filemtime($entityFilePath) > filemtime($proxyFilePath);
		}
		
		/**
		 * Constructs the full entity name
		 * @param string $fileName
		 * @return string
		 */
		private function constructEntityName(string $fileName): string {
			return "Quellabs\\ObjectQuel\\Entity\\" . substr($fileName, 0, strpos($fileName, ".php"));
		}
		
		/**
		 * Returns the proxy template
		 * @return string
		 */
		protected function getTemplate(): string {
			return "
<?php
	namespace {$this->proxyNamespace};
	
	include_once('%s');
	
	%s
	class %s extends \%s implements \Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface {
		%s
	}
";
		}
		
		/**
		 * Haalt de klassenaam van een gegeven entiteit op, zonder de namespace.
		 * @param mixed $classNameWithNamespace De entiteit waarvan we de klassenaam willen ophalen.
		 * @return string De klassenaam zonder de namespace.
		 */
		protected function getClassNameWithoutNamespace(mixed $classNameWithNamespace): string {
			return ltrim(strrchr($classNameWithNamespace, '\\'), '\\');
		}
		
		/**
		 * Convert a type to its string representation.
		 * @param string $type      The type to convert.
		 * @param bool $nullable    Indicates if the type can be null.
		 * @return string The string representation of the type.
		 */
		protected function typeToString(string $type, bool $nullable): string {
			// Special case for type 'mixed'
			if ($type === 'mixed') {
				return "mixed";
			}
			
			// Check if the type is not empty.
			$result = "";

			if ($type !== "") {
				// Check if the type is in the predefined types list.
				// If not, prepend it with a backslash.
				if (!in_array($type, $this->types)) {
					$result = "\\{$type}";
				} else {
					$result = $type;
				}
				
				// If the type is nullable, prepend it with a question mark.
				if ($nullable && ($result !== 'mixed')) {
					$result = "?{$result}";
				}
			}
			
			return $result;
		}
		
		/**
		 * Maakt een stringrepresentatie van de methods van een gegeven entiteit,
		 * inclusief hun types, zichtbaarheid en documentatiecommentaar.
		 * @param mixed $entity De entiteit waarvan de eigenschappen worden opgehaald.
		 * @return string Een samengevoegde string die de eigenschappen van de entiteit beschrijft.
		 */
		protected function makeProxyMethods(mixed $entity): string {
			$result = [];
			
			// Haal identifier keys op
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			$identifierKeysGetterMethod = 'get' . ucfirst($identifierKeys[0]);
			$hasConstructor = $this->reflectionHandler->hasConstructor($entity);
			$constructorParentCode = $hasConstructor ? "parent::__construct();" : "";
			
			// Voeg de constructor toe en de lazy load functie toe
			$result[] = "
				private \$entityManager;
				private \$initialized;
				
				public function __construct(\\Quellabs\ObjectQuel\\EntityManager \$entityManager) {
					\$this->entityManager = \$entityManager;
					\$this->initialized = false;
					{$constructorParentCode}
				}
				
				protected function doInitialize() {
					\$this->entityManager->find(\\{$entity}::class, \$this->{$identifierKeysGetterMethod}());
					\$this->setInitialized();
				}

				public function isInitialized(): bool {
					return \$this->initialized;
				}

				public function setInitialized(): void {
					\$this->initialized = true;
				}
			";
			
			// Loop door alle methoden van het gegeven object om proxy-methoden te genereren.
			foreach ($this->reflectionHandler->getMethods($entity) as $method) {
				// Sla de constructor en primary key getter over
				if (in_array($method, ["__construct", $identifierKeysGetterMethod])) {
					continue;
				}
				
				// Sla private functies over
				$visibility = $this->reflectionHandler->getMethodVisibility($entity, $method);
				
				if ($visibility === "private") {
					continue;
				}
				
				// Verkrijg belangrijke informatie over de methode via reflectie.
				$returnType = $this->reflectionHandler->getMethodReturnType($entity, $method);
				$returnTypeNullable = $this->reflectionHandler->methodReturnTypeIsNullable($entity, $method);
				$docComment = $this->reflectionHandler->getMethodDocComment($entity, $method);
				
				// Initialiseer een array om de parameterlijst op te bouwen.
				$parameterList = [];
				$parameters = $this->reflectionHandler->getMethodParameters($entity, $method);
				
				// Loop door de parameters en bouw de lijst op.
				foreach ($parameters as $parameter) {
					$parameterType = $this->typeToString($parameter["type"], $parameter["nullable"]);
					
					if (!$parameter["has_default"]) {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}";
					} elseif ($parameter["default"] === null) {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}=NULL";
					} elseif ($parameterType == "string") {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}='{$parameter["default"]}'";
					} else {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}={$parameter["default"]}";
					}
				}
				
				// Maak de uiteindelijke parameterlijst en parameter naam lijst.
				$parameterString = implode(",", $parameterList);
				$parameterNamesString = implode(",", array_map(function ($e) { return "\${$e}";}, array_column($parameters, "name")));
				$returnTypeString = $this->typeToString($returnType, $returnTypeNullable);
				$returnTypeString = !empty($returnTypeString) ? ": {$returnTypeString}" : "";
				
				// Functies die void retourneren, hebben geen return-statement. Anders crasht de boel.
				if (str_contains($returnTypeString, "void")) {
					$returnStatement = "";
				} else {
					$returnStatement = "return ";
				}
				
				// Voeg de proxy methode toe aan de resultatenlijst.
				$result[] = "
					{$docComment}
					{$visibility} function {$method}({$parameterString}){$returnTypeString} {
						\$this->doInitialize();
						{$returnStatement}parent::{$method}({$parameterNamesString});
					}
		        ";
			}
			
			// Voeg alle gegenereerde proxy-methoden samen tot één string en retourneer deze.
			return implode("\n", $result);
		}
		
		/**
		 * Create the contents of the proxy file for the given entity
		 * @param $entity
		 * @return string
		 */
		private function makeProxy($entity): string {
			$class = is_object($entity) ? get_class($entity) : $entity;
			
			return trim(sprintf(
				$this->getTemplate(),
				$this->reflectionHandler->getFilename($class),
				$this->reflectionHandler->getDocComment($class),
				$this->getClassNameWithoutNamespace($class),
				$class,
				$this->makeProxyMethods($class),
			));
		}
		
		/**
		 * Generate or retrieve a proxy class for the given entity
		 * @param string $entityClass The fully qualified class name of the entity
		 * @return string The fully qualified class name of the proxy
		 */
		public function getProxyClass(string $entityClass): string {
			// If a proxy path is set, return the path-based proxy class name
			if ($this->proxyPath !== false) {
				$className = $this->getClassNameWithoutNamespace($entityClass);
				return $this->proxyNamespace . '\\' . $className;
			}
			
			// If we've already generated this proxy at runtime, return its class name
			if (isset($this->runtimeProxies[$entityClass])) {
				return $this->runtimeProxies[$entityClass];
			}
			
			// Generate proxy class at runtime
			return $this->generateRuntimeProxy($entityClass);
		}
		
		/**
		 * Generates a runtime proxy class for the given entity and returns its class name
		 * @param string $entityClass
		 * @return string The fully qualified class name of the generated proxy
		 */
		protected function generateRuntimeProxy(string $entityClass): string {
			// Generate a unique class name for the runtime proxy
			$className = $this->getClassNameWithoutNamespace($entityClass);
			$uniqueId = uniqid();
			$proxyClassName = $this->proxyNamespace . '\\' . $className . '_' . $uniqueId;
			
			// Generate the proxy class code
			$proxyContents = $this->makeProxy($entityClass);
			
			// Modify the namespace in the proxy content to match the runtime namespace
			$proxyContents = preg_replace(
				'/namespace\s+([^;]+);/',
				'namespace ' . $this->proxyNamespace . ';',
				$proxyContents
			);
			
			// Modify the class name to include the unique identifier
			$proxyContents = preg_replace(
				'/class\s+' . $className . '\s+extends/',
				'class ' . $className . '_' . $uniqueId . ' extends',
				$proxyContents
			);
			
			// Use eval to define the proxy class at runtime
			eval('?>' . $proxyContents);
			
			// Store the generated proxy class name
			$this->runtimeProxies[$entityClass] = $proxyClassName;
			
			return $proxyClassName;
		}
	}