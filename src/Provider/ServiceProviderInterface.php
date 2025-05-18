<?php
	
	namespace Quellabs\DependencyInjection\Provider;
	
	/**
	 * Interface for service providers that support centralized autowiring
	 */
	interface ServiceProviderInterface {

		/**
		 * Determine if this provider supports creating the given class
		 * @param string $className
		 * @return bool
		 */
		public function supports(string $className): bool;
		
		/**
		 * Create an instance of the class with pre-resolved dependencies
		 * @param string $className The class to instantiate
		 * @param array $dependencies Pre-resolved constructor dependencies
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies): object;
	}