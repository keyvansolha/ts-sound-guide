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
	 * @return array{ok:bool,groups:array<string,array<int,array<string,mixed>>>,summary:array<string,int>,issues:array<int,array<string,mixed>>}
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
				'error'   => 'Catalog query failed: ' . get_class( $e ),
			];
		}

		$issues = [];
		foreach ( $catalog as $row ) {
			$p              = $row['product'];
			$product_issues = $row['issues'];
			if ( $product_issues ) {
				$groups['unusable'][] = $this->summary_row( $p );
				$summary['unusable']++;
				foreach ( $product_issues as $issue ) {
					$issue['product'] = $this->summary_row( $p );
					$issues[]         = $issue;
				}
				continue;
			}

			$product_issues = $this->product_issues( $p );
			if ( ! $product_issues ) {
				$groups['ready'][] = $this->summary_row( $p );
				$summary['ready']++;
				continue;
			}
			$groups['incomplete'][] = $this->summary_row( $p );
			$summary['incomplete']++;
			foreach ( $product_issues as $issue ) {
				$issue['product'] = $this->summary_row( $p );
				$issues[]         = $issue;
			}
		}

		return [ 'ok' => true, 'groups' => $groups, 'summary' => $summary, 'issues' => $issues ];
	}

	/**
	 * Diagnostics for one mapped product. Ready = sufficient valid data for
	 * all applicable recommendation paths; missing/ambiguous capabilities are
	 * warnings; unusable commerce data never reaches this method (those
	 * products are not in the mapped catalog at all).
	 *
	 * @param array<string, mixed>    $p      Mapped product.
	 * @return array<int, array<string, mixed>>
	 */
	private function product_issues( array $p ): array {
		$issues     = [];
		$applicable = 'earbuds' === $p['flow']
			? [ 'wireless', 'usbc', 'anc', 'multipoint', 'silicone' ]
			: [ 'wireless', 'aux', 'auxMic', 'anc', 'multipoint' ];
		foreach ( $applicable as $capability ) {
			$value = $p['capabilities'][ $capability ] ?? null;
			if ( null === $value ) {
				$issues[] = [
					'severity'   => 'warning',
					'capability' => $capability,
					'field'      => $this->field_hint( $capability ),
					'help'       => $this->capability_hint( $capability ),
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
