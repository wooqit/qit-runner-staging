<?php

namespace CI_CLI\EcosystemImpact;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class EcosystemUsageVisitor extends NodeVisitorAbstract {
	private string $file;

	private string $origin;

	/** @var string[] */
	private array $consumer_symbols = [];

	/** @var string[] */
	private array $declared_symbols = [];

	/** @var string[] */
	private array $declared_functions = [];

	/** @var array<int,array<string,mixed>> */
	private array $usages = [];

	public function __construct( string $file, string $origin ) {
		$this->file   = $file;
		$this->origin = $origin;
	}

	public function enterNode( Node $node ) {
		if (
			$node instanceof Node\Stmt\Class_
			|| $node instanceof Node\Stmt\Interface_
			|| $node instanceof Node\Stmt\Trait_
			|| $node instanceof Node\Stmt\Enum_
		) {
			$declaration_name         = $this->declaration_name( $node );
			$this->consumer_symbols[] = $declaration_name;
			if ( $declaration_name !== '' ) {
				$this->declared_symbols[] = $declaration_name;
			}
		}

		if ( $node instanceof Node\Stmt\Function_ ) {
			$declaration_name = $this->declaration_name( $node );
			if ( $declaration_name !== '' ) {
				$this->declared_functions[] = $declaration_name;
			}
		}

		if ( $node instanceof Node\Stmt\Class_ ) {
			$this->add_name( $node->extends, 'class_extend' );
			foreach ( $node->implements as $interface ) {
				$this->add_name( $interface, 'class_implement' );
			}
		} elseif ( $node instanceof Node\Stmt\Interface_ ) {
			foreach ( $node->extends as $interface ) {
				$this->add_name( $interface, 'class_extend' );
			}
		} elseif ( $node instanceof Node\Stmt\Enum_ ) {
			foreach ( $node->implements as $interface ) {
				$this->add_name( $interface, 'class_implement' );
			}
		} elseif ( $node instanceof Node\Stmt\TraitUse ) {
			foreach ( $node->traits as $trait ) {
				$this->add_name( $trait, 'trait_use' );
			}
		} elseif ( $node instanceof Node\Expr\New_ || $node instanceof Node\Expr\Instanceof_ ) {
			$this->add_name( $node->class, 'class_ref' );
		} elseif ( $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch ) {
			$this->add_name( $node->class, 'class_ref', $this->identifier_string( $node->name ) );
		} elseif ( $node instanceof Node\Expr\ClassConstFetch ) {
			$this->add_name( $node->class, 'class_ref', $this->identifier_string( $node->name ) );
		} elseif ( $node instanceof Node\Expr\FuncCall ) {
			$this->add_function( $node->name );
		} elseif ( $node instanceof Node\Param ) {
			$this->add_type( $node->type );
		} elseif ( $node instanceof Node\Stmt\Property ) {
			$this->add_type( $node->type );
		} elseif ( $node instanceof Node\FunctionLike ) {
			$this->add_type( $node->getReturnType() );
		} elseif ( $node instanceof Node\Stmt\Catch_ ) {
			foreach ( $node->types as $type ) {
				$this->add_name( $type, 'class_ref' );
			}
		} elseif ( $node instanceof Node\Attribute ) {
			$this->add_name( $node->name, 'class_ref' );
		}

		return null;
	}

	public function leaveNode( Node $node ) {
		if (
			$node instanceof Node\Stmt\Class_
			|| $node instanceof Node\Stmt\Interface_
			|| $node instanceof Node\Stmt\Trait_
			|| $node instanceof Node\Stmt\Enum_
		) {
			array_pop( $this->consumer_symbols );
		}

		return null;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_usages(): array {
		return $this->usages;
	}

	/** @return string[] */
	public function get_declared_symbols(): array {
		return $this->declared_symbols;
	}

	/** @return string[] */
	public function get_declared_functions(): array {
		return $this->declared_functions;
	}

	/**
	 * @param Node\Identifier|Node\Name|Node\ComplexType|null $type
	 */
	private function add_type( $type ): void {
		if ( $type instanceof Node\Name ) {
			$this->add_name( $type, 'class_ref' );
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
	private function add_name( $name, string $usage_kind, string $member = '' ): void {
		if ( ! $name instanceof Node\Name ) {
			return;
		}

		$surface = $this->name_string( $name );
		if ( ! $this->is_woocommerce_class_like( $surface ) ) {
			return;
		}

		$this->add_usage( $surface, $usage_kind, $name->getStartLine(), $member );
	}

	/** @param Node\Name|Node\Expr $name */
	private function add_function( $name ): void {
		if ( ! $name instanceof Node\Name ) {
			return;
		}

		$surface = ltrim( $this->name_string( $name ), '\\' );
		if ( strpos( $surface, '\\' ) !== false || preg_match( '/^(?:wc|woocommerce)_[a-z0-9_]+$/iD', $surface ) !== 1 ) {
			return;
		}

		$this->add_usage( $surface, 'function', $name->getStartLine() );
	}

	private function add_usage( string $surface, string $usage_kind, int $line, string $member = '' ): void {
		$surface = ltrim( trim( $surface ), '\\' );
		$usage   = [
			'surface'         => $surface,
			'surface_key'     => SurfaceNormalizer::surface_key( $surface ),
			'usage_kind'      => $usage_kind,
			'file'            => $this->file,
			'line'            => $line,
			'origin'          => $this->origin,
			'consumer_symbol' => $this->current_consumer_symbol(),
		];

		if ( $member !== '' ) {
			$usage['member']     = $member;
			$usage['member_key'] = SurfaceNormalizer::member_key( $member );
		}

		$this->usages[] = $usage;
	}

	private function is_woocommerce_class_like( string $surface ): bool {
		$surface = ltrim( trim( $surface ), '\\' );
		return stripos( $surface, 'Automattic\\WooCommerce\\' ) === 0
			|| preg_match( '/^(?:WC(?:_[A-Za-z0-9_]+)?|WooCommerce)$/iD', $surface ) === 1;
	}

	private function name_string( Node\Name $name ): string {
		$resolved = $name->getAttribute( 'resolvedName' );
		if ( $resolved instanceof Node\Name ) {
			return $resolved->toString();
		}

		return $name->toString();
	}

	/** @param Node\Identifier|Node\VarLikeIdentifier|Node\Expr|string|null $identifier */
	private function identifier_string( $identifier ): string {
		if ( $identifier instanceof Node\Identifier || $identifier instanceof Node\VarLikeIdentifier ) {
			return $identifier->toString();
		}

		return is_string( $identifier ) ? $identifier : '';
	}

	private function current_consumer_symbol(): string {
		if ( empty( $this->consumer_symbols ) ) {
			return '';
		}

		return (string) end( $this->consumer_symbols );
	}

	/** @param Node\Stmt\Class_|Node\Stmt\Interface_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Function_ $node */
	private function declaration_name( Node $node ): string {
		$namespaced_name = $node->getAttribute( 'namespacedName' );
		if ( $namespaced_name instanceof Node\Name ) {
			return $namespaced_name->toString();
		}

		if ( property_exists( $node, 'namespacedName' ) && $node->namespacedName instanceof Node\Name ) {
			return $node->namespacedName->toString();
		}

		if ( isset( $node->name ) && $node->name instanceof Node\Identifier ) {
			return $node->name->toString();
		}

		return '';
	}
}
