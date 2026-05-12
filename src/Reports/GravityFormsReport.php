<?php

declare( strict_types=1 );

namespace DSN\Hawk\Reports;

use DSN\Hawk\Plugin;
use DSN\Hawk\Support\GravityEntriesState;

final class GravityFormsReport implements ReportInterface {

	/** Max entries pulled per form per sync run. */
	public const BATCH_SIZE = 25;

	/** Keep individual field values safe for typical indexed VARCHAR receiver columns. */
	public const FIELD_VALUE_MAX_LENGTH = 180;

	private const REDACTED = '[redacted]';

	/** Pending cursor commits keyed by form_id — applied only after HTTP 2xx. */
	private array $pendingCommits = [];

	public function key(): string {
		return 'gravity_forms';
	}

	public function isAvailable(): bool {
		return class_exists( '\\GFAPI' );
	}

	public function collect(): ?array {
		if ( ! $this->isAvailable() ) {
			return null;
		}

		$settings     = Plugin::settings();
		$include_ents = ! empty( $settings['reports']['gravity_forms_entries'] );
		$raw_pii      = ! empty( $settings['reports']['gravity_forms_entries_pii'] );
		$batch_size       = max( 1, (int) apply_filters( 'dsn_hawk_gf_batch_size', self::BATCH_SIZE ) );
		$per_sync_budget  = max( 1, (int) apply_filters( 'dsn_hawk_gf_entries_per_sync', $batch_size ) );
		$entry_budget     = $per_sync_budget;
		$field_value_max  = max( 20, (int) apply_filters( 'dsn_hawk_gf_field_value_max_length', self::FIELD_VALUE_MAX_LENGTH ) );

		$this->pendingCommits = [];

		$active   = (array) \GFAPI::get_forms( true );
		$inactive = (array) \GFAPI::get_forms( false );
		$forms    = array_merge( $active, $inactive );

		$out = [];
		foreach ( $forms as $form ) {
			$id = isset( $form['id'] ) ? (int) $form['id'] : 0;
			if ( $id <= 0 ) {
				continue;
			}

			$is_trash  = ! empty( $form['is_trash'] );
			$is_active = ! empty( $form['is_active'] );

			$count = 0;
			if ( method_exists( '\\GFAPI', 'count_entries' ) ) {
				$count = (int) \GFAPI::count_entries( $id );
			}

			$notifications = [];
			$raw_notifs    = $form['notifications'] ?? [];
			if ( is_array( $raw_notifs ) ) {
				foreach ( $raw_notifs as $n ) {
					if ( ! is_array( $n ) ) {
						continue;
					}
					$notifications[] = [
						'id'        => $this->stringOrNull( $n['id'] ?? null ),
						'name'      => $this->stringOrNull( $n['name'] ?? null ),
						'is_active' => ! isset( $n['isActive'] ) || (bool) $n['isActive'],
						'to'        => $this->stringOrNull( $n['to'] ?? null ),
						'to_type'   => $this->stringOrNull( $n['toType'] ?? null ),
						'to_field'  => $this->stringOrNull( $n['toField'] ?? null ),
						'bcc'       => $this->stringOrNull( $n['bcc'] ?? null ),
						'from'      => $this->stringOrNull( $n['from'] ?? null ),
						'reply_to'  => $this->stringOrNull( $n['replyTo'] ?? null ),
						'subject'   => $this->stringOrNull( $n['subject'] ?? null ),
					];
				}
			}

			$form_batch_size = min( $batch_size, $entry_budget );
			[ $entries, $new_cursor, $backfilled, $mode ] = $include_ents && $entry_budget > 0
				? $this->pullEntries( $id, $form, $form_batch_size, $raw_pii, $field_value_max )
				: [ [], 0, true, 'disabled' ];

			$state = GravityEntriesState::for( $id );
			if ( $include_ents && $entry_budget <= 0 ) {
				$new_cursor = (int) $state['cursor'];
				$backfilled = (bool) $state['backfilled'];
				$mode       = 'deferred';
			}

			$returned = count( $entries );
			$entry_budget = max( 0, $entry_budget - $returned );

			$out[] = [
				'id'                  => (string) $id,
				'title'               => $this->stringOrNull( $form['title'] ?? null ) ?? 'Form ' . $id,
				'description'         => $this->stringOrNull( $form['description'] ?? null ),
				'is_active'           => ( ! $is_trash ) && $is_active,
				'is_trash'            => $is_trash,
				'date_created'        => $this->stringOrNull( $form['date_created'] ?? null ),
				'date_updated'        => $this->stringOrNull( $form['date_updated'] ?? null ),
				'total_entries_count' => $count,
				'fields'              => $this->serializeFields( $form ),
				'notifications'       => $notifications,
				'entries'             => $entries,
				'entries_meta'        => [
					'mode'           => $mode,                     // 'backfill' | 'incremental' | 'caught_up' | 'deferred' | 'disabled'
					'cursor_before'  => $state['cursor'],
					'cursor_after'   => $new_cursor,
					'backfilled'     => $backfilled,
					'batch_size'     => $batch_size,
					'per_sync_budget' => $per_sync_budget,
					'field_value_max_length' => $field_value_max,
					'returned'       => $returned,
					'pii_stripped'   => ! $raw_pii,
				],
			];

			if ( $include_ents && ( $new_cursor !== $state['cursor'] || $backfilled !== $state['backfilled'] ) ) {
				$this->pendingCommits[ (string) $id ] = [
					'cursor'     => $new_cursor,
					'backfilled' => $backfilled,
				];
			}
		}

		return [ 'forms' => $out ];
	}

