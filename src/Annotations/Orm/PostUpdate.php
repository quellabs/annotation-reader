<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    class PostUpdate {
        
        protected $parameters;
        
        /**
         * OneToMany constructor.
         * @param array $parameters
         */
        public function __construct(array $parameters) {
            $this->parameters = $parameters;
        }
    }