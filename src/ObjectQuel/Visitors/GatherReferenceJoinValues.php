<?php
	
	namespace Services\ObjectQuel\Visitors;
	
	use Services\ObjectQuel\Ast\AstIdentifier;
	use Services\ObjectQuel\Ast\AstRangeDatabase;
	use Services\ObjectQuel\AstInterface;
	use Services\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor class that detects if an AST node contains references to JSON sources
	 * This visitor is used to identify mixed data source references in query conditions
	 */
	class GatherReferenceJoinValues implements AstVisitorInterface {
		
		private array $identifiers = [];
		
		/**
		 * Returns a list of range name used in the  query
		 * @param AstInterface $node The current node being visited in the AST
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// We only care about identifier nodes, since these reference fields from ranges
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			/**
			 * Only use root nodes
			 */
			if (!$node->isRoot()) {
				return;
			}
			
			/**
			 * Only use database ranges
			 */
			if (!$node->getRange() instanceof AstRangeDatabase) {
				return;
			}
			
			// If we reached here, we found a reference
			// Throw an exception to signal this to the calling method
			$this->identifiers[] = $node;
		}
		
		/**
		 * Returns the gathered identifiers
		 * @return AstIdentifier[]
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
	}