	/**
	 * Pull next batch of entries for a form.
	 *
	 * @return array{0: array, 1: int, 2: bool, 3: string}
	 *         [entries, new_cursor, backfilled_flag, mode]
	 */
	private function pullEntries( int $form_id, array $form, int $batch_size, bool $raw_pii, int $field_value_max ): array {
		$state  = GravityEntriesState::for( $form_id );
		$cursor = (int) $state['cursor'];

		$search = [
			'field_filters' => [
				[
					'key'      => 'id',
					'operator' => '>',
					'value'    => $cursor,
				],
			],
		];

		$sorting = [
			'key'        => 'id',
			'direction'  => 'ASC',
			'is_numeric' => true,
		];

		$paging = [
			'offset'    => 0,
			'page_size' => $batch_size,
		];

		$entries = \GFAPI::get_entries( $form_id, $search, $sorting, $paging );

		if ( is_wp_error( $entries ) ) {
			return [ [], $cursor, (bool) $state['backfilled'], 'error' ];
		}

		$entries = (array) $entries;

		if ( empty( $entries ) ) {
			// Nothing new. If we weren't backfilled yet and there are no older entries, we are now.
			return [ [], $cursor, true, 'caught_up' ];
		}

		$max_id       = $cursor;
		$serialized   = [];
		$field_labels = $this->fieldLabels( $form );

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$eid    = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			if ( $eid <= 0 ) {
				continue;
			}
			$max_id = max( $max_id, $eid );

			$serialized[] = $this->serializeEntry( $entry, $field_labels, $raw_pii, $field_value_max );
		}

		// Caught up when the batch came back short.
		$backfilled = count( $entries ) < $batch_size;
		$mode       = $state['backfilled'] ? 'incremental' : 'backfill';

