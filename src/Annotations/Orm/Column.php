<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    use Quellabs\AnnotationReader\AnnotationInterface;
    
    class Column  implements AnnotationInterface {
        
        protected $parameters;
    
        /**
         * Table constructor.
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
		
	    public function getName() {
            return $this->parameters["name"];
        }

        public function getType() {
            return $this->parameters["type"];
        }

        public function getLength() {
            return $this->parameters["length"];
        }

        public function hasDefault(): bool {
            return array_key_exists("default", $this->parameters);
        }
        
        public function getDefault() {
            return $this->parameters["default"];
        }
        
        public function isPrimaryKey(): bool {
            return $this->parameters["primary_key"] ?? false;
        }

        public function isAutoIncrement(): bool {
            return $this->parameters["auto_increment"] ?? false;
        }

        public function isNullable(): bool {
            return $this->parameters["nullable"] ?? false;
        }
    }