<?php
	
	namespace Quellabs\ObjectQuel\CommandRunner\Commands;
	
	class TestCommand extends \Quellabs\ObjectQuel\CommandRunner\Command {
		
		public function execute(array $parameters = []): int {
			echo "run";
			return 0;
		}
		
		public static function getSignature(): string {
			return "test";
		}
		
		public static function getDescription(): string {
			return "test description";
		}
		
		public static function getHelp(): string {
			return "test help";
		}
	}