		return [ $serialized, $max_id, $backfilled || $state['backfilled'], $mode ];
	}

	/**
	 * @return array<string,array{label:string,type:string,parent_id:string}>
	 */
	private function fieldLabels( array $form ): array {
		$labels = [];
		$fields = $form['fields'] ?? [];
		if ( ! is_array( $fields ) ) {
			return $labels;
		}
		foreach ( $fields as $field ) {
			$fid   = (string) ( $this->fieldProp( $field, 'id' ) ?? '' );
			$label = $this->fieldLabel( $field, $fid );
			$type  = (string) ( $this->fieldProp( $field, 'type' ) ?? '' );
			if ( $fid === '' ) {
				continue;
			}
			$labels[ $fid ] = [
				'label'     => $label,
				'type'      => $type,
				'parent_id' => $fid,
			];

			foreach ( $this->fieldInputs( $field ) as $input ) {
				$input_id = (string) ( $input['id'] ?? '' );
				if ( $input_id === '' ) {
					continue;
				}

				$input_label = $this->stringOrNull( $input['label'] ?? null );
				$labels[ $input_id ] = [
					'label'     => $input_label !== null ? $label . ' - ' . $input_label : $label,
					'type'      => $type,
					'parent_id' => $fid,
				];
			}
		}
		return $labels;
	}

	private function serializeFields( array $form ): array {
		$out    = [];
		$fields = $form['fields'] ?? [];
		if ( ! is_array( $fields ) ) {
			return $out;
		}

		foreach ( $fields as $field ) {
			$id          = $this->fieldProp( $field, 'id' );
			$field_id    = $id === null ? '' : (string) $id;
			$label       = $this->fieldLabel( $field, $field_id );
			$admin_label = $this->stringOrNull( $this->fieldProp( $field, 'adminLabel' ) );
			$type        = $this->fieldProp( $field, 'type' );

			if ( $field_id === '' ) {
				continue;
			}

			$out[] = [
				'id'          => $field_id,
				'label'       => $label,
				'admin_label' => $admin_label,
				'type'        => $this->stringOrNull( $type ),
				'is_required' => (bool) ( $this->fieldProp( $field, 'isRequired' ) ?? false ),
				'visibility'  => (string) ( $this->fieldProp( $field, 'visibility' ) ?? 'visible' ),
				'inputs'      => $this->fieldInputs( $field ),
			];
		}

		return $out;
	}

	private function fieldProp( mixed $field, string $prop ): mixed {
		if ( is_object( $field ) && isset( $field->{$prop} ) ) {
			return $field->{$prop};
		}
		if ( is_array( $field ) && array_key_exists( $prop, $field ) ) {
			return $field[ $prop ];
		}
		return null;
	}

	private function fieldLabel( mixed $field, string $field_id ): string {
		$admin_label = $this->stringOrNull( $this->fieldProp( $field, 'adminLabel' ) );
		if ( $admin_label !== null ) {
			return $admin_label;
		}

		$label = $this->stringOrNull( $this->fieldProp( $field, 'label' ) );
		if ( $label !== null ) {
			return $label;
		}

		return $field_id !== '' ? 'Field ' . $field_id : 'Field';
	}

	private function fieldInputs( mixed $field ): array {
		$inputs = $this->fieldProp( $field, 'inputs' );
		if ( ! is_array( $inputs ) ) {
			return [];
		}

		$out = [];
		foreach ( $inputs as $input ) {
			if ( is_object( $input ) ) {
				$input = (array) $input;
			}
			if ( ! is_array( $input ) ) {
				continue;
			}
			$out[] = [
				'id'    => $this->stringOrNull( $input['id'] ?? null ),
				'label' => $this->stringOrNull( $input['label'] ?? null ),
				'name'  => $this->stringOrNull( $input['name'] ?? null ),
			];
		}
		return $out;
	}

	private function serializeEntry( array $entry, array $field_labels, bool $raw_pii, int $field_value_max ): array {
		$fields = [];
		$field_values = [];
		foreach ( $entry as $k => $v ) {
			// GF stores field values under numeric keys like "1", "2.3". Meta fields use word keys.
			if ( ! is_numeric( $k ) && ! preg_match( '/^\d+(\.\d+)?$/', (string) $k ) ) {
				continue;
			}
			$top   = (string) (int) $k;
			$key   = (string) $k;
			$meta  = $field_labels[ $key ] ?? ( $field_labels[ $top ] ?? [ 'label' => 'Field ' . $key, 'type' => null, 'parent_id' => $top ] );
			$value = $v;
			$redacted = false;

			if ( ! $raw_pii ) {
				[ $value, $redacted ] = $this->privacyValue( $value, (string) $meta['type'], (string) $meta['label'] );
			}

			[ $value, $truncated, $original_length ] = $this->limitFieldValue( $value, $field_value_max );

			$field_values[ (string) $k ] = $value;
			$fields[] = [
				'id'             => (string) $k,
				'field_id'       => (string) ( $meta['parent_id'] ?? $top ),
				'label'          => (string) $meta['label'],
				'type'           => $this->stringOrNull( $meta['type'] ?? null ),
				'value'          => $value,
				'value_redacted' => $redacted,
				'value_truncated' => $truncated,
				'value_original_length' => $original_length,
			];
		}

		$ip = (string) ( $entry['ip'] ?? '' );
		if ( ! $raw_pii && $ip !== '' ) {
			$ip = $this->maskIp( $ip );
		}

		$user_agent = (string) ( $entry['user_agent'] ?? '' );
		if ( ! $raw_pii && $user_agent !== '' ) {
			$user_agent = function_exists( 'mb_substr' ) ? mb_substr( $user_agent, 0, 120 ) : substr( $user_agent, 0, 120 );
		}

		$source_url = (string) ( $entry['source_url'] ?? '' );
		if ( ! $raw_pii && $source_url !== '' ) {
			$source_url = $this->stripUrlQuery( $source_url );
		}

		$entry_id = (string) ( $entry['id'] ?? '' );

		return [
			'id'          => $entry_id,
			'entry_id'    => $entry_id,
			'form_id'     => (string) ( $entry['form_id'] ?? '' ),
			'date_created' => $this->stringOrNull( $entry['date_created'] ?? null ),
			'date_updated' => $this->stringOrNull( $entry['date_updated'] ?? null ),
			'status'      => $this->stringOrNull( $entry['status'] ?? null ),
			'source_url'  => $this->stringOrNull( $source_url ),
			'user_agent'  => $this->stringOrNull( $user_agent ),
			'ip'          => $this->stringOrNull( $ip ),
			'created_by'  => isset( $entry['created_by'] ) ? (string) $entry['created_by'] : null,
			'is_starred'  => ! empty( $entry['is_starred'] ),
			'is_read'     => ! empty( $entry['is_read'] ),
			'fields'      => $fields,
			'field_values' => $field_values,
		];
	}

	private function privacyValue( mixed $value, string $type, string $label ): array {
		if ( ! $this->isPiiField( $type, $label ) ) {
			$masked = $this->maskInlinePii( $value );
			return [ $masked, $masked !== $value ];
		}

		if ( $type === 'email' && is_string( $value ) && $value !== '' ) {
			return [ 'sha256:' . hash( 'sha256', strtolower( trim( $value ) ) ), true ];
		}

		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = is_scalar( $v ) && (string) $v !== '' ? self::REDACTED : $v;
			}
			return [ $out, true ];
		}

		return [ is_scalar( $value ) && (string) $value !== '' ? self::REDACTED : $value, true ];
	}

	private function limitFieldValue( mixed $value, int $max_length ): array {
		if ( $value === null || $value === '' ) {
			return [ $value, false, null ];
		}

		$string_value = $this->fieldValueToString( $value );
		$length       = $this->stringLength( $string_value );

		if ( $length <= $max_length ) {
			return [ $string_value, false, $length ];
		}

		return [
			$this->substring( $string_value, 0, max( 0, $max_length - 15 ) ) . '...[truncated]',
			true,
			$length,
		];
	}

	private function fieldValueToString( mixed $value ): string {
		if ( is_array( $value ) ) {
			$flat = [];
			array_walk_recursive(
				$value,
				static function ( mixed $item ) use ( &$flat ): void {
					if ( $item === null || $item === '' ) {
						return;
					}
					if ( is_scalar( $item ) ) {
						$flat[] = (string) $item;
					}
				}
			);
			return implode( ', ', $flat );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	private function isPiiField( string $type, string $label ): bool {
		$type = strtolower( $type );
		if ( in_array( $type, [ 'name', 'email', 'phone', 'address', 'website' ], true ) ) {
			return true;
		}

		$label = strtolower( $label );
		foreach ( [ 'name', 'email', 'phone', 'address', 'street', 'city', 'state', 'zip', 'postal', 'company', 'website', 'url' ] as $needle ) {
			if ( strpos( $label, $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

	private function maskInlinePii( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->maskInlinePii( $v );
			}
			return $out;
		}

		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}

		if ( filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return 'sha256:' . hash( 'sha256', strtolower( trim( $value ) ) );
		}

		return preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			static fn ( array $match ): string => 'sha256:' . hash( 'sha256', strtolower( trim( $match[0] ) ) ),
			$value
		);
	}

	private function maskIp( string $ip ): string {
		if ( strpos( $ip, ':' ) !== false ) {
			// IPv6 — keep first 3 hextets.
			$parts = explode( ':', $ip );
			$keep  = array_slice( $parts, 0, 3 );
			return implode( ':', $keep ) . '::/48';
		}
		$parts = explode( '.', $ip );
		if ( count( $parts ) === 4 ) {
			return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
		}
		return '0.0.0.0';
	}

	private function stripUrlQuery( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$out = '';
		if ( ! empty( $parts['scheme'] ) ) {
			$out .= $parts['scheme'] . '://';
		}
		$out .= $parts['host'];
		if ( ! empty( $parts['path'] ) ) {
			$out .= $parts['path'];
		}
		return $out;
	}

	private function stringOrNull( mixed $value ): ?string {
		if ( $value === null ) {
			return null;
		}

		$value = trim( (string) $value );
		return $value !== '' ? $value : null;
	}

	private function stringLength( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function substring( string $value, int $start, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, $start, $length ) : substr( $value, $start, $length );
	}

	public function onSynced(): void {
		foreach ( $this->pendingCommits as $form_id => $data ) {
			GravityEntriesState::set( $form_id, (int) $data['cursor'], (bool) $data['backfilled'] );
		}
		$this->pendingCommits = [];
	}
}
