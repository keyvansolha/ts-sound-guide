<?php
/**
 * Catalog health: product-level diagnostics from the same mapping rules.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the catalog mapping rules and groups products into Ready, Incomplete,
 * and Unusable with actionable per-issue diagnostics. Read-only: never edits
 * product data and never creates a second dataset.
 */
final class CatalogHealth {

	/**
	 * Catalog adapter.
	 *
	 * @var CatalogAdapter
	 */
	private CatalogAdapter $catalog;

	/**
	 * Constructor.
	 *
	 * @param CatalogAdapter $catalog Catalog adapter.
	 */
	public function __construct( CatalogAdapter $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Build the health report.
	 *
	 * @return array{ok:bool,groups:array<string,array<int,array<string,mixed>>>,summary:array<string,int>,issues:array<int,array<string,mixed>>,information_issues:array<int,array<string,mixed>>,commerce_issues:array<int,array<string,mixed>>,not_listed:array<string,mixed>}
	 */
	public function report(): array {
		$groups  = [ 'ready' => [], 'incomplete' => [], 'unusable' => [] ];
		$summary = [ 'ready' => 0, 'incomplete' => 0, 'unusable' => 0 ];

		if ( ! $this->catalog->woo_available() ) {
			return [
				'ok'      => false,
				'groups'  => $groups,
				'summary' => $summary,
				'issues'  => [],
				'information_issues' => [],
				'commerce_issues'    => [],
				'not_listed' => [ 'by_capability' => [], 'products' => [] ],
				'error'   => 'WooCommerce is not available.',
			];
		}
		$currency = $this->catalog->currency();
		if ( null === $currency ) {
			return [
				'ok'      => false,
				'groups'  => $groups,
				'summary' => $summary,
				'issues'  => [],
				'information_issues' => [],
				'commerce_issues'    => [],
				'not_listed' => [ 'by_capability' => [], 'products' => [] ],
				'error'   => 'Store currency is unsupported. Use IRR, IRT, or TOMAN.',
			];
		}

		try {
			$catalog = $this->catalog->health_catalog();
		} catch ( \Throwable $e ) {
			return [
				'ok'      => false,
				'groups'  => $groups,
				'summary' => $summary,
				'issues'  => [],
				'information_issues' => [],
				'commerce_issues'    => [],
				'not_listed' => [ 'by_capability' => [], 'products' => [] ],
				'error'   => 'Catalog query failed: ' . get_class( $e ),
			];
		}

		$issues             = [];
		$information_issues = [];
		$commerce_issues    = [];
		$not_listed         = [ 'by_capability' => [], 'products' => [] ];
		foreach ( $catalog as $row ) {
			$p              = $row['product'];
			$product_issues = $row['issues'];
			if ( $product_issues ) {
				$groups['unusable'][] = $this->summary_row( $p );
				$summary['unusable']++;
				foreach ( $product_issues as $issue ) {
					$issue['product'] = $this->summary_row( $p );
					$issues[]          = $issue;
					$commerce_issues[] = $issue;
				}
				continue;
			}

			$product_issues = $this->product_issues( $p );
			$unlisted       = array_map( 'strval', (array) ( $p['unlisted'] ?? [] ) );
			if ( $unlisted ) {
				// Not listed is not a defect: it stays unknown for the shopper
				// and is reported as a coverage gap the data team can close.
				foreach ( $unlisted as $capability ) {
					$not_listed['by_capability'][ $capability ] = ( $not_listed['by_capability'][ $capability ] ?? 0 ) + 1;
				}
				$not_listed['products'][] = $this->summary_row( $p ) + [ 'capabilities' => $unlisted ];
			}
			if ( ! $product_issues ) {
				$groups['ready'][] = $this->summary_row( $p );
				$summary['ready']++;
				continue;
			}
			$groups['incomplete'][] = $this->summary_row( $p );
			$summary['incomplete']++;
			foreach ( $product_issues as $issue ) {
				$issue['product'] = $this->summary_row( $p );
				$issues[]             = $issue;
				$information_issues[] = $issue;
			}
		}

		return [
			'ok'         => true,
			'groups'     => $groups,
			'summary'    => $summary,
			'issues'     => $issues,
			'information_issues' => $information_issues,
			'commerce_issues'    => $commerce_issues,
			'not_listed'         => $not_listed,
		];
	}

	/**
	 * Diagnostics for one mapped product.
	 *
	 * Only actionable defects are reported here: unreadable or contradictory
	 * attribute text, a category/attribute disagreement, and a missing form.
	 * A capability the store has simply not listed is not a defect — it is
	 * `unknown` for the shopper and is reported separately as `not_listed`, so
	 * the Incomplete group stays a to-do list instead of naming every product.
	 *
	 * Ready means no warnings at all.
	 *
	 * @param array<string, mixed> $p Mapped product.
	 * @return array<int, array<string, mixed>>
	 */
	private function product_issues( array $p ): array {
		$issues   = [];
		$unlisted = array_map( 'strval', (array) ( $p['unlisted'] ?? [] ) );
		$relevant = 'headphones' === (string) ( $p['flow'] ?? '' )
			? [ 'wireless', 'usbc', 'aux', 'auxMic', 'anc', 'multipoint' ]
			: [ 'wireless', 'usbc', 'anc', 'multipoint', 'silicone' ];

		$conflicts = is_array( $p['conflicts'] ?? null ) ? $p['conflicts'] : [];
		foreach ( $conflicts as $capability => $kind ) {
			if ( ! in_array( (string) $capability, $relevant, true ) ) {
				continue;
			}
			$issues[] = [
				'severity'   => in_array( $kind, [ 'contradiction', 'category_conflict', 'category_negation_conflict' ], true ) ? 'warning' : 'notice',
				'capability' => (string) $capability,
				'kind'       => (string) $kind,
				'field'      => $this->field_hint( (string) $capability ),
				'help'       => $this->conflict_hint( (string) $capability, (string) $kind ),
			];
		}

		foreach ( (array) $p['capabilities'] as $capability => $value ) {
			if ( ! in_array( (string) $capability, $relevant, true ) ) {
				continue;
			}
			if ( null === $value && ! in_array( (string) $capability, $unlisted, true ) ) {
				$issues[] = [
					'severity'   => 'warning',
					'capability' => (string) $capability,
					'kind'       => 'unknown',
					'field'      => $this->field_hint( (string) $capability ),
					'help'       => $this->capability_hint( (string) $capability ),
				];
			}
		}

		if ( ! is_string( $p['form'] ?? null ) || '' === trim( $p['form'] ) ) {
			$issues[] = [
				'severity'   => 'warning',
				'capability' => 'form',
				'field'      => 'ویژگی «نوع هدفون» محصول',
				'help'       => 'فرم محصول ثبت نشده است؛ ویژگی pa_headphones-type را با مقدار دقیق و قابل نمایش تکمیل کنید.',
			];
		}
		return $issues;
	}

	/**
	 * Plain-language explanation for a category/attribute conflict.
	 *
	 * @param string $capability Capability key.
	 * @param string $kind       Conflict kind.
	 * @return string
	 */
	private function conflict_hint( string $capability, string $kind ): string {
		$registry = new CapabilityRegistry();
		$defs     = $registry->capabilities();
		$label    = (string) ( $defs[ $capability ]['label'] ?? $capability );
		if ( 'category_conflict' === $kind ) {
			return 'دسته‌بندی می‌گوید «' . $label . '» دارد، اما مقدار ویژگی محصول خلاف آن است. دسته‌بندی اولویت دارد؛ برای رفع تناقض، مقدار ویژگی را اصلاح کنید یا محصول را از دسته‌بندی حذف کنید.';
		}
		if ( 'category_negation_conflict' === $kind ) {
			return 'دسته‌بندی «' . $label . '» را رد می‌کند، اما مقدار ویژگی محصول آن را تأیید می‌کند. دسته‌بندی اولویت دارد؛ برای رفع تناقض، مقدار ویژگی را اصلاح کنید یا دسته‌بندی را بررسی کنید.';
		}
		if ( 'unreadable' === $kind ) {
			return 'مقدار ویژگی «' . $label . '» قابل تفسیر نیست (نه «دارد» و نه «ندارد»). برای بررسی در مسیرهای پیشنهاد، مقدار صریح ثبت کنید.';
		}
		return 'مقدار ویژگی «' . $label . '» هم‌زمان مثبت و منفی است و قابل اتکا نیست؛ مقدار صریح «دارد» یا «ندارد» ثبت کنید.';
	}

	/**
	 * Capability labels and WooCommerce field hints for admin reports.
	 *
	 * @return array<string, array{label:string,field:string}>
	 */
	public function capability_fields(): array {
		$rows = [];
		foreach ( ( new CapabilityRegistry() )->capabilities() as $capability => $def ) {
			$rows[ (string) $capability ] = [
				'label' => (string) ( $def['label'] ?? $capability ),
				'field' => (string) ( $def['field'] ?? '' ),
			];
		}
		return $rows;
	}

	/**
	 * WooCommerce field hint for a capability.
	 *
	 * @param string $capability Capability key.
	 * @return string
	 */
	private function field_hint( string $capability ): string {
		$registry = new CapabilityRegistry();
		$defs     = $registry->capabilities();
		return (string) ( $defs[ $capability ]['field'] ?? 'ویژگی محصول در WooCommerce' );
	}

	/**
	 * Plain-language explanation for a capability issue.
	 *
	 * @param string $capability Capability key.
	 * @return string
	 */
	private function capability_hint( string $capability ): string {
		$registry = new CapabilityRegistry();
		$defs     = $registry->capabilities();
		$label    = (string) ( $defs[ $capability ]['label'] ?? $capability );
		return 'قابلیت «' . $label . '» برای این مدل ثبت نشده یا مبهم است؛ در مسیرهای پیشنهاد که به این قابلیت وابسته‌اند، مدل فقط با مقدار صریح «دارد/ندارد» بررسی می‌شود.';
	}

	/**
	 * Compact row for admin tables.
	 *
	 * @param array<string, mixed> $p Mapped product.
	 * @return array<string, mixed>
	 */
	private function summary_row( array $p ): array {
		return [
			'id'   => (int) $p['id'],
			'wcId' => (int) $p['wcId'],
			'name' => (string) $p['name'],
			'flow' => (string) $p['flow'],
			'edit' => get_edit_post_link( (int) $p['wcId'], 'raw' ),
		];
	}
}
