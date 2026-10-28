<?php
/**
 * "Settings" tab: company info, content selection, URL rules, generation rules, AI policy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hs3daci_settings   = HS3DACI_Settings::get_settings();
$hs3daci_post_types = get_post_types( array( 'public' => true ), 'objects' );
unset( $hs3daci_post_types['attachment'] );
?>

<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonce-checked redirect. ?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e( 'Settings saved.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="hs3daci_save_settings">
	<?php wp_nonce_field( 'hs3daci_settings_nonce' ); ?>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Company Information', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

		<table class="form-table">
			<tr>
				<th><label for="company_name"><?php esc_html_e( 'Company Name', 'hs3dev-ai-content-index-llmstxt' ); ?></label></th>
				<td>
					<input type="text" id="company_name" name="company_name" class="regular-text" value="<?php echo esc_attr( $hs3daci_settings['company_name'] ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="company_email"><?php esc_html_e( 'Company Email', 'hs3dev-ai-content-index-llmstxt' ); ?></label></th>
				<td>
					<input type="email" id="company_email" name="company_email" class="regular-text" value="<?php echo esc_attr( $hs3daci_settings['company_email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty if you do not want the email displayed in llms.txt.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="company_description"><?php esc_html_e( 'Company Description', 'hs3dev-ai-content-index-llmstxt' ); ?></label></th>
				<td>
					<textarea id="company_description" name="company_description" class="large-text" rows="3"><?php echo esc_textarea( $hs3daci_settings['company_description'] ); ?></textarea>
				</td>
			</tr>
		</table>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Content Summaries', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

		<?php
		$hs3daci_detected_seo_plugin = hs3daci_detect_seo_plugin();
		?>

		<label>
			<input type="checkbox" name="include_meta_description" value="1" <?php checked( ! empty( $hs3daci_settings['include_meta_description'] ) ); ?>>
			<?php esc_html_e( 'Include a description next to each entry in llms.txt', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Unchecked: each entry shows only the heading and URL. Checked: each entry also shows a short description (controlled by the source priority below). This can also be toggled quickly from the Generate tab.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

		<hr>

		<label>
			<input type="checkbox" name="use_seo_meta" value="1" <?php checked( ! empty( $hs3daci_settings['use_seo_meta'] ) ); ?>>
			<?php esc_html_e( 'Use SEO meta description for summaries when available', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</label>

		<?php if ( $hs3daci_detected_seo_plugin ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: detected SEO plugin name */
					esc_html__( 'Detected: %s. When a meta description is set on an item, it will be used instead of the auto-generated summary.', 'hs3dev-ai-content-index-llmstxt' ),
					esc_html( $hs3daci_detected_seo_plugin )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No supported SEO plugin detected (Yoast SEO, RankMath, All in One SEO, SEOPress). The post excerpt or an auto-generated summary will be used instead.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Priority order: SEO meta description, then the manual excerpt, then an auto-trimmed snippet of the content.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

		<hr>

		<label>
			<input type="checkbox" name="compatibility_mode" value="1" <?php checked( ! empty( $hs3daci_settings['compatibility_mode'] ) ); ?>>
			<?php esc_html_e( 'Compatibility mode: fetch each page\'s live URL instead of rendering content internally', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Use this only if llms-full.txt looks garbled or missing content for pages built with a page builder (Elementor, WPBakery, UX Builder, etc.). It makes one live HTTP request per item, which causes severe slowdowns or hangs on large WooCommerce stores (100+ products) because the server ends up handling many self-referencing requests simultaneously. Leave this OFF for WooCommerce product pages — they render correctly through the default mode.', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</p>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Post Types', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Choose which content types to include. Click "Manage individual items" to exclude specific posts, pages or products.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

		<div class="hs3daci-posttype-list">
			<?php foreach ( $hs3daci_post_types as $hs3daci_post_type => $hs3daci_obj ) :
				$hs3daci_pt_settings  = isset( $hs3daci_settings['post_types'][ $hs3daci_post_type ] ) ? $hs3daci_settings['post_types'][ $hs3daci_post_type ] : array(
					'enabled'      => false,
					'excluded_ids' => array(),
				);
				$hs3daci_enabled      = ! empty( $hs3daci_pt_settings['enabled'] );
				$hs3daci_excluded_ids = isset( $hs3daci_pt_settings['excluded_ids'] ) ? (array) $hs3daci_pt_settings['excluded_ids'] : array();
				?>
				<div class="hs3daci-posttype-item">
					<div class="hs3daci-posttype-header">
						<label>
							<input type="checkbox" name="post_types[<?php echo esc_attr( $hs3daci_post_type ); ?>][enabled]" value="1" <?php checked( $hs3daci_enabled ); ?>>
							<?php echo esc_html( $hs3daci_obj->labels->name ); ?>
						</label>

						<button type="button" class="button-link hs3daci-pt-expand" data-post-type="<?php echo esc_attr( $hs3daci_post_type ); ?>">
							<?php esc_html_e( 'Manage individual items', 'hs3dev-ai-content-index-llmstxt' ); ?>
						</button>
					</div>

					<input
						type="hidden"
						class="hs3daci-excluded-ids"
						name="post_types[<?php echo esc_attr( $hs3daci_post_type ); ?>][excluded_ids]"
						value="<?php echo esc_attr( implode( ',', $hs3daci_excluded_ids ) ); ?>"
					>

					<div class="hs3daci-posttype-items" data-post-type="<?php echo esc_attr( $hs3daci_post_type ); ?>" style="display:none;">
						<p class="hs3daci-loading"><?php esc_html_e( 'Loading...', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Taxonomies', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Choose which taxonomy archives to include in llms.txt (e.g. WooCommerce product categories, tags, custom taxonomies). Each enabled taxonomy will appear as a separate section listing all its term URLs.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

		<?php
		$hs3daci_public_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		// Exclude built-in internal taxonomies that are not useful in llms.txt.
		$hs3daci_skip_taxonomies   = array( 'nav_menu', 'link_category', 'post_format' );

		foreach ( $hs3daci_public_taxonomies as $hs3daci_tax_slug => $hs3daci_tax_obj ) :
			if ( in_array( $hs3daci_tax_slug, $hs3daci_skip_taxonomies, true ) ) {
				continue;
			}
			$hs3daci_tax_enabled = ! empty( $hs3daci_settings['taxonomies'][ $hs3daci_tax_slug ] );
			?>
			<label style="display:block; margin: 6px 0;">
				<input type="checkbox"
					name="taxonomies[<?php echo esc_attr( $hs3daci_tax_slug ); ?>][enabled]"
					value="1"
					<?php checked( $hs3daci_tax_enabled ); ?>>
				<?php echo esc_html( $hs3daci_tax_obj->labels->name ); ?>
				<span style="color:#646970; font-size:12px;">
					(<?php echo esc_html( $hs3daci_tax_slug ); ?> — <?php esc_html_e( 'used by:', 'hs3dev-ai-content-index-llmstxt' ); ?>
					<?php echo esc_html( implode( ', ', (array) $hs3daci_tax_obj->object_type ) ); ?>)
				</span>
			</label>
		<?php endforeach; ?>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'URL Rules', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

		<table class="form-table">
			<tr>
				<th><label for="include_urls"><?php esc_html_e( 'Include URLs', 'hs3dev-ai-content-index-llmstxt' ); ?></label></th>
				<td>
					<textarea id="include_urls" name="include_urls" class="large-text" rows="3" placeholder="https://example.com/custom-page/"><?php echo esc_textarea( $hs3daci_settings['include_urls'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One URL per line. These will be listed as additional pages in llms.txt, useful for pages outside the post types above.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="exclude_urls"><?php esc_html_e( 'Exclude URLs', 'hs3dev-ai-content-index-llmstxt' ); ?></label></th>
				<td>
					<textarea id="exclude_urls" name="exclude_urls" class="large-text" rows="3"><?php echo esc_textarea( $hs3daci_settings['exclude_urls'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One URL per line. Matching items will always be removed from the generated files, even if included by a post type above.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Generation', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

		<table class="form-table">
			<tr>
				<th><label for="update_frequency"><?php esc_html_e( 'Update Frequency', 'hs3dev-ai-content-index-llmstxt' ); ?></label></th>
				<td>
					<select id="update_frequency" name="update_frequency">
						<option value="manual" <?php selected( $hs3daci_settings['update_frequency'], 'manual' ); ?>><?php esc_html_e( 'Manual only', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
						<option value="on_save" <?php selected( $hs3daci_settings['update_frequency'], 'on_save' ); ?>><?php esc_html_e( 'When content is published or updated', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
						<option value="daily" <?php selected( $hs3daci_settings['update_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
						<option value="weekly" <?php selected( $hs3daci_settings['update_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'AI Usage Policy', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
		<textarea name="ai_policy" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'e.g. Content may be used for training and citation with attribution to this site.', 'hs3dev-ai-content-index-llmstxt' ); ?>"><?php echo esc_textarea( $hs3daci_settings['ai_policy'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'This statement is included near the top of llms.txt to tell AI systems how they may use your content.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
	</div>

	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Analytics Settings', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
		<label>
			<input type="checkbox" name="enable_logging" value="1" <?php checked( ! empty( $hs3daci_settings['enable_logging'] ) ); ?>>
			<?php esc_html_e( 'Log requests to llms.txt and llms-full.txt (powers the Analytics tab)', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Records the timestamp and user agent of each request so you can see which AI crawlers are reading your site. No personal data or IP addresses are stored.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

		<p>
			<button type="button" class="button" id="hs3daci-clear-logs-btn"><?php esc_html_e( 'Clear Analytics Data', 'hs3dev-ai-content-index-llmstxt' ); ?></button>
			<span id="hs3daci-clear-logs-status" class="hs3daci-status"></span>
		</p>
	</div>


	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Role Capabilities', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Choose the minimum role that can view and manage this plugin\'s settings and trigger generation. Defaults to Administrator only.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

		<select name="allowed_role">
			<option value="manage_options" <?php selected( $hs3daci_settings['allowed_role'], 'manage_options' ); ?>><?php esc_html_e( 'Administrator only', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
			<option value="edit_others_posts" <?php selected( $hs3daci_settings['allowed_role'], 'edit_others_posts' ); ?>><?php esc_html_e( 'Editor and above', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
			<option value="publish_posts" <?php selected( $hs3daci_settings['allowed_role'], 'publish_posts' ); ?>><?php esc_html_e( 'Author and above', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'This takes effect immediately after saving. Make sure you keep at least one Administrator account, since lowering this does not remove Administrator access.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
	</div>

	<?php if ( is_multisite() ) : ?>
		<div class="hs3daci-card">
			<h2><?php esc_html_e( 'Multisite', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
			<p class="description"><?php esc_html_e( 'This is a multisite network. Each site has its own independent settings, generated files, and analytics; there is no shared or network-wide llms.txt. Configure and generate separately on each site where you want llms.txt and llms-full.txt available.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="submit">
		<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'hs3dev-ai-content-index-llmstxt' ); ?></button>
	</p>
</form>
