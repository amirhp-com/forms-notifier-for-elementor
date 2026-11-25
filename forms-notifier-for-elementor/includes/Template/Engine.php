<?php
/**
 * Template engine.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Contracts\FormatterInterface;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\I18n\Strings;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Template\Format\AbstractFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Templates are parsed first and values are put in last, as opaque segments. A value is escaped for the target format
 * and never read again as markup or as template syntax.
 */
final class Engine {

	/** Generic example values by field type. */
	private const SAMPLES = [
		'email'  => 'sara@example.com',
		'tel'    => '+989120000000',
		'number' => '42',
		'url'    => 'https://example.com/',
		'date'   => '2026-01-01',
		'time'   => '10:30',
	];

	/**
	 * Render a template to a channel format. Never throws.
	 *
	 * @param string|array $template Canonical markup; string or localized text.
	 * @param Context      $context  Context.
	 * @param string       $format   Target format id.
	 * @return string rendered text, NOT split
	 */
	public static function render( $template, Context $context, string $format = 'text' ): string {
		try {
			$context  = self::context( $context );
			$source   = (string) apply_filters( 'fnfe_template', self::source( $template, $context ), $context->channel, $context->purpose, $context );
			$segments = self::segments( Parser::parse( Markup::clean_text( $source ) )['nodes'], $context );
			return trim( self::assemble( $segments, Registry::formatter( $format ) ) );
		} catch ( \Throwable $e ) {
			Logger::error( 'template', $e->getMessage() );
			return '';
		}
	}

	/**
	 * Render a one-line value (subject, button text, URL, pattern variable): tags resolved, no markup, plain.
	 *
	 * @param string|array $template  Template.
	 * @param Context      $context   Context.
	 * @param bool         $urlencode Encode every inserted value. A URL at the very start of the template is kept as a URL.
	 */
	public static function render_inline( $template, Context $context, bool $urlencode = false ): string {
		try {
			$context  = self::context( $context );
			$source   = Markup::clean_text( self::source( $template, $context ) );
			$segments = self::segments( Parser::parse( $source )['nodes'], $context );
			$out      = '';
			foreach ( self::plain_segments( $segments ) as $segment ) {
				$text = $segment[1];
				if ( $segment[0] === 'v' && $urlencode ) {
					$url  = trim( $out ) === '' && preg_match( '~^https?://~i', trim( $text ) ) ? Markup::url( $text ) : '';
					$text = $url === '' ? rawurlencode( $text ) : $url;
				}
				$out .= $text;
			}
			return trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
		} catch ( \Throwable $e ) {
			Logger::error( 'template', $e->getMessage() );
			return '';
		}
	}

