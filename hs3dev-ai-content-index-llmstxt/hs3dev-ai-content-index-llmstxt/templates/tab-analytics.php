<?php
/**
 * "Analytics" tab: shows request stats for llms.txt / llms-full.txt,
 * including a breakdown by AI crawler / bot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hs3daci_settings = HS3DACI_Settings::get_settings();

if ( empty( $hs3daci_settings['enable_logging'] ) ) {
	?>
	<div class="hs3daci-card">
		<h2><?php esc_html_e( 'Analytics', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to settings tab */
					__( 'Logging is currently disabled. Enable "Log requests to llms.txt and llms-full.txt" on the %s tab to start collecting data.', 'hs3dev-ai-content-index-llmstxt' ),
					array( 'a' => array( 'href' => array() ) )
				),
				'<a href="' . esc_url( add_query_arg( array( 'page' => 'hs3dev-ai-content-index-llmstxt', 'tab' => 'settings' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Settings', 'hs3dev-ai-content-index-llmstxt' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php
	return;
}

$hs3daci_summary = HS3DACI_Logger::get_summary( 30 );

$hs3daci_by_file_counts = array(
	'llms'      => 0,
	'llms-full' => 0,
);
foreach ( $hs3daci_summary['by_file'] as $hs3daci_row ) {
	if ( isset( $hs3daci_by_file_counts[ $hs3daci_row->file_type ] ) ) {
		$hs3daci_by_file_counts[ $hs3daci_row->file_type ] = (int) $hs3daci_row->total;
	}
}

$hs3daci_max_daily = 0;
foreach ( $hs3daci_summary['daily'] as $hs3daci_row ) {
	$hs3daci_max_daily = max( $hs3daci_max_daily, (int) $hs3daci_row->total );
}
?>

<div class="hs3daci-card-row">
	<div class="hs3daci-card hs3daci-stat-card">
		<span class="hs3daci-stat-number"><?php echo esc_html( number_format_i18n( $hs3daci_summary['total'] ) ); ?></span>
		<span class="hs3daci-stat-label"><?php esc_html_e( 'Total requests (30 days)', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
	</div>
	<div class="hs3daci-card hs3daci-stat-card">
		<span class="hs3daci-stat-number"><?php echo esc_html( number_format_i18n( $hs3daci_by_file_counts['llms'] ) ); ?></span>
		<span class="hs3daci-stat-label"><?php esc_html_e( 'llms.txt requests', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
	</div>
	<div class="hs3daci-card hs3daci-stat-card">
		<span class="hs3daci-stat-number"><?php echo esc_html( number_format_i18n( $hs3daci_by_file_counts['llms-full'] ) ); ?></span>
		<span class="hs3daci-stat-label"><?php esc_html_e( 'llms-full.txt requests', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
	</div>
</div>

<div class="hs3daci-card">
	<h2><?php esc_html_e( 'Requests Over Time (30 days)', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

	<?php if ( empty( $hs3daci_summary['daily'] ) ) : ?>
		<p><?php esc_html_e( 'No requests recorded yet.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
	<?php else : ?>
		<div class="hs3daci-bar-chart">
			<?php foreach ( $hs3daci_summary['daily'] as $hs3daci_row ) :
				$hs3daci_height = $hs3daci_max_daily > 0 ? round( ( (int) $hs3daci_row->total / $hs3daci_max_daily ) * 100 ) : 0;
				$hs3daci_height = max( $hs3daci_height, 4 );
				?>
				<div class="hs3daci-bar-col">
					<div class="hs3daci-bar" style="height: <?php echo esc_attr( $hs3daci_height ); ?>%;" title="<?php echo esc_attr( $hs3daci_row->total ); ?>"></div>
					<span class="hs3daci-bar-label"><?php echo esc_html( date_i18n( 'M j', strtotime( $hs3daci_row->day ) ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<div class="hs3daci-card">
	<h2><?php esc_html_e( 'Requests by Bot / Crawler', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

	<?php if ( empty( $hs3daci_summary['by_bot'] ) ) : ?>
		<p><?php esc_html_e( 'No requests recorded yet.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Bot / Crawler', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
					<th><?php esc_html_e( 'Requests', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
					<th><?php esc_html_e( 'Last Seen', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $hs3daci_summary['by_bot'] as $hs3daci_row ) : ?>
					<tr>
						<td><?php echo esc_html( $hs3daci_row->bot_name ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $hs3daci_row->total ) ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $hs3daci_row->last_seen, 'Y-m-d H:i' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="hs3daci-card">
	<h2><?php esc_html_e( 'Recent Requests', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>

	<?php if ( empty( $hs3daci_summary['recent'] ) ) : ?>
		<p><?php esc_html_e( 'No requests recorded yet.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>
	<?php else : ?>
		<div class="hs3daci-recent-table-wrap">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
						<th><?php esc_html_e( 'File', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
						<th><?php esc_html_e( 'Bot / Crawler', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
						<th><?php esc_html_e( 'User Agent', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $hs3daci_summary['recent'] as $hs3daci_row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $hs3daci_row->requested_at, 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( 'llms-full' === $hs3daci_row->file_type ? 'llms-full.txt' : 'llms.txt' ); ?></td>
							<td><?php echo esc_html( $hs3daci_row->bot_name ); ?></td>
							<td class="hs3daci-ua-cell"><?php echo esc_html( $hs3daci_row->user_agent ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
