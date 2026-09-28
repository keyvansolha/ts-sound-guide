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
	 * Diagnostics for one mapped product.
	 *
	 * Under the category-authority rules a capability is false when absent, so
	 * "unknown" can only come from contradictory or unreadable attribute text.
	 * Both that and a category/attribute disagreement are reported here as
	 * data-quality warnings; Ready means no warnings at all.
	 *
	 * @param array<string, mixed> $p Mapped product.
	 * @return array<int, array<string, mixed>>
	 */
	private function product_issues( array $p ): array {
		$issues = [];

		$conflicts = is_array( $p['conflicts'] ?? null ) ? $p['conflicts'] : [];
		foreach ( $conflicts as $capability => $kind ) {
			$issues[] = [
				'severity'   => 'contradiction' === $kind || 'category_conflict' === $kind ? 'warning' : 'notice',
				'capability' => (string) $capability,
				'kind'       => (string) $kind,
				'field'      => $this->field_hint( (string) $capability ),
				'help'       => $this->conflict_hint( (string) $capability, (string) $kind ),
			];
		}

		foreach ( (array) $p['capabilities'] as $capability => $value ) {
			if ( null === $value ) {
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
		if ( 'unreadable' === $kind ) {
			return 'مقدار ویژگی «' . $label . '» قابل تفسیر نیست (نه «دارد» و نه «ندارد»). برای بررسی در مسیرهای پیشنهاد، مقدار صریح ثبت کنید.';
		}
		return 'مقدار ویژگی «' . $label . '» هم‌زمان مثبت و منفی است و قابل اتکا نیست؛ مقدار صریح «دارد» یا «ندارد» ثبت کنید.';
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
