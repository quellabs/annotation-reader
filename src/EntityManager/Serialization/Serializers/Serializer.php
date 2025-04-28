<?php
	
	namespace Quellabs\ObjectQuel\EntityManager\Serialization\Serializers;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\SerializationGroups;
	use Quellabs\ObjectQuel\EntityManager\Core\EntityStore;
	use Quellabs\ObjectQuel\EntityManager\Reflection\PropertyHandler;
	use Quellabs\ObjectQuel\EntityManager\Reflection\ReflectionHandler;
	
	class Serializer {
		
		protected array $normalizers;
		protected array $int_types;
		protected array $float_types;
		protected array $char_types;
		protected array $methodExistsCache;
		protected array $normalizerInstances;
		protected string $serialization_group_name;
		protected EntityStore $entityStore;
		protected PropertyHandler $propertyHandler;
		protected ReflectionHandler $reflectionHandler;
		protected AnnotationReader $annotationReader;
		
		/**
		 * Serializer constructor
		 * Initialiseert de benodigde handlers en readers.
		 * @param EntityStore $entityStore
		 * @param string $serializationGroupName
		 */
		public function __construct(EntityStore $entityStore, string $serializationGroupName="") {
			$this->entityStore = $entityStore;
			$this->propertyHandler = new PropertyHandler();
			$this->reflectionHandler = $entityStore->getReflectionHandler();
			$this->annotationReader = $entityStore->getAnnotationReader();
			
			$this->serialization_group_name = $serializationGroupName;
			$this->normalizers = [];
			$this->methodExistsCache = [];
			$this->normalizerInstances = [];
			$this->int_types = array_flip(["int", "integer", "smallint", "tinyint", "mediumint", "bigint", "bit"]);
			$this->float_types = array_flip(["decimal", "numeric", "float", "double", "real"]);
			$this->char_types = array_flip(['text', 'varchar','char']);

			$this->initializeNormalizers();
		}
		
		/**
		 * Deze functie initialiseert alle entiteiten in de "Entity"-directory.
		 * @return void
		 */
		private function initializeNormalizers(): void {
			// Ophalen van alle bestandsnamen in de "Entity"-directory.
			$normalizerFiles = scandir(dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "Normalizer");
			
			// Itereren over alle bestanden in de "Entity"-directory.
			foreach ($normalizerFiles as $fileName) {
				// Overslaan als het bestand geen PHP-bestand is.
				if (($fileName == 'NormalizerInterface.php') || !$this->isPHPFile($fileName)) {
					continue;
				}
				
				// Construeren van de entiteitsnaam op basis van de bestandsnaam.
				$this->normalizers[] = strtolower(substr($fileName, 0, strpos($fileName, "Normalizer")));
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
		 * Controleert of een gegeven kolomtype een integer-type is.
		 * @param string $columnType Het kolomtype om te controleren.
		 * @return bool True als het kolomtype een integer-type is, anders false.
		 */
		private function isIntColumnType(string $columnType): bool {
			return isset($this->int_types[$columnType]);
		}
		
		/**
		 * Controleert of een gegeven kolomtype een float-type is.
		 * @param string $columnType Het kolomtype om te controleren.
		 * @return bool True als het kolomtype een float-type is, anders false.
		 */
		private function isFloatColumnType(string $columnType): bool {
			return isset($this->float_types[$columnType]);
		}
		
		/**
		 * Controleert of een gegeven kolomtype een char-type is.
		 * @param string $columnType Het kolomtype om te controleren.
		 * @return bool True als het kolomtype een char-type is, anders false.
		 */
		private function isCharColumnType(string $columnType): bool {
			return isset($this->char_types[$columnType]);
		}
		
		/**
		 * Convert a string to camelcase
		 * @param string $input
		 * @param string $separator
		 * @return string
		 */
		protected function camelCase(string $input, string $separator = '_'): string {
			$array = explode($separator, $input);
			$parts = array_map('ucfirst', $array);
			return implode('', $parts);
		}
		
		/**
		 * Convert a string to snake case
		 * @url https://stackoverflow.com/questions/40514051/using-preg-replace-to-convert-camelcase-to-snake-case
		 * @param string $string
		 * @return string
		 */
		protected function snakeCase(string $string): string {
			return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $string));
		}
		
		/**
		 * Normalizes a value based on its column type annotation.
		 *
		 * This function handles value normalization in two ways:
		 * 1. For special types registered in $normalizers, it uses dedicated normalizer classes
		 * 2. For basic types (int, float), it performs simple type casting
		 * 3. For other types, it returns the value unchanged
		 *
		 * @param object $annotation The annotation object containing column metadata and type information
		 * @param mixed $value The raw value to be normalized
		 * @return mixed The normalized value appropriate for the column type
		 * @throws \RuntimeException If a normalizer class cannot be instantiated
		 */
		public function normalizeValue(object $annotation, mixed $value): mixed {
			// Extract the column type from the annotation object
			$columnType = $annotation->getType();
			
			// Check if this column type has a dedicated normalizer class
			if (in_array(strtolower($columnType), $this->normalizers)) {
				// Build the full normalizer class name based on the column type
				$normalizerClass = "\\Quellabs\ObjectQuel\\EntityManager\\Serialization\\Normalizer\\" . ucfirst($columnType) . "Normalizer";
				
				// Use cached normalizer instance if available, otherwise create a new one
				// This improves performance by reusing normalizer objects
				if (!isset($this->normalizerInstances[$columnType])) {
					$this->normalizerInstances[$columnType] = new $normalizerClass();
				}
				
				// Configure the normalizer with the current value and process it
				$this->normalizerInstances[$columnType]->setValue($value);
				return $this->normalizerInstances[$columnType]->normalize();
			}
			
			// For integer column types (int, bigint, smallint, etc.), perform integer casting
			if ($this->isIntColumnType($columnType)) {
				return (int)$value;
			}
			
			// For floating point column types (float, double, decimal, etc.), perform float casting
			if ($this->isFloatColumnType($columnType)) {
				return (float)$value;
			}
			
			// For all other column types (string, text, etc.), return the value unchanged
			// No special normalization needed for these types
			return $value;
		}
		
		/**
		 * Denormalizes a value based on its column type annotation.
		 *
		 * This function handles transforming database values back to their appropriate application formats:
		 * 1. Uses default values when appropriate
		 * 2. For special types registered in $normalizers, it uses dedicated normalizer classes
		 * 3. For other types, it returns the value unchanged
		 *
		 * @param object $annotation The annotation object containing column metadata and type information
		 * @param mixed $value The database value to be denormalized
		 * @return mixed The denormalized value appropriate for application use
		 * @throws \RuntimeException If a normalizer class cannot be instantiated
		 */
		public function denormalizeValue(object $annotation, mixed $value): mixed {
			// Extract the column type from the annotation object
			$columnType = $annotation->getType();
			
			// Handle null values with defaults
			// If the value is null but the column has a default value defined, return that default
			if (($value === null) && $annotation->hasDefault()) {
				return $annotation->getDefault();
			}
			
			// Check if this column type has a dedicated normalizer class
			if (in_array(strtolower($columnType), $this->normalizers)) {
				// Build the full normalizer class name based on the column type
				$normalizerClass = "\\Quellabs\ObjectQuel\\EntityManager\\Serialization\\Normalizer\\" . ucfirst($columnType) . "Normalizer";
				
				// Use cached normalizer instance if available, otherwise create a new one
				// This improves performance by reusing normalizer objects
				if (!isset($this->normalizerInstances[$columnType])) {
					$this->normalizerInstances[$columnType] = new $normalizerClass();
				}
				
				// Configure the normalizer with the current value and process it for denormalization
				$this->normalizerInstances[$columnType]->setValue($value);
				return $this->normalizerInstances[$columnType]->denormalize();
			}
			
			// For all other column types, return the value unchanged
			// No special denormalization needed for these types
			return $value;
		}
		
		/**
		 * Controleert of een eigenschap tot een specifieke serialisatiegroep behoort.
		 * Deze functie bepaalt of een gegeven eigenschap moet worden opgenomen in de serialisatie
		 * op basis van de gespecificeerde serialisatiegroep en de annotaties van de eigenschap.
		 * @param array $annotations Een array van annotaties geassocieerd met de eigenschap.
		 * @return bool True als de eigenschap moet worden geserialiseerd, anders false.
		 */
		public function propertyInSerializeGroup(array $annotations): bool {
			// Als er geen serialisatiegroep is opgegeven, includeren we alle eigenschappen
			if (empty($this->serialization_group_name)) {
				return true;
			}
			
			// Zoek naar de SerializeGroup annotatie
			foreach ($annotations as $annotation) {
				if ($annotation instanceof SerializationGroups) {
					// Controleer of de huidige serialisatiegroep in de annotatie voorkomt
					return in_array($this->serialization_group_name, $annotation->getGroups(), true);
				}
			}
			
			// Als er geen SerializeGroup annotatie is gevonden, dan includeren we de eigenschap
			return true;
		}
		
		/**
		 * Extraheert alle waarden uit de entiteit die gemarkeerd zijn als Column.
		 * @param object $entity De entiteit waaruit de waarden geëxtraheerd moeten worden.
		 * @return array Een array met property namen als keys en hun waarden.
		 */
		public function serialize(object $entity): array {
			// Early return if the entity does not exist in the entity store.
			if (!$this->entityStore->exists($entity)) {
				return [];
			}
			
			// Retrieve annotations for the entity class.
			$annotationList = $this->entityStore->getAnnotations($entity);
			
			// Iterate through each property's annotations.
			$result = [];
			
			foreach ($annotationList as $property => $annotations) {
				// Find the first valid annotation.
				foreach ($annotations as $annotation) {
					if ($annotation instanceof Column) {
						// Check if the property is part of a serialization group. If not, skip the property
						if (!$this->propertyInSerializeGroup($annotations)) {
							break;
						}
						
						// Fetch value
						$value = $this->propertyHandler->get($entity, $property);
						
						// Denormalize the value
						$valueDenormalized = $this->denormalizeValue($annotation, $value);
						
						// Get and store the property's current value.
						$result[$property] = $valueDenormalized;
						
						// Skip to the next property.
						break;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Injecteert de gegeven waarden in de entiteit.
		 * @param object $entity De entiteit waarin de waarden geïnjecteerd moeten worden.
		 * @param array $values De te injecteren waarden, met property namen als keys.
		 * @return void
		 */
		public function deserialize(object $entity, array $values): void {
			// Store the class name
			$className = get_class($entity);
			
			// Retrieve annotations for the entity class to understand how to map properties
			$annotationList = $this->entityStore->getAnnotations($entity);
			
			// Loop through each property's annotations to check how each should be handled
			foreach ($annotationList as $property => $annotations) {
				// Skip this property if the provided data array doesn't contain this column name
				if (!array_key_exists($property, $values)) {
					continue;
				}
				
				// Find the first valid annotation.
				foreach ($annotations as $annotation) {
					if ($annotation instanceof Column) {
						// Fetch value
						$value = $values[$property];
						
						// Normalize the value
						$normalizedValue = $this->normalizeValue($annotation, $value);
						
						// Determine the setter method name
						$setterMethod = 'set' . ucfirst($this->camelCase($property));
						
						// Check the cache for the method_exist result
						$methodKey = $className . '::' . $setterMethod;
						
						// If it's not there, add it
						if (!isset($this->methodExistsCache[$methodKey])) {
							$this->methodExistsCache[$methodKey] = method_exists($entity, $setterMethod);
						}
						
						// Set the property using the setter method. If that doesn't exist, use reflection
						if ($this->methodExistsCache[$methodKey]) {
							$entity->$setterMethod($normalizedValue);
						} else {
							$this->propertyHandler->set($entity, $property, $normalizedValue);
						}

						// Skip to the next property
						break;
					}
				}
			}
		}
	}