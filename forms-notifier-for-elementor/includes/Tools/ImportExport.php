<?php
/**
 * Import and export.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tools;

use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Settings and form configs as JSON. Secrets are never exported. */
final class ImportExport {

	/** Largest import. */
	public const MAX_BYTES = 2097152;

	private const PLUGIN = 'forms-notifier-for-elementor';

	/**
	 * Export envelope.
	 *
	 * @param string[] $include settings, forms.
	 */
	public static function export( array $include = [ 'settings', 'forms' ] ): array {
		$out = [
			'plugin'         => self::PLUGIN,
			'version'        => FNFE_VERSION,
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'           => home_url( '/' ),
			'secrets_needed' => Settings::secret_paths(),
		];
		if ( in_array( 'settings', $include, true ) ) {
			$tree     = Settings::all();
			$settings = [];
			foreach ( Schema::fields() as $path => $field ) {
				if ( $field['type'] === 'secret' ) {
					continue;
				}
				$value = Settings::path_get( $tree, $path, $found );
				if ( $found ) {
					$settings = Settings::path_set( $settings, $path, $value );
				}
			}
			$out['settings'] = (object) $settings;
		}
		if ( in_array( 'forms', $include, true ) ) {
			$out['forms'] = FormRepository::export_rows();
		}
		return $out;
	}

	/**
	 * Import an envelope.
	 *
	 * @param array  $data Decoded export.
	 * @param string $mode merge | replace.
	 * @return array{ok:bool,imported:array{settings:int,forms:int},warnings:string[]}|\WP_Error
	 */
	public static function import( array $data, string $mode = 'merge' ) {
		if ( ( $data['plugin'] ?? '' ) !== self::PLUGIN || ! is_scalar( $data['version'] ?? null ) ) {
			return new \WP_Error( 'fnfe_invalid_file', __( 'This is not an export file of this plugin.', 'forms-notifier-for-elementor' ), [ 'status' => 400 ] );
		}
		$settings = self::array_of( $data['settings'] ?? [] );
		$forms    = self::array_of( $data['forms'] ?? [] );
		$warnings = [];
		$imported = [
			'settings' => 0,
			'forms'    => 0,
		];

		if ( $settings ) {
			$values                 = self::flatten( $settings, $warnings );
			list( $clean, $errors ) = FieldSanitizer::settings( $values );
			foreach ( $errors as $path => $error ) {
				$warnings[] = $path . ': ' . $error;
			}
			if ( $mode === 'replace' ) {
				foreach ( array_keys( Schema::fields() ) as $path ) {
					if ( ! array_key_exists( $path, $clean ) && ! array_key_exists( $path, $errors ) ) {
						Settings::delete( $path );
					}
				}
			}
			Settings::save( $clean );
			$imported['settings'] = count( $clean );
		}

		foreach ( $forms as $form ) {
			$key = is_array( $form ) && is_scalar( $form['form_key'] ?? null ) ? (string) $form['form_key'] : '';
			$row = $key === '' ? null : FormRepository::find_by_key( $key );
			if ( ! $row ) {
				/* translators: %1$s: form name or key */
				$warnings[] = sprintf( __( 'Form not found on this site: %1$s', 'forms-notifier-for-elementor' ), sanitize_text_field( self::form_label( $form, $key ) ) );
				continue;
			}
			$config = self::array_of( $form['config'] ?? [] );
			if ( $mode !== 'replace' ) {
				$config = Settings::merge( $row['config'], $config );
			}
			if ( FormRepository::save_config( $row['id'], $config ) ) {
				++$imported['forms'];
			}
		}

		foreach ( self::array_of( $data['secrets_needed'] ?? [] ) as $path ) {
			if ( is_string( $path ) && Schema::is_secret( $path ) && ! Settings::has_secret( $path ) ) {
				/* translators: %1$s: settings path */
				$warnings[] = sprintf( __( 'Enter this secret again: %1$s', 'forms-notifier-for-elementor' ), $path );
			}
		}

		return [
			'ok'       => true,
			'imported' => $imported,
			'warnings' => $warnings,
		];
	}

	/**
	 * Nested tree to schema paths; unknown leaves become warnings.
	 *
	 * @param array    $tree     Tree.
	 * @param string[] $warnings Warnings, by reference.
	 * @param string   $prefix   Path so far.
	 */
	private static function flatten( array $tree, array &$warnings, string $prefix = '' ): array {
		$out = [];
		foreach ( $tree as $key => $value ) {
			$path  = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			$field = Schema::field( $path );
			if ( $field && $field['type'] !== 'secret' ) {
				$out[ $path ] = $value;
			} elseif ( ! $field && is_array( $value ) && self::has_fields_below( $path ) ) {
				$out += self::flatten( $value, $warnings, $path );
			} else {
				/* translators: %1$s: settings path */
				$warnings[] = sprintf( __( 'Unknown setting skipped: %1$s', 'forms-notifier-for-elementor' ), sanitize_text_field( $path ) );
			}
		}
		return $out;
	}

	/**
	 * The schema has fields under a path.
	 *
	 * @param string $path Path.
	 */
	private static function has_fields_below( string $path ): bool {
		foreach ( array_keys( Schema::fields() ) as $key ) {
			if ( strncmp( $key, $path . '.', strlen( $path ) + 1 ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Array from untrusted input; objects are converted.
	 *
	 * @param mixed $value Value.
	 */
	private static function array_of( $value ): array {
		return is_object( $value ) ? (array) $value : ( is_array( $value ) ? $value : [] );
	}

	/**
	 * Label of a form in a warning.
	 *
	 * @param mixed  $form Form entry.
	 * @param string $key  Form key.
	 */
	private static function form_label( $form, string $key ): string {
		$name = is_array( $form ) && is_scalar( $form['name'] ?? null ) ? (string) $form['name'] : '';
		return trim( $name . ' ' . $key );
	}
}
