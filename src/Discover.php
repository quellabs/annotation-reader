<?php
	
	namespace Quellabs\Discover;
	
	use Composer\Autoload\ClassLoader;
	use Dotenv\Dotenv;
	use Quellabs\Discover\Scanner\ScannerInterface;
	use Quellabs\Discover\Provider\ProviderInterface;
	use Quellabs\Discover\Config\DiscoveryConfig;
	use Quellabs\Discover\Utilities\PSR4;
	use RuntimeException;
	
	class Discover {
		
		/**
		 * @var array<ScannerInterface>
		 */
		protected array $scanners = [];
		
		/**
		 * @var array<ProviderInterface>
		 */
		protected array $providers = [];
		
		/**
		 * @var DiscoveryConfig
		 */
		protected DiscoveryConfig $config;
		
		/**
		 * @var PSR4 PSR-4 Utility Class
		 */
		private PSR4 $utilities;
		
		/**
		 * Create a new Discover instance
		 * @param DiscoveryConfig|null $config
		 */
		public function __construct(?DiscoveryConfig $config = null) {
			$this->config = $config ?? new DiscoveryConfig();
			$this->utilities = new PSR4();
		}
		
		/**
		 * Discover providers using all registered scanners
		 * @return self
		 */
		public function discover(): self {
			foreach ($this->scanners as $scanner) {
				$discoveredProviders = $scanner->scan($this->config);
				
				foreach ($discoveredProviders as $provider) {
					if ($provider instanceof ProviderInterface) {
						$this->addProvider($provider);
					}
				}
			}
			
			return $this;
		}
		
		/**
		 * Get all discovered providers
		 * @return array<ProviderInterface>
		 */
		public function getProviders(): array {
			return $this->providers;
		}
		
		/**
		 * Clear all discovered providers
		 * @return self
		 */
		public function clearProviders(): self {
			$this->providers = [];
			return $this;
		}
		
		/**
		 * Get the current configuration
		 * @return DiscoveryConfig
		 */
		public function getConfig(): DiscoveryConfig {
			return $this->config;
		}
		
		/**
		 * Set a new configuration
		 * @param DiscoveryConfig $config
		 * @return self
		 */
		public function setConfig(DiscoveryConfig $config): self {
			$this->config = $config;
			return $this;
		}
		
		/**
		 * Add a scanner
		 * @param ScannerInterface $scanner
		 * @return self
		 */
		public function addScanner(ScannerInterface $scanner): self {
			$this->scanners[] = $scanner;
			return $this;
		}
		
		/**
		 * This method adds a service provider to the internal providers collection,
		 * but only if a provider of the same class doesn't already exist and
		 * the provider indicates it should be loaded.
		 * @param ProviderInterface $provider The service provider instance to add
		 * @return self Returns $this for method chaining
		 */
		public function addProvider(ProviderInterface $provider): self {
			// Get the fully qualified class name of the provider
			$className = get_class($provider);
			
			// Flag to track if this provider class already exists in our collection
			$exists = false;
			
			// Check if a provider of the same class is already registered
			foreach ($this->providers as $existingProvider) {
				if (get_class($existingProvider) === $className) {
					$exists = true;
					break;
				}
			}
			
			// Only add the provider if:
			// 1. It doesn't already exist in our collection
			// 2. The provider itself indicates it should be loaded (via shouldLoad())
			if (!$exists && $provider->shouldLoad()) {
				$this->providers[] = $provider;
			}
			
			// Return $this to allow method chaining
			return $this;
		}
		
		/**
		 * Get all available provider types across all providers
		 * @return array<string> Array of unique provider types
		 */
		public function getProviderTypes(): array {
			// Initialize an empty array to store unique provider types
			$types = [];
			
			// Iterate through each provider in the collection
			foreach ($this->getProviders() as $provider) {
				// Get the family/type of the current provider
				$family = $provider->getFamily();
				
				// Only add the provider type if:
				// 1. It has a valid family (not null)
				// 2. The family isn't already in our types array (avoid duplicates)
				if ($family !== null && !in_array($family, $types)) {
					// Add the unique provider type to our array
					$types[] = $family;
				}
			}
			
			// Return the array of unique provider types
			return $types;
		}
		
		/**
		 * Find providers by metadata using a filter function
		 * @param callable $metadataFilter A callback function that receives a provider's metadata array
		 *                               and returns true if the provider should be included
		 * @return array<ProviderInterface> Array of provider instances matching the metadata criteria
		 */
		public function findProvidersByMetadata(callable $metadataFilter): array {
			return array_filter(
				$this->getProviders(),
				function(ProviderInterface $provider) use ($metadataFilter) {
					return $metadataFilter($provider->getMetadata());
				}
			);
		}
		
		/**
		 * Find all providers of a specific type
		 * @param string $family The provider type to filter by
		 * @return array<ProviderInterface> Array of provider instances of the requested type
		 */
		public function findProvidersByType(string $family): array {
			return array_filter(
				$this->getProviders(),
				function(ProviderInterface $provider) use ($family) {
					return $provider->getFamily() === $family;
				}
			);
		}
		
		/**
		 * Find providers that match a specific type and optionally filter by metadata
		 * @param string $family The family to filter by
		 * @param callable $metadataFilter Callback function that receives a provider's metadata array
		 *                                 and returns true if the provider should be included
		 * @return array<ProviderInterface> Array of matching provider instances
		 */
		public function findProvidersByTypeAndMetadata(string $family, callable $metadataFilter): array {
			return array_filter(
				$this->getProviders(),
				function(ProviderInterface $provider) use ($family, $metadataFilter) {
					// Check family first
					if ($provider->getFamily() !== $family) {
						return false;
					}
					
					// Apply the custom metadata filter
					return $metadataFilter($provider->getMetadata());
				}
			);
		}
		
		/**
		 * Gets the Composer autoloader instance
		 * @return ClassLoader
		 * @throws RuntimeException If autoloader can't be found
		 */
		public function getComposerAutoloader(): ClassLoader {
			return $this->utilities->getComposerAutoloader();
		}
		
		/**
		 * Find directory containing composer.json by traversing up from the given directory
		 * @param string|null $directory Directory to start searching from (defaults to current directory)
		 * @return string|null Directory containing composer.json if found, null otherwise
		 */
		public function getProjectRoot(?string $directory = null): ?string {
			return $this->utilities->getProjectRoot($directory);
		}
		
		/**
		 * Find the path to the local composer.json file
		 * @param string|null $startDirectory Directory to start searching from (defaults to current directory)
		 * @return string|null Path to composer.json if found, null otherwise
		 */
		public function getComposerJsonFilePath(?string $startDirectory = null): ?string {
			return $this->utilities->getComposerJsonFilePath($startDirectory);
		}
		
		/**
		 * Find the path to installed.json
		 * @param string|null $startDirectory Directory to start searching from (defaults to current directory)
		 * @return string|null Path to composer.json if found, null otherwise
		 */
		public function getComposerInstalledFilePath(?string $startDirectory = null): ?string {
			return $this->utilities->getComposerInstalledFilePath($startDirectory);
		}
		
		/**
		 * Maps a directory path to a namespace based on PSR-4 rules.
		 * This method attempts to determine the correct namespace for a directory by:
		 * 1. First checking against registered autoloader PSR-4 mappings (for dependencies)
		 * 2. Then checking against the main project's composer.json PSR-4 mappings if necessary
		 * @param string $directory Directory path to map to a namespace
		 * @return string|null The corresponding namespace if found, null otherwise
		 */
		public function resolveNamespaceFromPath(string $directory): ?string {
			return $this->utilities->resolveNamespaceFromPath($directory);
		}
		
		/**
		 * Recursively scans a directory and maps files to namespaced classes based on PSR-4 rules
		 * @param string $directory Directory to scan
		 * @param callable|null $filter Optional callback function to filter classes (receives className as parameter)
		 * @return array<string> Array of fully qualified class names
		 */
		public function findClassesInDirectory(string $directory, ?callable $filter = null): array {
			return $this->utilities->findClassesInDirectory($directory, $filter);
		}
		
		/**
		 * Reads and parses the .env file into an array
		 * Uses the vlucas/phpdotenv library to parse environment variables
		 * Does not load variables into $_ENV or $_SERVER superglobals
		 * @return array Array containing all environment variables as key-value pairs
		 */
		public function readEnvironmentFile(): array {
			// Construct the full path to the .env file in the project root directory
			$envPath = $this->getProjectRoot() . DIRECTORY_SEPARATOR . '.env';
			
			// Check if the .env file exists before attempting to read it
			if (!file_exists($envPath)) {
				// Return an empty array if .env file is not found
				return [];
			}
			
			// Read the raw contents of the .env file as a string
			$content = file_get_contents($envPath);
			
			// Parse the .env content into an associative array using Dotenv's static parser
			// This parses KEY=VALUE pairs without loading them into PHP's environment
			return Dotenv::parse($content);
		}
	}