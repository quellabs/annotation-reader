<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    class PostPersist {
        
        protected $parameters;
        
        /**
         * OneToMany constructor.
         * @param array $parameters
         */
        public function __construct(array $parameters) {
            $this->parameters = $parameters;
        }
    }