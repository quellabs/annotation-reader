<?php
	
	namespace Quellabs\Canvas\Routing;
	
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\Canvas\Kernel;
	use ReflectionException;
	use Quellabs\Canvas\Annotations\Route;
	use Symfony\Component\HttpFoundation\Request;
	
	class AnnotationResolver {
		
		/**
		 * @var Kernel Kernel object, used among other things for service discovery
		 */
		private Kernel $kernel;
		
		/**
		 * FetchAnnotations constructor.
		 * @param Kernel $kernel
		 */
		public function __construct(Kernel $kernel) {
			$this->kernel = $kernel;
		}
		
		/**
		 * Resolves an HTTP request to a controller, method, and route variables
		 * Matches the request URL against controller route annotations to find the correct endpoint
		 * @param Request $request The incoming HTTP request to resolve
		 * @return array|null Returns matched route info or null if no match found
		 *         array contains: ['controller' => string, 'method' => string, 'variables' => array]
		 */
		public function resolve(Request $request): ?array {
			// Get the request URL and method
			$baseUrl = ltrim($request->getRequestUri(), '/');
			$requestUrl = array_filter(explode('/', $baseUrl), function ($e) {
				return $e !== '';
			});
			
			// Get controller classes from the standard location
			$controllerDir = $this->getControllerDirectory();
			$controllers = $this->kernel->getDiscover()->findClassesInDirectory($controllerDir);
			
			// Collect all routes with their priorities
			$allRoutes = [];
			foreach ($controllers as $controller) {
				$routes = $this->getRoutesFromController($controller, $request->getMethod());
				$allRoutes = array_merge($allRoutes, $routes);
			}
			
			// Sort routes by priority (higher priority first)
			// Exact routes get higher priority than wildcard routes
			usort($allRoutes, function ($a, $b) {
				return $b['priority'] <=> $a['priority'];
			});
			
			// Try to match routes in priority order
			foreach ($allRoutes as $routeData) {
				$matchedRoute = $this->tryMatchRoute($routeData, $requestUrl);
				
				if ($matchedRoute) {
					return $matchedRoute;
				}
			}
			
			return null;
		}
		
		/**
		 * Gets all potential routes from a controller with their priorities
		 * @param string $controller
		 * @param string $requestMethod
		 * @return array
		 */
		private function getRoutesFromController(string $controller, string $requestMethod): array {
			// Initialize an empty array to store matching routes
			$routes = [];
			
			try {
				// Retrieve all route annotations from the controller's methods
				// This likely uses reflection to scan the controller class for route annotations
				$routeAnnotations = $this->getMethodRouteAnnotations($controller);
				
				// Loop through each method and its associated route annotation
				foreach ($routeAnnotations as $method => $routeAnnotation) {
					// Filter routes by HTTP method (GET, POST, PUT, DELETE, etc.)
					// Skip this route if it doesn't support the requested HTTP method
					if (!in_array($requestMethod, $routeAnnotation->getMethods())) {
						continue;
					}
					
					// Extract the route path pattern (e.g., "/users/{id}", "/api/products")
					$routePath = $routeAnnotation->getRoute();
					
					// Calculate priority for route matching order
					// Routes with more specific patterns typically get higher priority
					$priority = $this->calculateRoutePriority($routePath);
					
					// Build route data structure with all necessary information
					$routes[] = [
						'controller'       => $controller,        // Controller class name
						'method'           => $method,            // Method name to invoke
						'route_annotation' => $routeAnnotation,   // Full annotation object
						'route_path'       => $routePath,         // URL pattern string
						'priority'         => $priority           // Numeric priority for sorting
					];
				}
			} catch (\Exception $e) {
				// Silently handle any reflection or annotation parsing errors
				// Routes array will remain empty if controller scanning fails
				// Log exception for debugging if needed
			}
			
			// Return array of route definitions, empty if no matches or errors occurred
			return $routes;
		}
		
		/**
		 * Calculate priority for route (higher = more priority)
		 * Exact routes get higher priority than wildcard routes
		 * @param string $routePath
		 * @return int
		 */
		private function calculateRoutePriority(string $routePath): int {
			// Base priority
			$priority = 1000;
			
			// Reduce priority for each wildcard or variable
			$segments = explode('/', ltrim($routePath, '/'));
			
			foreach ($segments as $segment) {
				if ($segment === '*' || $segment === '**') {
					$priority -= 100; // Wildcards get lower priority
				} elseif (!empty($segment) && $segment[0] === '{') {
					$priority -= 10; // Variables get slightly lower priority
				}
			}
			
			// Longer specific paths get higher priority
			$priority += count($segments) * 5;
			
			// Return the priority
			return $priority;
		}
		
		/**
		 * Attempts to match a specific route against the request
		 * Note: HTTP method validation is already performed in getRoutesFromController()
		 * before this method is called, so we only need to validate the URL pattern.
		 * @param array $routeData Route configuration containing path, controller, method
		 * @param array $requestUrl Parsed URL segments from the request
		 * @return array|null Route match data with controller/method/variables, or null if no match
		 */
		private function tryMatchRoute(array $routeData, array $requestUrl): ?array {
			// Parse the route path into segments for comparison
			$routePath = $routeData['route_path'];
			$routeSegments = $this->parseRoutePath($routePath);
			
			// if URL pattern matches - return route data
			$urlVariables = [];
			
			if ($this->urlMatchesRoute($requestUrl, $routeSegments, $urlVariables)) {
				return [
					'controller' => $routeData['controller'],
					'method'     => $routeData['method'],
					'variables'  => $urlVariables
				];
			}
			
			// URL pattern doesn't match
			return null;
		}
		
		/**
		 * Parses a route path string into clean segments for matching
		 * @param string $routePath Raw route path like '/users/{id}/posts'
		 * @return array Clean route segments like ['users', '{id}', 'posts']
		 */
		private function parseRoutePath(string $routePath): array {
			// Remove leading slash and split into segments
			$segments = explode('/', ltrim($routePath, '/'));
			
			// Filter out empty segments (handles multiple slashes, trailing slashes)
			return array_filter($segments, function ($segment) {
				return $segment !== '';
			});
		}
		
		/**
		 * Gets the absolute path to the controllers directory
		 * @return string Absolute path to controllers directory
		 */
		private function getControllerDirectory(): string {
			// Get the project root
			$projectRoot = $this->kernel->getDiscover()->getProjectRoot();
			
			// Construct the full path
			$fullPath = $projectRoot . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Controllers";
			
			// Make sure the directory exists
			if (!is_dir($fullPath)) {
				return "";
			}
			
			// Return the full path
			return realpath($fullPath);
		}
		
		/**
		 * Determines if a URL matches a route pattern and extracts variables
		 *
		 * Supports:
		 * - Static segments: /users/profile
		 * - Variables: /users/{id}
		 * - Single wildcards: /files/* or /users/{id:*}
		 * - Multi-segment wildcards: /api/** or /files/{path:**}
		 *
		 * @param array $requestUrl URL segments to match
		 * @param array $routePattern Route pattern segments
		 * @param array &$variables Extracted variables (passed by reference)
		 * @return bool True if URL matches pattern
		 */
		protected function urlMatchesRoute(array $requestUrl, array $routePattern, array &$variables): bool {
			// Initialize pointers to track current position in both arrays
			$routeIndex = 0;  // Current position in route pattern
			$urlIndex = 0;    // Current position in request URL
			
			// Main matching loop - continue while there are segments to process
			while ($this->hasMoreSegments($routePattern, $routeIndex, $requestUrl, $urlIndex)) {
				// Get the current route pattern segment to analyze
				$routeSegment = $routePattern[$routeIndex];
				
				// Handle multi-segment wildcards (/** or {name:**})
				// These consume all remaining URL segments
				if ($this->isMultiWildcard($routeSegment)) {
					// Multi-wildcards are terminal - they consume everything remaining
					// Return the result of processing this wildcard
					return $this->handleMultiWildcard($routeSegment, $requestUrl, $urlIndex, $variables);
				}
				
				// Handle single-segment wildcards (/* or {name:*}).
				// These match exactly one URL segment
				if ($this->isSingleWildcard($routeSegment)) {
					// Process the wildcard and store any named variable
					$this->handleSingleWildcard($routeSegment, $requestUrl[$urlIndex], $variables);
					
					// Move both pointers forward by one position
					++$urlIndex;
					++$routeIndex;
					
					// Continue to next iteration
					continue;
				}
				
				// Handle named variables ({id}, {name}, etc.)
				// These capture URL segments into named parameters
				if ($this->isVariable($routeSegment)) {
					// Process the variable - may consume multiple segments in some cases
					if ($this->handleVariable($routeSegment, $requestUrl, $urlIndex, $variables)) {
						// Variable handler indicates it consumed all remaining segments
						return true;
					}

					// Variable consumed one segment, advance both pointers
					++$urlIndex;
					++$routeIndex;
					
					// Continue to next iteration
					continue;
				}
				
				// Handle static segments - must match exactly
				// These are literal strings that must appear in the URL
				if ($routeSegment !== $requestUrl[$urlIndex]) {
					// Static segment doesn't match - route fails
					return false;
				}
				
				// Static segment matches - advance both pointers manually
				// (We don't use advanceIndices here for clarity/performance)
				++$routeIndex;
				++$urlIndex;
			}
			
			// All segments processed - validate that we have a complete match
			// This ensures we consumed all required segments and no extra segments remain
			return $this->validateMatch($routePattern, $routeIndex, $requestUrl, $urlIndex);
		}
		
		/**
		 * Checks if there are more segments to process in both route and URL
		 * @param array $routePattern Complete route pattern segments
		 * @param int $routeIndex Current position in route pattern
		 * @param array $requestUrl Complete URL segments
		 * @param int $urlIndex Current position in URL
		 * @return bool True if both arrays have more segments to process
		 */
		private function hasMoreSegments(array $routePattern, int $routeIndex, array $requestUrl, int $urlIndex): bool {
			return $routeIndex < count($routePattern) && $urlIndex < count($requestUrl);
		}
		
		/**
		 * Determines if a route segment is a multi-segment wildcard
		 *
		 * Multi-wildcards consume all remaining URL segments:
		 * - '**': anonymous multi-wildcard, stored as $variables['**']
		 * - '{path:**}': named multi-wildcard, stored as $variables['path']
		 * - '{files:.*}': alternative syntax for named multi-wildcard
		 *
		 * @param string $segment Route segment to check
		 * @return bool True if segment matches multiple URL segments
		 */
		private function isMultiWildcard(string $segment): bool {
			if ($segment === '**') {
				return true;
			}
			
			if (str_ends_with($segment, ':**}')) {
				return true;
			}
			
			return str_ends_with($segment, ':.*}');
		}
		
		/**
		 * Determines if a route segment is a single-segment wildcard
		 *
		 * Single wildcards match exactly one URL segment:
		 * - '*': anonymous single wildcard, stored as $variables['*']
		 * - '{file:*}': named single wildcard, stored as $variables['file']
		 *
		 * @param string $segment Route segment to check
		 * @return bool True if segment matches exactly one URL segment
		 */
		private function isSingleWildcard(string $segment): bool {
			if ($segment === '*') {
				return true;
			}
			
			return str_ends_with($segment, ':*}');
		}
		
		/**
		 * Determines if a route segment is a variable placeholder
		 *
		 * Variables are enclosed in curly braces: {id}, {slug}, {path:*}, etc.
		 * They can be simple variables or include patterns after a colon.
		 *
		 * @param string $segment Route segment to check
		 * @return bool True if segment is a variable placeholder
		 */
		private function isVariable(string $segment): bool {
			return !empty($segment) && $segment[0] === '{';
		}
		
		/**
		 * Processes a multi-segment wildcard and captures remaining URL segments
		 *
		 * Multi-wildcards always succeed and consume all remaining URL segments,
		 * effectively ending the matching process. The captured segments are joined
		 * with '/' to recreate the original path structure.
		 *
		 * @param string $segment The wildcard route segment
		 * @param array $requestUrl Complete URL segments
		 * @param int $urlIndex Current position in URL
		 * @param array &$variables Variables array to store captured values
		 * @return bool Always returns true (multi-wildcards never fail)
		 */
		private function handleMultiWildcard(string $segment, array $requestUrl, int $urlIndex, array &$variables): bool {
			// Extract all remaining URL segments from the current position onward
			// This captures everything that hasn't been matched yet
			$remainingSegments = array_slice($requestUrl, $urlIndex);
			
			// Join the remaining segments back into a path string using '/' as separator
			// This reconstructs the original URL structure for the unmatched portion
			$remainingPath = implode('/', $remainingSegments);
			
			// Check if this is an anonymous multi-wildcard (just '**')
			if ($segment === '**') {
				// Store the captured path under the generic '**' key
				$variables['**'] = $remainingPath;
				return true; // Anonymous multi-wildcard processed successfully
			}
			
			// Handle named multi-wildcard in format {varName:**}
			// Extract the custom variable name from the segment pattern
			$variableName = $this->extractVariableName($segment);
			
			// Store the captured path under the custom variable name
			// This allows routes like {path:**} to access the value via $variables['path']
			$variables[$variableName] = $remainingPath;
			
			// Named multi-wildcard processed successfully
			return true;
		}
		
		/**
		 * Processes a single-segment wildcard and captures one URL segment
		 *
		 * Single wildcards match exactly one URL segment. The captured value
		 * is stored either anonymously ('*') or with a custom variable name.
		 *
		 * @param string $segment The wildcard route segment
		 * @param string $urlSegment The current URL segment to capture
		 * @param array &$variables Variables array to store captured values
		 */
		private function handleSingleWildcard(string $segment, string $urlSegment, array &$variables): void {
			if ($segment === '*') {
				$variables['*'] = $urlSegment;
				return; // Anonymous single wildcard processed
			}
			
			// Extract variable name from {varName:*} format
			$variableName = $this->extractVariableName($segment);
			$variables[$variableName] = $urlSegment;
		}
		
		/**
		 * Processes a variable placeholder and extracts the URL segment value
		 *
		 * Variables can be:
		 * - Simple: {id} -> captures one segment as $variables['id']
		 * - With constraints: {id:numeric} -> captures with validation (not implemented)
		 * - With wildcards: {path:*} or {path:**} -> handled as wildcards
		 *
		 * @param string $segment The variable route segment
		 * @param array $requestUrl Complete URL segments
		 * @param int $urlIndex Current position in URL
		 * @param array &$variables Variables array to store captured values
		 * @return bool True if multi-wildcard consumed everything, false to continue processing
		 */
		private function handleVariable(string $segment, array $requestUrl, int $urlIndex, array &$variables): bool {
			$variableName = trim($segment, '{}');
			
			if (!str_contains($variableName, ':')) {
				// Simple variable like {id} - capture current segment
				$variables[$variableName] = $requestUrl[$urlIndex];
				return false; // Continue normal processing
			}
			
			// Handle special patterns like {id:*} or {path:**}
			[$varName, $pattern] = explode(':', $variableName, 2);
			
			if ($pattern === '*') {
				// Single wildcard variable
				$variables[$varName] = $requestUrl[$urlIndex];
				return false; // Continue normal processing
			}
			
			if ($pattern === '**' || $pattern === '.*') {
				// Multi-segment wildcard variable - consume everything remaining
				$remainingSegments = array_slice($requestUrl, $urlIndex);
				$variables[$varName] = implode('/', $remainingSegments);
				return true; // Stop processing, everything consumed
			}
			
			// Regular variable with constraint (constraint validation not implemented)
			$variables[$varName] = $requestUrl[$urlIndex];
			return false; // Continue normal processing
		}
		
		/**
		 * Extracts the variable name from a route segment
		 *
		 * Handles both simple variables and those with patterns:
		 * - {id} -> 'id'
		 * - {path:*} -> 'path'
		 * - {files:**} -> 'files'
		 *
		 * @param string $segment Route segment containing variable
		 * @return string The extracted variable name
		 */
		private function extractVariableName(string $segment): string {
			// Remove the curly braces from the segment to get the inner content
			// e.g., "{id}" becomes "id", "{path:*}" becomes "path:*"
			$variableName = trim($segment, '{}');
			
			// Check if this is a simple variable (no pattern specification)
			if (!str_contains($variableName, ':')) {
				return $variableName; // Simple variable, no pattern
			}
			
			// For variables with patterns (e.g., "path:*" or "files:**"),
			// extract just the name part before the colon
			// explode() with limit 2 ensures we only split on the first colon
			return explode(':', $variableName, 2)[0];
		}
		
		/**
		 * Validates that the route pattern and URL are completely matched
		 *
		 * After processing all segments, we need to ensure:
		 * 1. All route segments have been consumed (no missing URL parts)
		 * 2. All URL segments have been consumed (unless route ends with wildcard)
		 *
		 * @param array $routePattern The complete route pattern segments
		 * @param int $routeIndex Current position in route pattern
		 * @param array $requestUrl The complete URL segments
		 * @param int $urlIndex Current position in URL
		 * @return bool True if match is valid and complete
		 */
		private function validateMatch(array $routePattern, int $routeIndex, array $requestUrl, int $urlIndex): bool {
			// Check for unmatched route segments
			// If we haven't processed all route segments, the URL is too short
			if ($routeIndex < count($routePattern)) {
				return false; // Route expects more segments than URL provides
			}
			
			// Check for unmatched URL segments
			// If we haven't processed all URL segments, the URL is too long
			if ($urlIndex < count($requestUrl)) {
				// URL has extra segments - this is only acceptable if the route
				// ended with a wildcard that should have consumed them
				$lastRouteSegment = end($routePattern);
				return $this->isWildcardSegment($lastRouteSegment);
			}
			
			// Perfect match - all segments consumed on both sides
			return true;
		}
		
		/**
		 * Determines if a route segment is any type of wildcard
		 *
		 * Wildcards can consume extra URL segments beyond the basic pattern:
		 * - '*' or '{var:*}': matches exactly one segment
		 * - '**' or '{var:**}': matches zero or more segments
		 *
		 * @param string $segment Route segment to check
		 * @return bool True if the segment is a wildcard pattern
		 */
		private function isWildcardSegment(string $segment): bool {
			return
				$segment === '*' ||              // Simple single wildcard
				$segment === '**' ||             // Simple multi wildcard
				str_ends_with($segment, ':*}') || // Named single wildcard: {file:*}
				str_ends_with($segment, ':**}') || // Named multi wildcard: {path:**}
				str_ends_with($segment, ':.*}'); // Add this line
		}
		
		/**
		 * Returns all methods with route annotation in the class
		 * @param object|string $controller The controller class name or object instance to analyze
		 * @return array Associative array where keys are method names and values are Route annotation objects
		 *               Returns an empty array if the controller class doesn't exist or has no route annotations
		 */
		private function getMethodRouteAnnotations(object|string $controller): array {
			try {
				// Create a reflection object to analyze the controller class structure
				$reflectionClass = new \ReflectionClass($controller);
				
				// Get all methods defined in the controller class
				$methods = $reflectionClass->getMethods();
				
				// Initialize a result array to store method name => Route annotation pairs
				$result = [];
				
				// Iterate through each method to find Route annotations
				foreach ($methods as $method) {
					try {
						// Retrieve all annotations for current method
						$annotations = $this->kernel->getAnnotationsReader()->getMethodAnnotations($controller, $method->getName());
						
						// Check each annotation to find Route instances
						foreach ($annotations as $annotation) {
							// If a Route annotation is found, add it to results and skip to next method
							if ($annotation instanceof Route) {
								// Add annotation to list
								$result[$method->getName()] = $annotation;
								
								// Skip to the next method after finding a Route annotation (only one Route per method)
								continue 2;
							}
						}
					} catch (ParserException $e) {
						// Silently ignore parser exceptions for individual methods.
						// This allows processing to continue even if one method has invalid annotations
					}
				}
				
				return $result;
			} catch (ReflectionException $e) {
				// Return an empty array if the controller class doesn't exist or can't be reflected
				return [];
			}
		}
	}