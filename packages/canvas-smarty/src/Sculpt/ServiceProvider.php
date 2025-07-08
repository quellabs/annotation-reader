<?php
	
	namespace Quellabs\Canvas\Smarty\Sculpt;
	
	use Quellabs\Sculpt\Application;
	
	/**
	 * Service Provider for Smarty template engine integration
	 * Registers Smarty-related commands with the Sculpt application
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * Register services and commands with the application
		 * This method is called during the application bootstrap process
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			// Register all Smarty-related commands with the application
			// This makes the commands available through the CLI interface
			$this->registerCommands($application, [
				ClearCacheCommand::class,  // Register the smarty:clear_cache command
			]);
		}
	}