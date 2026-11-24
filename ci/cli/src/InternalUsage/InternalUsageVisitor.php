<?php

namespace CI_CLI\InternalUsage;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class InternalUsageVisitor extends NodeVisitorAbstract {
	private const INTERNAL_PREFIX = 'Automattic\\WooCommerce\\Internal\\';

	private string $file;

	/** @var array<int,array{symbol:string,kind:string,file:string,line:int}> */
	private array $findings = [];

	public function __construct( string $file ) {
		$this->file = $file;
	}

	public function enterNode( Node $node ) {
		if ( $node instanceof Node\Stmt\Use_ ) {
			foreach ( $node->uses as $use ) {
				$this->add_name( $use->name, 'import' );
			}
		} elseif ( $node instanceof Node\Stmt\GroupUse ) {
			$prefix = $this->name_string( $node->prefix );
			foreach ( $node->uses as $use ) {
				$this->add_symbol( $prefix . '\\' . $this->name_string( $use->name ), 'import', $use->getStartLine() );
			}
		} elseif ( $node instanceof Node\Stmt\Class_ ) {
			$this->add_name( $node->extends, 'extends' );
			foreach ( $node->implements as $interface ) {
				$this->add_name( $interface, 'implements' );
			}
		} elseif ( $node instanceof Node\Stmt\Interface_ ) {
			foreach ( $node->extends as $interface ) {
				$this->add_name( $interface, 'extends' );
			}
		} elseif ( $node instanceof Node\Stmt\Enum_ ) {
			foreach ( $node->implements as $interface ) {
				$this->add_name( $interface, 'implements' );
			}
		} elseif ( $node instanceof Node\Stmt\TraitUse ) {
			foreach ( $node->traits as $trait ) {
				$this->add_name( $trait, 'trait_use' );
			}
		} elseif ( $node instanceof Node\Expr\New_ ) {
			$this->add_name( $node->class, 'new' );
		} elseif ( $node instanceof Node\Expr\Instanceof_ ) {
			$this->add_name( $node->class, 'instanceof' );
		} elseif ( $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch ) {
			$this->add_name( $node->class, 'static_access' );
		} elseif ( $node instanceof Node\Expr\ClassConstFetch ) {
			$this->add_name( $node->class, 'class_constant' );
		} elseif ( $node instanceof Node\Expr\FuncCall ) {
			$this->add_name( $node->name, 'function_call' );
		} elseif ( $node instanceof Node\Expr\ConstFetch ) {
			$this->add_name( $node->name, 'constant_access' );
		} elseif ( $node instanceof Node\Param ) {
			$this->add_type( $node->type );
		} elseif ( $node instanceof Node\Stmt\Property ) {
			$this->add_type( $node->type );
		} elseif ( $node instanceof Node\FunctionLike ) {
			$this->add_type( $node->getReturnType() );
		} elseif ( $node instanceof Node\Stmt\Catch_ ) {
			foreach ( $node->types as $type ) {
				$this->add_name( $type, 'native_type' );
			}
		} elseif ( $node instanceof Node\Attribute ) {
			$this->add_name( $node->name, 'attribute' );
		} elseif ( $node instanceof Node\Scalar\String_ ) {
			$this->add_string_symbol( $node->value, $node->getStartLine() );
		}

		return null;
	}

	/** @return array<int,array{symbol:string,kind:string,file:string,line:int}> */
	public function get_findings(): array {
		return $this->findings;
	}

	/**
	 * @param Node\Identifier|Node\Name|Node\ComplexType|null $type
	 */
	private function add_type( $type ): void {
		if ( $type instanceof Node\Name ) {
			$this->add_name( $type, 'native_type' );
			return;
		}

		if ( $type instanceof Node\NullableType ) {
			$this->add_type( $type->type );
			return;
		}

		if ( $type instanceof Node\UnionType || $type instanceof Node\IntersectionType ) {
			foreach ( $type->types as $inner_type ) {
				$this->add_type( $inner_type );
			}
		}
	}

	/** @param Node\Name|Node\Stmt\Class_|Node\Expr|null $name */
	private function add_name( $name, string $kind ): void {
		if ( ! $name instanceof Node\Name ) {
			return;
		}

		$this->add_symbol( $this->name_string( $name ), $kind, $name->getStartLine() );
	}

	private function name_string( Node\Name $name ): string {
		$resolved = $name->getAttribute( 'resolvedName' );
		if ( $resolved instanceof Node\Name ) {
			return $resolved->toString();
		}

		return $name->toString();
	}

	private function add_string_symbol( string $symbol, int $line ): void {
		$symbol = ltrim( trim( $symbol ), '\\' );
		if ( preg_match( '/^[\p{L}_][\p{L}\p{N}_]*(?:\\\\[\p{L}_][\p{L}\p{N}_]*)*(?:::[\p{L}_][\p{L}\p{N}_]*)?$/uD', $symbol ) !== 1 ) {
			return;
		}

		$this->add_symbol( $symbol, 'string_reference', $line );
	}

	private function add_symbol( string $symbol, string $kind, int $line ): void {
		$symbol = ltrim( trim( $symbol ), '\\' );
		if ( stripos( $symbol, self::INTERNAL_PREFIX ) !== 0 ) {
			return;
		}

		$this->findings[] = [
			'symbol' => $symbol,
			'kind'   => $kind,
			'file'   => $this->file,
			'line'   => $line,
		];
	}
}
