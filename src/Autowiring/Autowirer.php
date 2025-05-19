<?php
	
	namespace Quellabs\DependencyInjection\Autowiring;
	
	use Quellabs\DependencyInjection\Container\ContainerInterface;
	
	/**
	 * Handles dependency injection through reflection
	 */
	class Autowirer {
		
		/**
		 * @var ContainerInterface
		 */
		private ContainerInterface $container;
		
		/**
		 * Autowirer constructor
		 * @param ContainerInterface $container
		 */
		public function __construct(ContainerInterface $container) {
			$this->container = $container;
		}
		
		/**
		 * Get the arguments for a method with autowired dependencies
		 * @param string $className
		 * @param string $methodName
		 * @param array $parameters
		 * @return array
		 */
		public function getMethodArguments(string $className, string $methodName, array $parameters = []): array {
			$methodParams = $this->getMethodParameters($className, $methodName);
			$arguments = [];
			
			foreach ($methodParams as $param) {
				$paramName = $param['name'];
				$paramType = $param['type'] ?? null;
				
				// If a parameter is provided, use it
				if (isset($parameters[$paramName])) {
					$arguments[] = $parameters[$paramName];
					continue;
				}
				
				// If type is a class/interface, try to get from container
				if ($paramType && !$this->isBuiltinType($paramType)) {
					$arguments[] = $this->container->get($paramType);
					continue;
				}
				
				// Use default value if available
				if (array_key_exists('default_value', $param)) {
					$arguments[] = $param['default_value'];
					continue;
				}
				
				// If we reach here, we couldn't resolve the parameter
				throw new \RuntimeException("Cannot autowire parameter '$paramName' for $className::$methodName");
			}
			
			return $arguments;
		}
		
		/**
		 * Get the parameters of a method including type hints and default values
		 * @param string $className
		 * @param string $methodName
		 * @return array
		 */
		protected function getMethodParameters(string $className, string $methodName): array {
			try {
				$result = [];
				
				// New reflection class to get information about the class name
				$reflectionClass = new \ReflectionClass($className);
				
				// Determine which method to reflect
				if (empty($methodName) || $methodName === '__construct') {
					$methodReflector = $reflectionClass->getConstructor();
				} else {
					$methodReflector = $reflectionClass->getMethod($methodName);
				}
				
				// Return an empty array when the method does not exist
				if (!$methodReflector) {
					return [];
				}
				
				// Process each parameter
				foreach ($methodReflector->getParameters() as $parameter) {
					$param = [
						'name'          => $parameter->getName(),
						'type'          => null,
						'default_value' => null
					];
					
					// Get the type if available
					if ($parameter->hasType()) {
						$type = $parameter->getType();
						$param['type'] = $type->getName();
					}
					
					// Get default value if available
					if ($parameter->isDefaultValueAvailable()) {
						$param['default_value'] = $parameter->getDefaultValue();
					}
					
					// Add the parameter to the parameter list
					$result[] = $param;
				}
				
				return $result;
			} catch (\ReflectionException $e) {
				return [];
			}
		}
		
		/**
		 * Check if a type is a built-in PHP type that can be used in parameter lists
		 * @param string $type
		 * @return bool
		 */
		protected function isBuiltinType(string $type): bool {
			return in_array($type, [
				// Basic scalar types
				'string', 'int', 'float', 'bool',
				
				// Legacy aliases for scalar types
				'integer', 'boolean', 'double',
				
				// Compound types
				'array', 'object', 'callable', 'iterable',
				
				// Special types
				'mixed', 'null', 'false', 'true',
				
				// Resource (rarely used as a parameter type)
				'resource',
			]);
		}
	}