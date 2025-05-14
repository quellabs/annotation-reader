<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Sculpt\Application;
	
	interface ServiceProviderInterface {
		/**
		 * Register any application services
		 * @param Application $app
		 */
		public function register(Application $app): void;
		
		/**
		 * Bootstrap any application services
		 * @param Application $app
		 */
		public function boot(Application $app): void;
		
		/**
		 * Get the provider's namespace identifier
		 * Used for grouping commands in the CLI
		 * @return string
		 */
		public function getNamespace(): string;
		
		/**
		 * Get a description of this provider's functionality
		 * @return string
		 */
		public function getDescription(): string;
	}