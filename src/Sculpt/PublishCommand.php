<?php
	
	namespace Quellabs\Canvas\Sculpt;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Discover\Discover;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Contracts\Publishing\AssetPublisher;
	
	class PublishCommand extends CommandBase {
		
		/**
		 * @var Discover Discovery component
		 */
		private Discover $discover;
		
		/**
		 * PublishCommand constructor
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ProviderInterface|null $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ProviderInterface $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->discover = new Discover();
		}
		
		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "canvas:publish";
		}
		
		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Publishes assets from available publishers";
		}
		
		/**
		 * Execute the publish command
		 *
		 * This is the main entry point for the canvas:publish command. It handles four scenarios:
		 * 1. --list flag: Shows all available publishers
		 * 2. --help flag: Shows detailed help for a specific tag or general usage
		 * 4. No parameters: Shows usage help
		 *
		 * @param ConfigurationManager $config Configuration containing command flags and options
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			// Show title of command
			$this->output->writeLn("<info>Canvas Publish Command</info>");
			$this->output->writeLn("");
			
			// Initialize the discovery system to find all available asset publishers
			$discover = new Discover();
			$discover->addScanner(new ComposerScanner("publishers"));
			$discover->discover();
			
			// Retrieve all discovered publisher providers from the discovery system
			$providers = $discover->getProviders();
			
			// Handle the --list flag first (no tag validation needed)
			if ($config->hasFlag("list")) {
				return $this->listPublishers($providers);
			}
			
			// Get the tag parameter once
			$tag = $config->getPositional(0);
			
			// If no tag is provided, show usage help
			if (!$tag) {
				$this->showUsageHelp();
				return 0;
			}
			
			// Validate the tag exists before doing anything else
			if (!$this->findProviderByTag($providers, $tag)) {
				$this->output->error("Publisher with tag '{$tag}' not found.");
				return 1;
			}
			
			// Now that we know the tag is valid, handle help or publish
			if ($config->hasFlag("help")) {
				return $this->showHelp($providers, $tag);
			}
			
			// Proceed with publishing
			return $this->publishTag($providers, $tag, $config->hasFlag("force"), $config->hasFlag("overwrite"));
		}
		
		/**
		 * Show help information for publishers
		 * @param array $providers Array of discovered publisher providers
		 * @param string|null $publisher Optional publisher to show specific help for
		 * @return int Exit code (0 = success, 1 = error)
		 */
		private function showHelp(array $providers, ?string $publisher = null): int {
			// Show help for a specific publisher
			$provider = $this->findProviderByTag($providers, $publisher);
			
			// Display detailed help for the specific publisher
			$this->output->writeLn("<info>Help for publisher: {$publisher}</info>");
			$this->output->writeLn($provider::getDescription());
			$this->output->writeLn("Usage: php ./vendor/bin/sculpt canvas:publish {$publisher}");
			$this->output->writeLn("");
			
			// Show extended help if the publisher supports it
			$helpText = $provider::getHelp();
			
			if (!empty($helpText)) {
				$this->output->writeLn($provider::getHelp());
				$this->output->writeLn("");
			}
			
			return 0;
		}
		
		/**
		 * Find a provider by its tag identifier
		 * @param array $providers Array of provider class names
		 * @param string $tag Tag to search for
		 * @return AssetPublisher|null Provider class if found, null otherwise
		 */
		private function findProviderByTag(array $providers, string $tag): ?AssetPublisher {
			foreach ($providers as $provider) {
				if ($provider::getTag() === $tag) {
					return $provider;
				}
			}
			
			return null;
		}
		
		/**
		 * List all available publishers
		 * @param array $providers
		 * @return int
		 */
		private function listPublishers(array $providers): int {
			$this->output->writeLn("<info>Available Publishers:</info>");
			$this->output->writeLn("");
			
			foreach ($providers as $provider) {
				$this->output->writeLn(sprintf(
					"  <comment>%s</comment> - %s",
					$provider->getTag(),
					$provider->getDescription()
				));
			}
			
			$this->output->writeLn("");
			return 0;
		}
		
		/**
		 * Publish assets for a specific tag with file copying and rollback functionality
		 * @param array $providers
		 * @param string $publisher
		 * @param bool $force
		 * @param bool $overwrite
		 * @return int
		 */
		private function publishTag(array $providers, string $publisher, bool $force = false, bool $overwrite = false): int {
			$targetProvider = $this->findProviderByTag($providers, $publisher);
			
			// Check if the assets can be published
			if (!$targetProvider->canPublish()) {
				$this->showCannotPublishError($targetProvider);
				return 1;
			}
			
			// Validate and prepare for publishing
			$publishData = $this->preparePublishing($targetProvider, $publisher);
			
			if ($publishData === null) {
				return 1; // Error occurred during preparation
			}
			
			// Show preview and get confirmation
			if (!$this->showPublishPreview($publishData, $force, $overwrite)) {
				return 0; // User cancelled
			}
			
			// Execute the publishing process
			return $this->executePublishing($publishData, $targetProvider, $overwrite);
		}
		
		/**
		 * Prepare and validate everything needed for publishing
		 * @param AssetPublisher $targetProvider
		 * @param string $publisher
		 * @return array|null Returns publish data array or null on error
		 */
		private function preparePublishing(AssetPublisher $targetProvider, string $publisher): ?array {
			// Resolve the source directory
			$sourceDirectory = $this->discover->resolvePath($targetProvider->getSourcePath());
			
			// Show information about what we're publishing
			$this->output->writeLn("Publishing: " . $publisher);
			$this->output->writeLn("Description: " . $targetProvider::getDescription());
			$this->output->writeLn("Source directory: " . $sourceDirectory);
			$this->output->writeLn("");
			
			// Get the manifest and validate it
			$manifest = $targetProvider->getManifest();
			
			if (!isset($manifest['files']) || !is_array($manifest['files'])) {
				$this->output->error("Invalid manifest: 'files' key not found or not an array");
				return null;
			}
			
			// Get project root and source directory
			$discover = new Discover();
			$projectRoot = $discover->getProjectRoot();
			
			// Make source path absolute if it's relative
			if (!$this->isAbsolutePath($sourceDirectory)) {
				$sourceDirectory = rtrim($projectRoot, '/') . '/' . ltrim($sourceDirectory, '/');
			}
			
			// Validate source directory exists
			if (!is_dir($sourceDirectory)) {
				$this->output->error("Source directory does not exist: {$sourceDirectory}");
				return null;
			}
			
			return [
				'manifest'        => $manifest,
				'publisher'       => $publisher,
				'projectRoot'     => $projectRoot,
				'sourceDirectory' => $this->discover->resolvePath($sourceDirectory)
			];
		}
		
		/**
		 * Show preview of files to be published and get user confirmation
		 * @param array $publishData
		 * @param bool $force
		 * @param bool $overwrite
		 * @return bool True to proceed, false to cancel
		 */
		private function showPublishPreview(array $publishData, bool $force, bool $overwrite): bool {
			// Validate source files exist
			if (!$this->validateSourceFiles($publishData)) {
				return false;
			}
			
			// Show what will happen
			$this->displayPublishingPreview($publishData, $overwrite);
			
			// Get user confirmation
			return $this->getUserConfirmation($force);
		}
		
		/**
		 * Validate that all source files exist
		 * @param array $publishData
		 * @return bool True if all source files exist, false otherwise
		 */
		private function validateSourceFiles(array $publishData): bool {
			$missingFiles = [];
			
			foreach ($publishData['manifest']['files'] as $file) {
				$sourcePath = rtrim($publishData['sourceDirectory'], '/') . '/' . ltrim($file['source'], '/');
				
				if (!file_exists($sourcePath)) {
					$missingFiles[] = [
						'source'     => $file['source'],
						'sourcePath' => $sourcePath
					];
				}
			}
			
			if (!empty($missingFiles)) {
				$this->output->error("Source files not found:");
				foreach ($missingFiles as $file) {
					$this->output->writeLn("  • " . $file['source'] . " (expected at: " . $file['sourcePath'] . ")");
				}
				return false;
			}
			
			return true;
		}
		
		/**
		 * Display the publishing preview showing what files will be published
		 * @param array $publishData
		 * @param bool $overwrite
		 * @return void
		 */
		private function displayPublishingPreview(array $publishData, bool $overwrite): void {
			$this->output->writeLn("<info>Files to publish:</info>");
			
			foreach ($publishData['manifest']['files'] as $file) {
				$targetPath = $this->resolveTargetPath($file['target'], $publishData['projectRoot']);
				$exists = file_exists($targetPath);
				
				if (!$overwrite && $exists) {
					$this->output->writeLn("  • " . $file['source'] . " → " . $file['target'] . " <comment>[SKIP - EXISTS]</comment>");
				} else {
					$status = $exists ? "<comment>[OVERWRITE]</comment>" : "<info>[NEW]</info>";
					$this->output->writeLn("  • " . $file['source'] . " → " . $file['target'] . " " . $status);
				}
			}
			
			$this->output->writeLn("");
			
			if (!$overwrite) {
				$this->output->writeLn("Use --overwrite flag to replace existing files");
				$this->output->writeLn("");
			}
		}
		
		/**
		 * Get user confirmation to proceed with publishing
		 * @param bool $force
		 * @return bool True to proceed, false to cancel
		 */
		private function getUserConfirmation(bool $force): bool {
			if ($force) {
				$this->output->writeLn("Force flag set, proceeding without confirmation...");
				return true;
			}
			
			return $this->askForConfirmation();
		}
		
		/**
		 * Execute the actual publishing process with rollback support
		 * @param array $publishData
		 * @param AssetPublisher $targetProvider
		 * @param bool $overwrite
		 * @return int Exit code (0 = success, 1 = error)
		 */
		private function executePublishing(array $publishData, AssetPublisher $targetProvider, bool $overwrite): int {
			$copiedFiles = [];
			$backupFiles = [];
			
			try {
				// Copy all files with backup support
				$this->copyFiles($publishData, $copiedFiles, $backupFiles, $overwrite);
				
				// Clean up backup files and show the success message
				$this->handlePublishingSuccess($backupFiles, $targetProvider);
				return 0;
				
			} catch (\Exception $e) {
				// Publishing failed - perform rollback
				$this->handlePublishingFailure($e, $copiedFiles, $backupFiles);
				return 1;
			}
		}
		
		/**
		 * Copy files from source to target with backup support
		 * @param array $publishData
		 * @param array &$copiedFiles Reference to track copied files
		 * @param array &$backupFiles Reference to track backup files
		 * @param bool $overwrite Whether to overwrite existing files
		 * @throws \Exception On any file operation failure
		 */
		private function copyFiles(array $publishData, array &$copiedFiles, array &$backupFiles, bool $overwrite): void {
			foreach ($publishData['manifest']['files'] as $file) {
				// Setup source and target paths
				$sourcePath = rtrim($publishData['sourceDirectory'], '/') . '/' . ltrim($file['source'], '/');
				$targetPath = $this->resolveTargetPath($file['target'], $publishData['projectRoot']);
				
				// Skip existing files if overwrite is not enabled
				if (!$overwrite && file_exists($targetPath)) {
					$this->output->writeLn("  • Skipped: {$targetPath} (already exists)");
					continue;
				}
				
				// Copy the file
				$this->copyFile($sourcePath, $targetPath, $copiedFiles, $backupFiles);
			}
		}
		
		/**
		 * Copy a single file with backup support and comprehensive error handling
		 *
		 * This method performs a safe file copy operation with the following features:
		 * - Validates source file existence
		 * - Creates timestamped backups of existing target files
		 * - Ensures target directory structure exists
		 * - Tracks all operations for potential rollback
		 *
		 * @param string $sourcePath Path to the source file to copy
		 * @param string $targetPath Destination path for the copied file
		 * @param array &$copiedFiles Reference to array tracking successfully copied files
		 * @param array &$backupFiles Reference to array mapping original paths to backup paths
		 * @return void
		 * @throws \Exception             On any file operation failure with descriptive message
		 */
		private function copyFile(string $sourcePath, string $targetPath, array &$copiedFiles, array &$backupFiles): void {
			// Step 1: Validate source file exists and is readable
			if (!file_exists($sourcePath)) {
				throw new \Exception("Source file not found: {$sourcePath}");
			}
			
			if (!is_readable($sourcePath)) {
				throw new \Exception("Source file is not readable: {$sourcePath}");
			}
			
			// Step 2: Handle existing target file with backup creation
			if (file_exists($targetPath)) {
				$this->createBackupFile($targetPath, $backupFiles);
			}
			
			// Step 3: Ensure target directory structure exists
			$this->ensureTargetDirectory($targetPath);
			
			// Step 4: Perform the actual file copy operation
			$this->performFileCopy($sourcePath, $targetPath);
			
			// Step 5: Track successful operation and log result
			$copiedFiles[] = $targetPath;
			$this->output->writeLn("  ✓ Copied: {$sourcePath} → {$targetPath}");
		}
		
		/**
		 * Create a timestamped backup of an existing file
		 * @param string $targetPath Path to the file that needs backing up
		 * @param array &$backupFiles Reference to backup tracking array
		 * @return void
		 * @throws \Exception           If backup creation fails
		 */
		private function createBackupFile(string $targetPath, array &$backupFiles): void {
			// Generate unique backup filename with timestamp
			$timestamp = date('Y-m-d_H-i-s');
			$backupPath = $targetPath . '.backup.' . $timestamp;
			
			// Ensure the backup path is unique (handle rapid successive calls)
			$counter = 1;
			
			while (file_exists($backupPath)) {
				$backupPath = $targetPath . '.backup.' . $timestamp . '_' . $counter;
				$counter++;
			}
			
			// Create the backup copy
			if (!copy($targetPath, $backupPath)) {
				throw new \Exception("Failed to create backup: {$targetPath} → {$backupPath}");
			}
			
			// Track the backup for potential cleanup
			$backupFiles[$targetPath] = $backupPath;
			
			// Show a message
			$this->output->writeLn("  📁 Backed up: {$targetPath} → {$backupPath}");
		}
		
		/**
		 * Ensure the target directory structure exists
		 * @param string $targetPath Full path to the target file
		 * @return void
		 * @throws \Exception           If directory creation fails
		 */
		private function ensureTargetDirectory(string $targetPath): void {
			$targetDir = dirname($targetPath);
			
			// Skip if directory already exists
			if (is_dir($targetDir)) {
				return;
			}
			
			// Create directory structure with appropriate permissions
			if (!mkdir($targetDir, 0755, true)) {
				throw new \Exception("Failed to create target directory: {$targetDir}");
			}
			
			// Show a message
			$this->output->writeLn("  📂 Created directory: {$targetDir}");
		}
		
		/**
		 * Perform the actual file copy operation with validation
		 * @param string $sourcePath Path to source file
		 * @param string $targetPath Path to target file
		 * @return void
		 * @throws \Exception           If copy operation fails
		 */
		private function performFileCopy(string $sourcePath, string $targetPath): void {
			// Attempt the file copy
			if (!copy($sourcePath, $targetPath)) {
				// Get more specific error information
				$error = error_get_last();
				$errorMessage = $error ? $error['message'] : 'Unknown error';
				
				throw new \Exception("Failed to copy file: {$sourcePath} → {$targetPath}. Error: {$errorMessage}");
			}
			
			// Verify the copy was successful by checking file existence
			if (!file_exists($targetPath)) {
				throw new \Exception("Copy operation appeared successful but target file was not created: {$targetPath}");
			}
			
			// Optional: Verify file sizes match (for additional safety)
			$sourceSize = filesize($sourcePath);
			$targetSize = filesize($targetPath);
			
			if ($sourceSize !== $targetSize) {
				throw new \Exception("File copy verification failed: size mismatch. Source: {$sourceSize} bytes, Target: {$targetSize} bytes");
			}
		}
		
		/**
		 * Handle successful publishing completion
		 * @param array $backupFiles
		 * @param AssetPublisher $targetProvider
		 */
		private function handlePublishingSuccess(array $backupFiles, AssetPublisher $targetProvider): void {
			$this->cleanupBackupFiles($backupFiles);
			
			$this->output->writeLn("");
			$this->output->writeLn("<info>Assets published successfully!</info>");
			$this->output->writeLn("");
			$this->output->writeLn($targetProvider->getPostPublishInstructions());
		}
		
		/**
		 * Handle publishing failure with rollback
		 * @param \Exception $e
		 * @param array $copiedFiles
		 * @param array $backupFiles
		 */
		private function handlePublishingFailure(\Exception $e, array $copiedFiles, array $backupFiles): void {
			$this->output->writeLn("");
			$this->output->error("Publishing failed: " . $e->getMessage());
			$this->output->writeLn("<comment>Performing rollback...</comment>");
			
			$this->performRollback($copiedFiles, $backupFiles);
		}
		
		/**
		 * Check if a path is absolute
		 * @param string $path
		 * @return bool
		 */
		private function isAbsolutePath(string $path): bool {
			return $path[0] === '/' || (strlen($path) > 1 && $path[1] === ':');
		}
		
		/**
		 * Resolve target path, making it absolute if relative
		 * @param string $targetPath
		 * @param string $projectRoot
		 * @return string
		 */
		private function resolveTargetPath(string $targetPath, string $projectRoot): string {
			if ($this->isAbsolutePath($targetPath)) {
				return $targetPath;
			}
			
			return rtrim($projectRoot, '/') . '/' . ltrim($targetPath, '/');
		}
		
		/**
		 * Clean up backup files after successful publishing
		 * @param array $backupFiles
		 * @return void
		 */
		private function cleanupBackupFiles(array $backupFiles): void {
			foreach ($backupFiles as $backupPath) {
				if (file_exists($backupPath)) {
					unlink($backupPath);
				}
			}
			
			if (!empty($backupFiles)) {
				$this->output->writeLn("  Cleaned up " . count($backupFiles) . " backup file(s)");
			}
		}
		
		/**
		 * Perform rollback by removing copied files and restoring backups
		 * @param array $copiedFiles
		 * @param array $backupFiles
		 * @return void
		 */
		private function performRollback(array $copiedFiles, array $backupFiles): void {
			$rollbackErrors = [];
			
			// Remove files that were successfully copied
			foreach ($copiedFiles as $filePath) {
				if (file_exists($filePath)) {
					if (!unlink($filePath)) {
						$rollbackErrors[] = "Failed to remove: {$filePath}";
					} else {
						$this->output->writeLn("  Removed: {$filePath}");
					}
				}
			}
			
			// Restore backup files
			foreach ($backupFiles as $originalPath => $backupPath) {
				if (file_exists($backupPath)) {
					if (!copy($backupPath, $originalPath)) {
						$rollbackErrors[] = "Failed to restore backup: {$backupPath} to {$originalPath}";
					} else {
						$this->output->writeLn("  Restored: {$backupPath} → {$originalPath}");
						unlink($backupPath);
					}
				}
			}
			
			if (empty($rollbackErrors)) {
				$this->output->writeLn("<info>Rollback completed successfully</info>");
			} else {
				$this->output->writeLn("<error>Rollback completed with errors:</error>");
				foreach ($rollbackErrors as $error) {
					$this->output->writeLn("  {$error}");
				}
			}
		}
		
		/**
		 * Display comprehensive usage help for the canvas:publish command
		 * @return void
		 */
		private function showUsageHelp(): void {
			$this->output->writeLn("<comment>Use publish to add assets using configured publishers</comment>");
			$this->output->writeLn("");
			
			$this->output->writeLn("<info>USAGE:</info>");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish [publisher] [options]");
			$this->output->writeLn("");
			
			$this->output->writeLn("<info>ARGUMENTS:</info>");
			$this->output->writeLn("  <comment>publisher</comment>            Name of the publisher to use");
			$this->output->writeLn("");
			
			$this->output->writeLn("<info>OPTIONS:</info>");
			$this->output->writeLn("  <comment>--list</comment>              List all available publishers");
			$this->output->writeLn("  <comment>--force</comment>             Skip all interactive prompts and confirmations");
			$this->output->writeLn("  <comment>--overwrite</comment>         Overwrite existing files (creates backups)");
			$this->output->writeLn("  <comment>--help</comment>              Display help for a specific publisher or general help");
			$this->output->writeLn("");
			
			$this->output->writeLn("<info>NOTE:</info>");
			$this->output->writeLn("  By default, existing files are skipped. Use --overwrite to replace them.");
			$this->output->writeLn("");
			
			$this->output->writeLn("<info>EXAMPLES:</info>");
			$this->output->writeLn("  <comment># List all available publishers</comment>");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish --list");
			$this->output->writeLn("");
			$this->output->writeLn("  <comment># Publish new files only (skip existing)</comment>");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish production");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish staging");
			$this->output->writeLn("");
			$this->output->writeLn("  <comment># Publish and overwrite existing files</comment>");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish production --overwrite");
			$this->output->writeLn("");
			$this->output->writeLn("  <comment># Skip interactive prompts (non-interactive mode)</comment>");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish production --force");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish production --overwrite --force");
			$this->output->writeLn("");
			$this->output->writeLn("  <comment># Show help information</comment>");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish");
			$this->output->writeLn("  php ./vendor/bin/sculpt canvas:publish production --help");
		}
		
		/**
		 * Displays an error message when asset publishing fails
		 * @param AssetPublisher $targetProvider The asset publisher that failed to publish
		 * @return void
		 */
		private function showCannotPublishError(AssetPublisher $targetProvider): void {
			// Display the main error message to the user
			$this->output->error("Cannot publish assets");
			
			// Add a blank line for better readability
			$this->output->writeLn("");
			
			// Display the specific reason why publishing failed, obtained from the target provider
			$this->output->writeLn($targetProvider->getCannotPublishReason());
		}
		
		/**
		 * Prompts the user for confirmation before proceeding with an action.
		 * @return bool Returns true if user confirms with 'y', false otherwise.
		 */
		private function askForConfirmation(): bool {
			// Ask for confirmation
			$confirmation = $this->input->ask("Proceed? (y/N)");
			
			// Check if the user entered 'y' (case-insensitive)
			if ($confirmation && strtolower($confirmation) === 'y') {
				return true; // User confirmed - proceed with the action
			}
			
			// Any input other than 'y' is treated as cancellation
			$this->output->writeLn("Cancelled.");
			
			// User canceled or provided invalid input
			return false;
		}
	}