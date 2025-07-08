<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class Length implements AnnotationInterface {
		
		protected array $parameters;
		
		/**
		 * Length constructor.
		 * @param array $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns all parameters
		 * @return array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns true if the 'property' field is populated, false if not
		 * @return bool
		 */
		public function hasProperty(): bool {
			return !empty($this->parameters['property']);
		}
		
		/**
		 * Returns the value of 'column'
		 * @return string
		 */
		public function getProperty(): string {
			return $this->parameters['property'] ?? '';
		}
		
		/**
		 * Returns the minimum length
		 * @return int|null
		 */
		public function getMin(): ?int {
			return $this->parameters['min'] ?? null;
		}
		
		/**
		 * Returns the maximum length
		 * @return int|null
		 */
		public function getMax(): ?int {
			return $this->parameters['max'] ?? null;
		}
		
		/**
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->parameters['message'] ?? null;
		}
	}