<?php
	
	namespace Quellabs\AnnotationReader\AnnotationTest;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class CacheableTest implements AnnotationInterface {
		
		protected array $parameters;
		
		/**
		 * CacheableTest constructor.
		 * @param array $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * @return array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
	}