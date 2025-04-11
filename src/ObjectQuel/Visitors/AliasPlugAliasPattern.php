<?php
	
	namespace Services\ObjectQuel\Visitors;
	
	use Services\ObjectQuel\Ast\AstAlias;
	use Services\ObjectQuel\Ast\AstEntity;
	use Services\ObjectQuel\Ast\AstIdentifier;
	use Services\ObjectQuel\AstInterface;
	use Services\ObjectQuel\AstVisitorInterface;
	
	class AliasPlugAliasPattern implements AstVisitorInterface {
		
		/**
		 * Voegt een unieke range toe aan de entity als deze ontbreekt
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Deze visitor behandeld alleen AstEntity
			if (!$node instanceof AstAlias) {
				return;
			}
			
			// Sla deze node over als het niet om een entity gaat
			if (!$node->getExpression() instanceof AstIdentifier) {
				return;
			}
			
			/**
			 * Sla properties over
			 */
			if ($node->getExpression()->hasParent()) {
				return;
			}
			
			// Zet de alias pattern
			$node->setAliasPattern($node->getExpression()->getRange()->getName() . ".");
		}
	}