	/**
	 * Render a template that is not canonical markup (JSON bodies, plain bodies): literal text stays as it is, every
	 * inserted value goes through $escape.
	 *
	 * @param string   $template Template.
	 * @param Context  $context  Context.
	 * @param callable $escape   Receives the raw value.
	 */
	public static function render_raw( string $template, Context $context, callable $escape ): string {
		try {
			$out = '';
			foreach ( self::segments( Parser::parse( str_replace( [ "\r\n", "\r" ], "\n", $template ) )['nodes'], self::context( $context ) ) as $segment ) {
				if ( $segment[0] === 'c' ) {
					$out .= $segment[1];
				} elseif ( $segment[0] === 'v' ) {
					$out .= $escape( $segment[1] );
				} else {
					$out .= $escape( self::plain( $segment[2] ) );
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			Logger::error( 'template', $e->getMessage() );
			return '';
		}
	}

	/**
	 * Resolve one tag to its raw value ('' when unknown).
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Tag argument.
	 * @param Context $context Context.
	 */
	public static function tag( string $name, string $arg, Context $context ): string {
		$segments = Tags::segments( $name, $arg, $context );
		return $segments === null ? (string) Tags::value( $name, $arg, $context ) : self::plain( $segments );
	}

	/**
	 * Syntax check for the editor.
	 *
	 * @param string $template Template.
	 * @return array[] [ 'line' => int, 'message' => string ]
	 */
	public static function validate( string $template ): array {
		$parsed = Parser::parse( Markup::clean_text( $template ) );
		$errors = array_merge( $parsed['errors'], self::check( $parsed['nodes'] ) );
		usort(
			$errors,
			static function ( array $a, array $b ): int {
				return $a['line'] <=> $b['line'];
			}
		);
		return $errors;
	}

	/**
	 * Sample submission for previews: from the form's stored field snapshot, or a generic one.
	 *
	 * @param int    $form_id Form row id, 0 = generic sample.
	 * @param string $lang    Language.
	 */
	public static function sample_submission( int $form_id = 0, string $lang = '' ): Submission {
		$row    = $form_id > 0 ? FormRepository::find( $form_id ) : null;
		$fields = [];
		$files  = [];
		foreach ( $row && is_array( $row['fields'] ) ? $row['fields'] : [] as $definition ) {
			if ( ! is_array( $definition ) || (string) ( $definition['id'] ?? '' ) === '' ) {
				continue;
			}
			$field                  = self::sample_field( $definition );
			$fields[ $field['id'] ] = $field;
			if ( $field['is_file'] ) {
				$files[] = [
					'field_id' => $field['id'],
					'name'     => $field['value'],
					'size'     => 48211,
					'mime'     => 'application/pdf',
				];
			}
		}
		if ( ! $fields ) {
			$generic = [
				[ 'name', 'text', __( 'Name', 'forms-notifier-for-elementor' ) ],
				[ 'email', 'email', __( 'Email', 'forms-notifier-for-elementor' ) ],
				[ 'phone', 'tel', __( 'Phone', 'forms-notifier-for-elementor' ) ],
				[ 'message', 'textarea', __( 'Message', 'forms-notifier-for-elementor' ) ],
			];
			foreach ( $generic as $item ) {
				$fields[ $item[0] ] = self::sample_field(
					[
						'id'    => $item[0],
						'type'  => $item[1],
						'label' => $item[2],
					]
				);
			}
		}

		return Submission::from_array(
			[
				'id'          => 981,
				'uuid'        => '8f14e45fceea167a5a36dedd4bea2543',
				'form_id'     => $row ? (int) $row['id'] : 0,
				'form_key'    => $row ? (string) $row['form_key'] : 'api:sample',
				'post_id'     => $row ? (int) $row['post_id'] : 0,
				'element_id'  => $row ? (string) ( $row['element_id'] ?? '' ) : '',
				'source'      => $row ? (string) $row['source'] : 'pro',
				'form_name'   => $row && (string) $row['name'] !== '' ? (string) $row['name'] : __( 'Sample form', 'forms-notifier-for-elementor' ),
				'lang'        => $lang,
				'created_gmt' => gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) ),
				'fields'      => $fields,
				'files'       => $files,
				'meta'        => [
					'page_url'   => home_url( '/contact/' ),
					'page_title' => __( 'Contact', 'forms-notifier-for-elementor' ),
					'user_ip'    => '203.0.113.5',
					'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
					'via'        => 'test',
					'utm'        => [ 'source' => 'newsletter' ],
				],
			]
		);
	}

	/**
	 * Text of a template in the render language, with the translation of the multilingual plugin.
	 *
	 * @param string|array $template String or localized text.
	 * @param Context      $context  Context.
	 */
	public static function source( $template, Context $context ): string {
		$base = is_array( $template ) ? ( $template['_'] ?? '' ) : $template;
		$name = is_string( $base ) && $base !== '' ? Strings::find_name( $base, $context->submission->form_key ) : '';
		return Settings::localize( $template, $context->lang, $name );
	}

