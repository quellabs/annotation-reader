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
	}