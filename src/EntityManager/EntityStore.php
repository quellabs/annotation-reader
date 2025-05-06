<?php
    
    namespace Quellabs\ObjectQuel\EntityManager;
    
    use Quellabs\AnnotationReader\AnnotationReader;
    use Quellabs\ObjectQuel\Annotations\Orm\Column;
    use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
    use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
    use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
    use Quellabs\ObjectQuel\Configuration;
    use Quellabs\ObjectQuel\EntityManager\Proxy\ProxyGenerator;
    use Quellabs\ObjectQuel\EntityManager\Reflection\EntityLocator;
    use Quellabs\ObjectQuel\EntityManager\Reflection\ReflectionHandler;
    use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
    
    class EntityStore {
	    protected Configuration $configuration;
	    protected EntityLocator $entity_locator;
		protected AnnotationReader $annotation_reader;
        protected ReflectionHandler $reflection_handler;
		protected ProxyGenerator $proxy_generator;
        protected array $entity_properties;
        protected array $entity_table_name;
        protected array $entity_annotations;
        protected array $column_map_cache;
        protected array $identifier_keys_cache;
        protected array $identifier_columns_cache;
        protected string|bool $services_path;
	    protected string $entity_namespace;
        protected array|null $dependencies;
        protected array $dependencies_cache;
        protected array $completed_entity_name_cache;
	    
	    /**
	     * EntityStore constructor.
	     */
		public function __construct(Configuration $configuration) {
			$annotationReaderConfiguration = new \Quellabs\AnnotationReader\Configuration();
			$annotationReaderConfiguration->setUseAnnotationCache($configuration->useAnnotationCache());
			$annotationReaderConfiguration->setAnnotationCachePath($configuration->getAnnotationCachePath());

			$this->annotation_reader = new AnnotationReader($annotationReaderConfiguration);
			$this->reflection_handler = new ReflectionHandler();
			$this->services_path = realpath(__DIR__ . DIRECTORY_SEPARATOR . "..");
			$this->entity_namespace = $configuration->getEntityNameSpace();
			$this->entity_properties = [];
			$this->entity_table_name = [];
			$this->entity_annotations = [];
			$this->column_map_cache = [];
			$this->identifier_keys_cache = [];
			$this->identifier_columns_cache = [];
			$this->dependencies = null;
			$this->dependencies_cache = [];
			$this->completed_entity_name_cache = [];

			// Create the EntityLocator
			$this->entity_locator = new EntityLocator($configuration, $this->annotation_reader);
			
			// Deze functie initialiseert alle entiteiten in de "Entity"-directory.
			$this->initializeEntities();
			
			// Deze functie initialiseert de proxies
			$this->proxy_generator = new ProxyGenerator($this, $configuration);
		}
	    
	    /**
	     * Initialize entity classes using the EntityLocator.
	     * This method discovers entity classes, validates them,
	     * and loads their properties and annotations into memory.
	     * @return void
	     */
	    private function initializeEntities(): void {
		    try {
			    // Discover all entities using the EntityLocator
			    $entityClasses = $this->entity_locator->discoverEntities();
			    
			    // Process each discovered entity
			    foreach ($entityClasses as $entityName) {
				    // Initialize data structures for this entity
				    $this->entity_annotations[$entityName] = [];
				    $this->entity_properties[$entityName] = $this->reflection_handler->getProperties($entityName);
				    $this->entity_table_name[$entityName] = $this->annotation_reader->getClassAnnotations($entityName)["Orm\\Table"]->getName();
				    
				    // Process each property of the entity
				    foreach ($this->entity_properties[$entityName] as $property) {
					    // Get annotations for the current property
					    $annotations = $this->annotation_reader->getPropertyAnnotations($entityName, $property);
					    
					    // Store property annotations in the entity_annotations array
					    $this->entity_annotations[$entityName][$property] = $annotations;
				    }
			    }
		    } catch (\Exception $e) {
			    // Log or handle initialization errors
			    throw new \RuntimeException("Error initializing entities: " . $e->getMessage(), 0, $e);
		    }
	    }
		
		/**
		 * Returns a list of entities and their manytoone dependencies
		 * @return array
		 */
		private function getAllEntityDependencies(): array {
			if ($this->dependencies === null) {
				$this->dependencies = [];

				foreach (array_keys($this->entity_table_name) as $class) {
					$manyToOneDependencies = $this->getManyToOneDependencies($class);
					$oneToOneDependencies = array_filter($this->getOneToOneDependencies($class), function($e) { return !empty($e->getInversedBy()); });
					
					$this->dependencies[$class] = array_unique(array_merge(
						array_map(function($e) { return $e->getTargetEntity(); }, $manyToOneDependencies),
						array_map(function($e) { return $e->getTargetEntity(); }, $oneToOneDependencies),
					));
				}
			}
			
			return $this->dependencies;
		}
		
		/**
		 * Interne helper functies voor het ophalen van properties met een bepaalde annotatie
		 * @param mixed $entity De naam van de entiteit waarvoor je afhankelijkheden wilt krijgen.
		 * @param string $desiredAnnotationType Het type van de afhankelijkheid
		 * @return array
		 */
		private function internalGetDependencies(mixed $entity, string $desiredAnnotationType): array {
			// Bepaal de klassenaam van de entiteit
			if (!is_object($entity)) {
				$entityClass = ltrim($entity, "\\");
			} elseif ($entity instanceof \ReflectionClass) {
				$entityClass = $entity->getName();
			} else {
				$entityClass = get_class($entity);
			}
			
			// Als de klassenaam een proxy is, haal dan de class op van de parent
			$normalizedClass = $this->normalizeEntityName($entityClass);
			
			// Cache hash
			$md5OfQuery = hash("sha256", $normalizedClass . "##" . $desiredAnnotationType);
			
			// Haal dependencies uit cache indien mogelijk
			if (isset($this->dependencies_cache[$md5OfQuery])) {
				return $this->dependencies_cache[$md5OfQuery];
			}
			
			// Haal de annotaties op voor de opgegeven klasse.
			$annotationList = $this->getAnnotations($normalizedClass);
			
			// Loop door elke annotatie om te controleren op een relatie.
			$result = [];
			
			foreach ($annotationList as $property => $annotations) {
				foreach ($annotations as $annotation) {
					if ($annotation instanceof $desiredAnnotationType) {
						$result[$property] = $annotation;
						break; // Stop met zoeken als de gewenste annotatie is gevonden
					}
				}
			}
			
			$this->dependencies_cache[$md5OfQuery] = $result;
			return $result;
		}
		
		/**
         * Returns the annotationReader object
         * @return AnnotationReader
         */
        public function getAnnotationReader(): AnnotationReader {
            return $this->annotation_reader;
        }
    
        /**
         * Returns the ReflectionHandler object
         * @return ReflectionHandler
         */
        public function getReflectionHandler(): ReflectionHandler {
            return $this->reflection_handler;
        }
	    
	    /**
	     * Normalizes the entity name to return the base entity class if the input is a proxy class.
	     * @param string $class The fully qualified class name to be normalized.
	     * @return string The normalized class name.
	     */
	    public function normalizeEntityName(string $class): string {
		    if (!isset($this->completed_entity_name_cache[$class])) {
			    // Check if the class name contains the proxy namespace, which indicates a proxy class.
			    // If it's a proxy class, get the name of the parent class (the real entity class).
			    if (str_contains($class, $this->configuration->getProxyNamespace())) {
				    $this->completed_entity_name_cache[$class] = $this->reflection_handler->getParent($class);
			    } elseif (str_contains($class, "\\")) {
				    $this->completed_entity_name_cache[$class] = $class;
			    } else {
				    $this->completed_entity_name_cache[$class] = "{$this->entity_namespace}\\{$class}";
			    }
		    }
		    
		    // Return the cached class name, which will be the normalized version
		    return $this->completed_entity_name_cache[$class];
	    }
	    
	    /**
	     * Checks if the entity or its parent exists in the entity_table_name array.
	     * @param mixed $entity The entity to check, either as an object or as a string class name.
	     * @return bool True if the entity or its parent class exists in the entity_table_name array, false otherwise.
	     */
	    public function exists(mixed $entity): bool {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the parent class name
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Check if the entity class exists in the entity_table_name array
		    if (isset($this->entity_table_name[$normalizedClass])) {
			    return true;
		    }
		    
		    // Get the parent class name using the ReflectionHandler
		    $parentClass = $this->getReflectionHandler()->getParent($normalizedClass);
		    
		    // Check if the parent class exists in the entity_table_name array
		    if ($parentClass !== null && isset($this->entity_table_name[$parentClass])) {
			    return true;
		    }
		    
		    // Return false if neither the entity nor its parent class exists in the entity_table_name array
		    return false;
	    }
	    
	    /**
	     * Returns the table name attached to the entity
	     * @param mixed $entity
	     * @return string|null
	     */
	    public function getOwningTable(mixed $entity): ?string {
			// Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }

			// If the class name is a proxy, get the parent class name
		    $normalizedClass = $this->normalizeEntityName($entityClass);

			// Get the table name
		    return $this->entity_table_name[$normalizedClass] ?? null;
	    }
	    
		/**
		 * Deze functie haalt de primaire sleutels van een gegeven entiteit op.
		 * @param mixed $entity De entiteit waarvan de primaire sleutels worden opgehaald.
		 * @return array Een array met de namen van de eigenschappen die de primaire sleutels zijn.
		 */
		public function getIdentifierKeys(mixed $entity): array {
			// Bepaal de klassenaam van de entiteit
			if (!is_object($entity)) {
				$entityClass = ltrim($entity, "\\");
			} elseif ($entity instanceof \ReflectionClass) {
				$entityClass = $entity->getName();
			} else {
				$entityClass = get_class($entity);
			}
			
			// Als de klassenaam een proxy is, haal dan de class op van de parent
			$normalizedClass = $this->normalizeEntityName($entityClass);
			
			// Gebruik de gecachte waarde als deze bestaat
			if (isset($this->identifier_keys_cache[$normalizedClass])) {
				return $this->identifier_keys_cache[$normalizedClass];
			}
			
			// Ophalen van alle annotaties voor de gegeven entiteit.
			$entityAnnotations = $this->getAnnotations($entity);
			
			// Zoek de primaire sleutels en cache het resultaat
			$result = [];
			
			foreach ($entityAnnotations as $property => $annotations) {
				foreach ($annotations as $annotation) {
					if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
						$result[] = $property;
						break;
					}
				}
			}
			
			// Cache het resultaat voor toekomstig gebruik
			$this->identifier_keys_cache[$normalizedClass] = $result;
			
			// Retourneer het resultaat
			return $result;
		}

		/**
		 * Haalt de kolomnamen op die als primaire sleutel dienen voor een bepaalde entiteit.
		 * @param mixed $entity De entiteit waarvoor de primaire sleutelkolommen worden opgehaald.
		 * @return array Een array met de namen van de kolommen die als primaire sleutel dienen.
		 */
		public function getIdentifierColumnNames(mixed $entity): array {
			// Bepaal de klassenaam van de entiteit
			if (!is_object($entity)) {
				$entityClass = ltrim($entity, "\\");
			} elseif ($entity instanceof \ReflectionClass) {
				$entityClass = $entity->getName();
			} else {
				$entityClass = get_class($entity);
			}
			
			// Als de klassenaam een proxy is, haal dan de class op van de parent
			$normalizedClass = $this->normalizeEntityName($entityClass);
			
			// Gebruik de gecachte waarde als deze bestaat
			if (isset($this->identifier_columns_cache[$normalizedClass])) {
				return $this->identifier_columns_cache[$normalizedClass];
			}
			
			// Haal alle annotaties op voor de gegeven entiteit
			$annotationList = $this->getAnnotations($normalizedClass);
			
			// Initialiseer een lege array om de resultaten in op te slaan
			$result = [];
			
			// Loop door alle annotaties van de entiteit
			foreach ($annotationList as $annotations) {
				foreach ($annotations as $annotation) {
					if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
						$result[] = $annotation->getName();
					}
				}
			}
			
			// Cache het resultaat voor toekomstig gebruik
			$this->identifier_columns_cache[$normalizedClass] = $result;
			
			// Retourneer het resultaat
			return $result;
		}
		
		/**
		 * Verkrijgt de kaart tussen eigenschappen en kolomnamen voor een gegeven entiteit.
		 * Deze functie genereert een associatieve array die de eigenschappen van een entiteit
		 * koppelt aan hun respectievelijke kolomnamen in de database. De resultaten worden gecached
		 * om herhaalde berekeningen te voorkomen.
		 * @param mixed $entity Het object of de klassenaam van de entiteit.
		 * @return array Een associatieve array met de eigenschap als sleutel en de kolomnaam als waarde.
		 */
		public function getColumnMap(mixed $entity): array {
			// Bepaal de klassenaam van de entiteit
			if (!is_object($entity)) {
				$entityClass = ltrim($entity, "\\");
			} elseif ($entity instanceof \ReflectionClass) {
				$entityClass = $entity->getName();
			} else {
				$entityClass = get_class($entity);
			}
			
			// Als de klassenaam een proxy is, haal dan de class op van de parent
			$normalizedClass = $this->normalizeEntityName($entityClass);
			
			// Gebruik de gecachte waarde als deze bestaat
			if (isset($this->column_map_cache[$normalizedClass])) {
				return $this->column_map_cache[$normalizedClass];
			}
			
			// Haal alle annotaties voor de entiteit op
			$annotationList = $this->getAnnotations($normalizedClass);
			
			// Loop door alle annotaties, gekoppeld aan hun respectievelijke eigenschappen
			$result = [];

			foreach ($annotationList as $property => $annotations) {
				// Verkrijg de kolomnaam van de annotaties
				foreach ($annotations as $annotation) {
					if ($annotation instanceof Column) {
						$result[$property] = $annotation->getName();
						break;
					}
				}
			}
			
			// Cache het resultaat voor toekomstig gebruik
			$this->column_map_cache[$normalizedClass] = $result;
			
			// Retourneer het resultaat
			return $result;
		}
	    
	    /**
	     * Returns the entity's annotations
	     * @param mixed $entity
	     * @return array
	     */
	    public function getAnnotations(mixed $entity): array {
		    // Determine the class name of the entity
		    $entityClass = !is_object($entity) ? ltrim($entity, "\\") : get_class($entity);
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Return the annotation information
		    return $this->entity_annotations[$normalizedClass] ?? [];
	    }
		
		/**
		 * Haalt alle OneToOne-afhankelijkheden op voor een bepaalde entiteit.
		 * @param mixed $entity De naam van de entiteit waarvoor je de OneToOne-afhankelijkheden wilt krijgen.
		 * @return OneToOne[] Een associatieve array met als sleutel de naam van de doelentiteit en als waarde de annotatie.
		 */
		public function getOneToOneDependencies(mixed $entity): array {
			return $this->internalGetDependencies($entity, OneToOne::class);
		}
		
		/**
		 * Haal de ManyToOne afhankelijkheden op voor een gegeven entiteitsklasse.
		 * Deze functie gebruikt annotaties om te bepalen welke andere entiteiten
		 * gerelateerd zijn aan de gegeven entiteitsklasse via een ManyToOne relatie.
		 * De namen van deze gerelateerde entiteiten worden geretourneerd als een array.
		 * @param mixed $entity De naam van de entiteitsklasse om te inspecteren.
		 * @return ManyToOne[] Een array van entiteitsnamen waarmee de gegeven klasse een ManyToOne relatie heeft.
		 */
		public function getManyToOneDependencies(mixed $entity): array {
			return $this->internalGetDependencies($entity, ManyToOne::class);
		}
		
		/**
		 * Haalt alle OneToMany-afhankelijkheden op voor een bepaalde entiteit.
		 * @param mixed $entity De naam van de entiteit waarvoor je de OneToMany-afhankelijkheden wilt krijgen.
		 * @return OneToMany[] Een associatieve array met als sleutel de naam van de doelentiteit en als waarde de annotatie.
		 */
		public function getOneToManyDependencies(mixed $entity): array {
			return $this->internalGetDependencies($entity, OneToMany::class);
		}
        
        /**
         * Interne helper functie voor het ophalen van properties met een bepaalde annotatie
         * @param mixed $entity De naam van de entiteit waarvoor je afhankelijkheden wilt krijgen.
         * @return array
         */
        public function getAllDependencies(mixed $entity): array {
			// Bepaal de klassenaam van de entiteit
			$entityClass = !is_object($entity) ? ltrim($entity, "\\") : get_class($entity);
            
            // Als de klassenaam een proxy is, haal dan de class op van de parent
            $normalizedClass = $this->normalizeEntityName($entityClass);
            
            // Cache hash
            $md5OfQuery = hash("sha256", $normalizedClass);
            
            // Haal dependencies uit cache indien mogelijk
            if (isset($this->dependencies_cache[$md5OfQuery])) {
                return $this->dependencies_cache[$md5OfQuery];
            }
            
            // Haal de annotaties op voor de opgegeven klasse.
            $annotationList = $this->getAnnotations($normalizedClass);
            
            // Loop door elke annotatie om te controleren op een relatie.
			$result = [];
			
			foreach (array_keys($annotationList) as $property) {
				foreach ($annotationList[$property] as $annotation) {
					if ($annotation instanceof OneToMany || $annotation instanceof OneToOne || $annotation instanceof ManyToOne) {
						$result[$property][] = $annotation;
						continue 2;
					}
				}
			}
            
            $this->dependencies_cache[$md5OfQuery] = $result;
            return $result;
        }
	    
	    /**
	     * Returns all entities that depend on the specified entity.
	     * @param mixed $entity The name of the entity for which you want to find dependent entities.
	     * @return array A list of dependent entities.
	     */
	    public function getDependentEntities(mixed $entity): array {
		    // Determine the class name of the entity
		    $entityClass = !is_object($entity) ? ltrim($entity, "\\") : get_class($entity);
		    
		    // If the class name is a proxy, get the parent class
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Get all known entity dependencies
		    $dependencies = $this->getAllEntityDependencies();
		    
		    // Loop through each entity and its dependencies to check for the specified class
		    $result = [];
		    
		    foreach ($dependencies as $entity => $entityDependencies) {
			    // Use array_flip for faster lookups
			    $flippedDependencies = array_flip($entityDependencies);
			    
			    // If the specified class exists in the flipped dependencies list,
			    // add it to the result
			    if (isset($flippedDependencies[$normalizedClass])) {
				    $result[] = $entity;
			    }
		    }
		    
		    // Return the list of dependent entities
		    return $result;
	    }
	    
	    /**
	     * Retrieves the primary key of the main range from an AstRetrieve object.
	     * This function searches through the ranges within the AstRetrieve object and returns the primary key
	     * of the first range that doesn't have a join property. This represents the main entity the query relates to.
	     * @param AstRetrieve $e A reference to the AstRetrieve object representing the query.
	     * @return ?array An array with information about the range and primary key, or null if no suitable range is found.
	     */
	    public function fetchPrimaryKeyOfMainRange(AstRetrieve $e): ?array {
		    foreach ($e->getRanges() as $range) {
			    // Continue if the range contains a join property
			    if ($range->getJoinProperty() !== null) {
				    continue;
			    }
			    
			    // Get the entity name and its associated primary key if the range doesn't have a join property
			    $entityName = $range->getEntity()->getName();
			    $entityNameIdentifierKeys = $this->getIdentifierKeys($entityName);
			    
			    // Return the range name, entity name, and the primary key of the entity
			    return [
				    'range'      => $range,
				    'entityName' => $entityName,
				    'primaryKey' => $entityNameIdentifierKeys[0]
			    ];
		    }
		    
		    // Return null if no range without a join property is found
		    // This should never happen in practice, as such a query cannot be created
		    return null;
	    }
	    
	    /**
	     * Normalizes the primary key into an array.
	     * This function checks if the given primary key is already an array.
	     * If not, it converts the primary key into an array with the proper key
	     * based on the entity type.
	     * @param mixed $primaryKey The primary key to be normalized.
	     * @param string $entityType The type of entity for which the primary key is needed.
	     * @return array A normalized representation of the primary key as an array.
	     */
	    public function formatPrimaryKeyAsArray(mixed $primaryKey, string $entityType): array {
		    // If the primary key is already an array, return it directly.
		    if (is_array($primaryKey)) {
			    return $primaryKey;
		    }
		    
		    // Otherwise, get the identifier keys and create an array with the proper key and value.
		    $identifierKeys = $this->getIdentifierKeys($entityType);
		    return [$identifierKeys[0] => $primaryKey];
	    }
    }