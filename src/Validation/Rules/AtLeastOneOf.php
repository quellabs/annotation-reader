<?php
	
	namespace Quellabs\Canvas\Validation\Rules;
	
	use Quellabs\Canvas\Validation\ValidationRuleInterface;
	
	class AtLeastOneOf implements ValidationRuleInterface {
		
		protected $conditions;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions = []) {
			$this->conditions = $conditions;
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions() : array {
			return $this->conditions;
		}
		
		public function validate($value): bool {
			$counter = 0;
			
			foreach($this->conditions as $condition) {
				if ($condition->validate($value)) {
					++$counter;
				}
			}
			
			return $counter > 0;
		}
		
		public function getError(): string {
			if (!isset($this->conditions["message"])) {
				return "At least one of the conditions should be fulfilled.";
			}
			
			return $this->conditions["message"];
		}
	}