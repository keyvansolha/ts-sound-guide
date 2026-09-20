<?php
/**
 * Admin screens: settings page and catalog-health report.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and renders the settings screen (Settings API
 * form, capability-gated) and the read-only catalog-health report with
 * route and WooCommerce availability diagnostics.
 */
final class AdminScreens {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Catalog adapter.
	 *
	 * @var CatalogAdapter
	 */
	private CatalogAdapter $catalog;

	/**
	 * Catalog health.
	 *
	 * @var CatalogHealth
	 */
	private CatalogHealth $health;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param CatalogAdapter $catalog  Catalog adapter.
	 * @param CatalogHealth  $health   Health service.
	 */
	public function __construct( Settings $settings, CatalogAdapter $catalog, CatalogHealth $health ) {
		$this->settings = $settings;
		$this->catalog  = $catalog;
		$this->health   = $health;
	}

	/**
	 * Register the menu under Settings.
	 */
	public function register_menu(): void {
		add_options_page(
			'راهنمای انتخاب صدا',
			'راهنمای انتخاب صدا',
			'manage_options',
			'ts-sound-guide',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Capability gate for both screens.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}
	}

	/**
	 * Render the settings + health page.
	 */
	public function render_page(): void {
		$this->guard();
		$stored  = $this->settings->stored();
		$page_id = (int) ( $stored['page_id'] ?? 0 );
		$terms   = $this->settings->category_terms();
		$broken  = $this->settings->has_broken_page();
		$woo     = $this->catalog->woo_available();
		$currency = $this->catalog->currency();
		$health  = $this->health->report();

		$pages = get_pages( [ 'sort_column' => 'post_title', 'hierarchical' => 0 ] );
		?>
<div class="wrap">
	<h1>راهنمای انتخاب صدا</h1>
	<?php if ( ! $woo ) : ?>
		<div class="notice notice-error"><p>WooCommerce فعال نیست؛ مسیر عمومی راهنما پاسخ کنترل‌شده ۵۰۳ می‌دهد و پیشنهادی نمایش داده نمی‌شود.</p></div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'ts_sound_guide' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ts-sound-page">صفحه راهنما</label></th>
				<td>
					<select id="ts-sound-page" name="<?php echo esc_attr( TS_SOUND_GUIDE_OPTION ); ?>[page_id]">
						<option value="0">— انتخاب نشده —</option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $page_id, (int) $page->ID ); ?>><?php echo esc_html( get_the_title( $page ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">محتوای این صفحه با راهنمای انتخاب جایگزین می‌شود؛ سرصفحه و پاصفحه قالب باقی می‌مانند.</p>
					<?php if ( $broken ) : ?>
						<p class="description" style="color:#c92b55;">صفحه انتخاب‌شده حذف شده یا منتشر نیست؛ در نمایش عمومی نادیده گرفته می‌شود.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ts-sound-earbuds">دسته‌بندی هندزفری</label></th>
				<td>
					<?php $this->term_select( 'earbuds_term', (int) $terms['earbuds'] ); ?>
					<p class="description">پیش‌فرض: دسته‌بندی موجود «handsfree».</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ts-sound-headphones">دسته‌بندی هدفون</label></th>
				<td>
					<?php $this->term_select( 'headphones_term', (int) $terms['headphones'] ); ?>
					<p class="description">پیش‌فرض: دسته‌بندی موجود «headphone».</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'ذخیره تنظیمات' ); ?>
	</form>

	<h2>تشخیص وضعیت</h2>
	<table class="widefat striped" role="presentation">
		<tbody>
			<tr><th scope="row">مسیر REST</th><td><code><?php echo esc_html( rest_url( TS_SOUND_GUIDE_REST_BASE ) ); ?></code> — <code>recommend</code> و <code>validate</code>؛ بدون کش (<code>no-store</code>)</td></tr>
			<tr><th scope="row">WooCommerce</th><td><?php echo $woo ? 'فعال' : 'غیرفعال'; ?></td></tr>
			<tr><th scope="row">واحد پول فروشگاه</th><td><?php echo $currency ? esc_html( $currency['code'] ) : 'پشتیبانی نمی‌شود (IRR، IRT یا TOMAN لازم است)'; ?></td></tr>
		</tbody>
	</table>

	<h2>سلامت داده محصولات</h2>
	<?php if ( ! $health['ok'] ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $health['error'] ); ?></p></div>
	<?php else : ?>
		<p>
			آماده: <strong><?php echo (int) $health['summary']['ready']; ?></strong> ·
			ناقص: <strong><?php echo (int) $health['summary']['incomplete']; ?></strong> ·
			غیرقابل ارائه: <strong><?php echo (int) $health['summary']['unusable']; ?></strong>
		</p>
		<?php if ( $health['issues'] ) : ?>
		<table class="widefat striped">
			<thead><tr><th>محصول</th><th>شدت</th><th>قابلیت</th><th>فیلد WooCommerce</th><th>توضیح</th><th>ویرایش</th></tr></thead>
			<tbody>
			<?php foreach ( $health['issues'] as $issue ) : ?>
				<tr>
					<td><?php echo esc_html( $issue['product']['name'] ); ?> <code>#<?php echo (int) $issue['product']['wcId']; ?></code></td>
					<td><?php echo 'error' === $issue['severity'] ? '⛔' : '⚠'; ?></td>
					<td><?php echo esc_html( $issue['capability'] ); ?></td>
					<td><?php echo esc_html( $issue['field'] ); ?></td>
					<td><?php echo esc_html( $issue['help'] ); ?></td>
					<td><a href="<?php echo esc_url( (string) $issue['product']['edit'] ); ?>">ویرایش محصول ↗</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
			<p>همه محصولات بررسی‌شده داده کامل دارند.</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Render a product-category term select.
	 *
	 * @param string $key  Settings key.
	 * @param int    $value Current value.
	 */
	private function term_select( string $key, int $value ): void {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		?>
<select id="ts-sound-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( TS_SOUND_GUIDE_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]">
			<?php foreach ( $terms as $term ) : ?>
	<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $value, (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?> (#<?php echo (int) $term->term_id; ?>)</option>
			<?php endforeach; ?>
</select>
		<?php
	}
}
