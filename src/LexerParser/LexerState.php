<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	/**
	 * LexerState class
	 */
	class LexerState {
		
		/** @var int Current position in the input stream */
		private int $pos;
		
		/** @var Token Lookahead token for predictive parsing */
		private Token $lookahead;
		
		/**
		 * Constructs a new LexerState with the given parameters
		 * @param int $pos Current position in the input
		 * @param Token $lookahead Lookahead token for predictive parsing
		 */
		public function __construct(
			int    $pos,
			Token  $lookahead,
		) {
			$this->pos = $pos;
			$this->lookahead = $lookahead;
		}
		
		/**
		 * Gets the current position in the input stream
		 * @return int Current position
		 */
		public function getPos(): int {
			return $this->pos;
		}
		
		/**
		 * Gets the lookahead token
		 * Used for predictive parsing and decision making
		 * @return Token The lookahead token
		 */
		public function getLookahead(): Token {
			return $this->lookahead;
		}
	}