	/**
	 * Filtered context.
	 *
	 * @param Context $context Context.
	 */
	private static function context( Context $context ): Context {
		$filtered = apply_filters( 'fnfe_template_context', $context );
		return $filtered instanceof Context ? $filtered : $context;
	}

	/**
	 * Resolve nodes to segments: [ 'c', canonical literal ], [ 'v', raw value ], [ 'm', '', pieces of a markup tag ].
	 *
	 * @param array   $nodes   Nodes.
	 * @param Context $context Context.
	 */
	private static function segments( array $nodes, Context $context ): array {
		$out = [];
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'text' ) {
				$out[] = [ 'c', $node['text'] ];
			} elseif ( $node['type'] === 'tag' ) {
				$out[] = self::tag_segment( $node, $context );
			} else {
				foreach ( $node['branches'] as $branch ) {
					if ( $branch['cond'] === null || Conditions::template( $branch['cond'], $context ) ) {
						$out = array_merge( $out, self::segments( $branch['nodes'], $context ) );
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Segment of one tag.
	 *
	 * @param array   $node    Tag node.
	 * @param Context $context Context.
	 */
	private static function tag_segment( array $node, Context $context ): array {
		$pieces = Tags::segments( $node['name'], $node['arg'], $context );
		if ( $pieces !== null && ! $node['filters'] ) {
			return [ 'm', '', $pieces ];
		}
		$value = $pieces === null ? Tags::value( $node['name'], $node['arg'], $context ) : self::plain( $pieces );
		if ( $value === null ) {
			Logger::debug( 'template', sprintf( 'Unknown tag "%s".', $node['name'] ) );
			return [ 'v', '' ];
		}
		if ( $node['filters'] ) {
			$value = Filters::chain( $node['filters'], $value, Tags::values( $node['name'], $node['arg'], $value, $context ), $context );
		}
		return [ 'v', Markup::clean_text( $value ) ];
	}

	/**
	 * Convert segments to a format.
	 *
	 * @param array              $segments  Segments.
	 * @param FormatterInterface $formatter Formatter.
	 */
	private static function assemble( array $segments, FormatterInterface $formatter ): string {
		$canonical = '';
		$values    = [];
		foreach ( $segments as $segment ) {
			foreach ( $segment[0] === 'm' ? $segment[2] : [ $segment ] as $piece ) {
				if ( $piece[0] === 'c' ) {
					$canonical .= $piece[1];
				} else {
					$canonical .= "\x02" . count( $values ) . "\x03";
					$values[]   = $piece[1];
				}
			}
		}
		if ( $formatter instanceof AbstractFormatter ) {
			return $formatter->convert_with( $canonical, $values );
		}

		// Other formatters convert the literal text only; values are put between the converted pieces.
		$out = '';
		foreach ( preg_split( '/\x02(\d+)\x03/', $formatter->convert( $canonical ), -1, PREG_SPLIT_DELIM_CAPTURE ) as $i => $part ) {
			if ( $i % 2 === 0 ) {
				$out .= $part;
				continue;
			}
			$value = $values[ (int) $part ] ?? '';
			$out  .= $formatter->escape( is_array( $value ) ? self::plain( [ [ 't', $value ] ] ) : $value );
		}
		return $out;
	}

	/**
	 * Segments without markup: [ 'c', plain literal ] and [ 'v', raw value ].
	 *
	 * @param array $segments Segments.
	 */
	private static function plain_segments( array $segments ): array {
		$canonical = '';
		$values    = [];
		foreach ( $segments as $segment ) {
			if ( $segment[0] === 'c' ) {
				$canonical .= $segment[1];
				continue;
			}
			$canonical .= "\x02" . count( $values ) . "\x03";
			$values[]   = $segment[0] === 'm' ? self::plain( $segment[2] ) : $segment[1];
		}
		$out = [];
		foreach ( self::flatten( Markup::parse( $canonical ) ) as $node ) {
			$out[] = $node['type'] === 'text' ? [ 'c', $node['text'] ] : [ 'v', $values[ $node['index'] ] ?? '' ];
		}
		return $out;
	}

	/**
	 * Text and value nodes in order.
	 *
	 * @param array $nodes Nodes.
	 */
	private static function flatten( array $nodes ): array {
		$out = [];
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'el' ) {
				$out = array_merge( $out, self::flatten( $node['children'] ) );
			} else {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * Plain text of the pieces of a markup tag.
	 *
	 * @param array $pieces Pieces.
	 */
	private static function plain( array $pieces ): string {
		return self::assemble( [ [ 'm', '', $pieces ] ], new Format\TextFormatter() );
	}

	/**
	 * Unknown tags and filters.
	 *
	 * @param array $nodes Nodes.
	 */
	private static function check( array $nodes ): array {
		$errors = [];
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'tag' ) {
				if ( ! Tags::known( $node['name'] ) ) {
					$errors[] = [
						'line'    => $node['line'],
						/* translators: %1$s: template tag */
						'message' => sprintf( __( 'Unknown tag: %1$s', 'forms-notifier-for-elementor' ), '{' . $node['name'] . '}' ),
					];
				}
				foreach ( $node['filters'] as $filter ) {
					if ( ! Filters::known( $filter[0] ) ) {
						$errors[] = [
							'line'    => $node['line'],
							/* translators: %1$s: filter name */
							'message' => sprintf( __( 'Unknown filter: %1$s', 'forms-notifier-for-elementor' ), $filter[0] ),
						];
					}
				}
			} elseif ( $node['type'] === 'if' ) {
				foreach ( $node['branches'] as $branch ) {
					foreach ( (array) $branch['cond'] as $terms ) {
						foreach ( $terms as $term ) {
							if ( ! Tags::known( $term['name'] ) ) {
								$errors[] = [
									'line'    => $node['line'],
									/* translators: %1$s: template tag */
									'message' => sprintf( __( 'Unknown tag: %1$s', 'forms-notifier-for-elementor' ), $term['name'] ),
								];
							}
						}
					}
					$errors = array_merge( $errors, self::check( $branch['nodes'] ) );
				}
			}
		}
		return $errors;
	}

	/**
	 * Field with an example value.
	 *
	 * @param array $definition id, type, label, options.
	 */
	private static function sample_field( array $definition ): array {
		$id      = (string) $definition['id'];
		$type    = (string) ( $definition['type'] ?? 'text' );
		$label   = (string) ( $definition['label'] ?? '' );
		$options = array_values( array_filter( (array) ( $definition['options'] ?? [] ), 'is_scalar' ) );
		$value   = self::SAMPLES[ $type ] ?? '';
		$values  = [];
		if ( $options ) {
			$values = $type === 'checkbox' ? array_slice( array_map( 'strval', $options ), 0, 2 ) : [ (string) $options[0] ];
			$value  = implode( ', ', $values );
		} elseif ( $type === 'upload' ) {
			$value = 'sample.pdf';
		} elseif ( $type === 'acceptance' ) {
			$value = __( 'Accepted', 'forms-notifier-for-elementor' );
		} elseif ( $type === 'textarea' ) {
			$value = __( 'Hello, I would like to know more.', 'forms-notifier-for-elementor' ) . "\n" . __( 'Thank you.', 'forms-notifier-for-elementor' );
		} elseif ( $value === '' ) {
			$value = preg_match( '/name/i', $id ) ? __( 'Sara', 'forms-notifier-for-elementor' ) : __( 'Sample text', 'forms-notifier-for-elementor' );
		}
		return [
			'id'      => $id,
			'type'    => $type,
			'label'   => $label === '' ? $id : $label,
			'value'   => $value,
			'values'  => $values ? $values : [ $value ],
			'raw'     => $type === 'checkbox' ? $values : $value,
			'is_file' => $type === 'upload',
		];
	}
}
