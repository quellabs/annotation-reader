<?php
	
	
	namespace Services\ObjectQuel\Visitors;
	
	use Services\EntityManager\EntityManager;
	use Services\EntityManager\EntityStore;
	use Services\ObjectQuel\Ast\Ast;
	use Services\ObjectQuel\Ast\AstEntity;
	use Services\ObjectQuel\Ast\AstIdentifier;
	use Services\ObjectQuel\Ast\AstRangeDatabase;
	use Services\ObjectQuel\AstInterface;
	use Services\ObjectQuel\AstVisitorInterface;
	use Services\ObjectQuel\EntityNotFoundException;
	use Services\ObjectQuel\QuelException;
	
	/**
	 * Class EntityProcessRange
	 * If a given entity is a range, fetch the attached entity and
	 * store it in the AstEntity node.
	 */
	class EntityProcessMacro implements AstVisitorInterface {
		
		/**
		 * Array of macros
		 * @var array $ranges
		 */
		private array $macros;
		
		/**
		 * EntityProcessMacro constructor.
		 * @param array $macros
		 */
		public function __construct(array $macros) {
			$this->macros = $macros;
		}
		
		/**
		 * Visit a node in the AST.
		 * @param AstInterface $node The node to visit.
		 * @eeturn void
		 */
		public function visitNode(AstInterface $node): void {
			if ($node instanceof AstEntity) {
				$entityName = $node->getName();
				
				if (array_key_exists($entityName, $this->macros) && ($this->macros[$entityName] instanceof AstEntity)) {
					$node->setName($this->macros[$entityName]->getName());
					$node->setRange($this->macros[$entityName]->getRange());
				}
			}
		}
	}