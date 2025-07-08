<?php
	
	namespace Quellabs\Discover\Utilities;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	class ProviderValidator {
		
		/**
		 * Constants
		 */
		private const string CLASS_NAME_PATTERN = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*$/';
		
		/**
		 * Performs essential validation checks to ensure a provider class is properly
		 * defined and can be safely instantiated. This prevents runtime errors that
		 * would occur if invalid providers were included in the application bootstrap.
		 * @param string $providerClass Fully qualified class name of the provider to validate
		 * @return bool True if provider is valid and can be used, false if validation fails
		 */
		public function validate(string $providerClass): bool {
			// Prevent arbitrary class loading
			if (!preg_match(self::CLASS_NAME_PATTERN, $providerClass)) {
				return false;
			}
			
			// Verify that the provider class can be found and autoloaded
			// This catches typos in class names, missing files, or autoloader issues
			if (!class_exists($providerClass)) {
				return false;
			}
			
			// Ensure the provider class implements the required ProviderInterface contract
			// This guarantees the class has all necessary methods for provider functionality
			if (!is_subclass_of($providerClass, ProviderInterface::class)) {
				return false;
			}
			
			// Check if class is instantiable
			try {
				$reflection = new \ReflectionClass($providerClass);
				
				if (!$reflection->isInstantiable()) {
					return false;
				}
			} catch (\ReflectionException $e) {
				return false;
			}
			
			// The provider passed all validation checks and is safe to instantiate
			return true;
		}
	}