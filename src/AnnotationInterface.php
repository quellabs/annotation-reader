<?php
	
	namespace Quellabs\AnnotationReader;
	
	interface AnnotationInterface {
		
		/**
		 * Annotation constructor.
		 * @param array $parameters
		 */
		public function __construct(array $parameters);
		
		/**
		 * Returns all parameters
		 * @return array
		 */
		public function getParameters(): array;
	}