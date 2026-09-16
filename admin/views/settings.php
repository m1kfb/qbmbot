<?php
/**
 * Admin settings view.
 *
 * @package QBMBot
 *
 * @var array<string, mixed> $settings
 * @var array<int, array<string, mixed>> $faqs
 * @var array<int, object> $logs
 * @var array<string, int> $usage
 * @var array<int, array{id:int,title:string}> $cf7_forms
 * @var array<int, array{id:int,title:string,type:string}> $content_posts
 * @var string $tab
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs = array(
	'general'    => __( 'General', 'qbmbot' ),
	'business'   => __( 'Business & Content', 'qbmbot' ),
	'providers'  => __( 'AI Providers', 'qbmbot' ),
	'faq'        => __( 'FAQ / Preloads', 'qbmbot' ),
	'spam'       => __( 'Spam & Limits', 'qbmbot' ),
	'appearance' => __( 'Appearance', 'qbmbot' ),
	'cf7'        => __( 'CF7', 'qbmbot' ),
	'logs'       => __( 'Logs', 'qbmbot' ),
);

if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'general';
}
?>
<div class="wrap qbmbot-admin">
	<h1><?php echo esc_html__( 'QBMBOT', 'qbmbot' ); ?></h1>

	<?php if ( isset( $_GET['qbmbot_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'qbmbot' ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=qbmbot&tab=' . $slug ) ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'logs' === $tab ) : ?>
		<div class="qbmbot-panel">
			<p>
				<?php
				printf(
					/* translators: 1: used count, 2: daily cap */
					esc_html__( 'Today’s AI calls: %1$d / %2$d', 'qbmbot' ),
					(int) $usage['daily_used'],
					(int) $usage['daily_cap']
				);
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'When (UTC)', 'qbmbot' ); ?></th>
						<th><?php echo esc_html__( 'Channel', 'qbmbot' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'qbmbot' ); ?></th>
						<th><?php echo esc_html__( 'Reason', 'qbmbot' ); ?></th>
						<th><?php echo esc_html__( 'Provider', 'qbmbot' ); ?></th>
						<th><?php echo esc_html__( 'Tokens', 'qbmbot' ); ?></th>
						<th><?php echo esc_html__( 'Spam', 'qbmbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="7"><?php echo esc_html__( 'No events yet.', 'qbmbot' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row->created_at ); ?></td>
								<td><?php echo esc_html( (string) $row->channel ); ?></td>
								<td><?php echo esc_html( (string) $row->status ); ?></td>
								<td><?php echo esc_html( (string) $row->reason ); ?></td>
								<td><?php echo esc_html( (string) $row->provider ); ?></td>
								<td><?php echo esc_html( (string) $row->tokens ); ?></td>
								<td><?php echo esc_html( (string) $row->spam_score ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php else : ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'qbmbot_save_settings' ); ?>
			<input type="hidden" name="qbmbot_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<input type="hidden" name="qbmbot_save" value="1" />

			<div class="qbmbot-panel">
				<?php if ( 'general' === $tab ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Chat widget', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="widget_enabled" value="1" <?php checked( ! empty( $settings['widget_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable floating chat widget', 'qbmbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'CF7 auto-reply', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="cf7_enabled" value="1" <?php checked( ! empty( $settings['cf7_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable Contact Form 7 email auto-replies', 'qbmbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="global_prompt"><?php echo esc_html__( 'Global AI instructions', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="6" name="global_prompt" id="global_prompt"><?php echo esc_textarea( (string) $settings['global_prompt'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="welcome_message"><?php echo esc_html__( 'Welcome message', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="welcome_message" id="welcome_message" value="<?php echo esc_attr( (string) $settings['welcome_message'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="blocked_message"><?php echo esc_html__( 'Blocked / unavailable message', 'qbmbot' ); ?></label></th>
							<td><input class="large-text" type="text" name="blocked_message" id="blocked_message" value="<?php echo esc_attr( (string) $settings['blocked_message'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Uninstall', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> /> <?php echo esc_html__( 'Delete settings, FAQ, and logs when uninstalling', 'qbmbot' ); ?></label></td>
						</tr>
					</table>

				<?php elseif ( 'business' === $tab ) : ?>
					<h2><?php echo esc_html__( 'Business profile', 'qbmbot' ); ?></h2>
					<p class="description"><?php echo esc_html__( 'Ground the assistant on this SME / trades business so it does not invent general advice or talk about other companies.', 'qbmbot' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="business_name"><?php echo esc_html__( 'Business name', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="business_name" id="business_name" value="<?php echo esc_attr( (string) $settings['business_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="business_trade"><?php echo esc_html__( 'Trade / industry', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="business_trade" id="business_trade" value="<?php echo esc_attr( (string) $settings['business_trade'] ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Plumbing & heating', 'qbmbot' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="business_services"><?php echo esc_html__( 'Services offered', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="4" name="business_services" id="business_services" placeholder="<?php echo esc_attr__( 'One service per line', 'qbmbot' ); ?>"><?php echo esc_textarea( (string) $settings['business_services'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="business_service_area"><?php echo esc_html__( 'Service area', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="2" name="business_service_area" id="business_service_area" placeholder="<?php echo esc_attr__( 'e.g. Bristol and surrounding 20 miles', 'qbmbot' ); ?>"><?php echo esc_textarea( (string) $settings['business_service_area'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="business_notes"><?php echo esc_html__( 'Key business facts', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="4" name="business_notes" id="business_notes" placeholder="<?php echo esc_attr__( 'Hours, accreditations, call-out policy, payment terms…', 'qbmbot' ); ?>"><?php echo esc_textarea( (string) $settings['business_notes'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="business_refuse_topics"><?php echo esc_html__( 'Topics to refuse', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="2" name="business_refuse_topics" id="business_refuse_topics"><?php echo esc_textarea( (string) $settings['business_refuse_topics'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="business_handoff_message"><?php echo esc_html__( 'Off-topic handoff message', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="3" name="business_handoff_message" id="business_handoff_message"><?php echo esc_textarea( (string) $settings['business_handoff_message'] ); ?></textarea></td>
						</tr>
					</table>

					<h2><?php echo esc_html__( 'WordPress site content', 'qbmbot' ); ?></h2>
					<p class="description"><?php echo esc_html__( 'Pull answers from published pages and posts on this site. Pin About / Services / Areas pages so they are always available.', 'qbmbot' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Site content', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="content_enabled" value="1" <?php checked( ! empty( $settings['content_enabled'] ) ); ?> /> <?php echo esc_html__( 'Source context from this WordPress site', 'qbmbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Post types', 'qbmbot' ); ?></th>
							<td>
								<?php
								$selected_types = (array) $settings['content_post_types'];
								?>
								<label style="margin-right:12px;"><input type="checkbox" name="content_post_types[]" value="page" <?php checked( in_array( 'page', $selected_types, true ) ); ?> /> <?php echo esc_html__( 'Pages', 'qbmbot' ); ?></label>
								<label><input type="checkbox" name="content_post_types[]" value="post" <?php checked( in_array( 'post', $selected_types, true ) ); ?> /> <?php echo esc_html__( 'Posts', 'qbmbot' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Always include (pinned)', 'qbmbot' ); ?></th>
							<td>
								<?php
								$pinned = array_map( 'intval', (array) $settings['content_pinned_ids'] );
								if ( empty( $content_posts ) ) :
									?>
									<p class="description"><?php echo esc_html__( 'No published pages or posts found yet.', 'qbmbot' ); ?></p>
								<?php else : ?>
									<div class="qbmbot-post-checklist">
										<?php foreach ( $content_posts as $post_row ) : ?>
											<label>
												<input type="checkbox" name="content_pinned_ids[]" value="<?php echo esc_attr( (string) $post_row['id'] ); ?>" <?php checked( in_array( (int) $post_row['id'], $pinned, true ) ); ?> />
												<?php echo esc_html( $post_row['title'] . ' (' . $post_row['type'] . ' #' . $post_row['id'] . ')' ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Exclude from search', 'qbmbot' ); ?></th>
							<td>
								<?php
								$excluded = array_map( 'intval', (array) $settings['content_exclude_ids'] );
								if ( ! empty( $content_posts ) ) :
									?>
									<div class="qbmbot-post-checklist">
										<?php foreach ( $content_posts as $post_row ) : ?>
											<label>
												<input type="checkbox" name="content_exclude_ids[]" value="<?php echo esc_attr( (string) $post_row['id'] ); ?>" <?php checked( in_array( (int) $post_row['id'], $excluded, true ) ); ?> />
												<?php echo esc_html( $post_row['title'] . ' (' . $post_row['type'] . ' #' . $post_row['id'] . ')' ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="content_search_limit"><?php echo esc_html__( 'Search results per question', 'qbmbot' ); ?></label></th>
							<td><input type="number" min="1" max="10" name="content_search_limit" id="content_search_limit" value="<?php echo esc_attr( (string) (int) $settings['content_search_limit'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="content_max_chars"><?php echo esc_html__( 'Max total content chars', 'qbmbot' ); ?></label></th>
							<td><input type="number" min="500" max="20000" name="content_max_chars" id="content_max_chars" value="<?php echo esc_attr( (string) (int) $settings['content_max_chars'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="content_per_post_chars"><?php echo esc_html__( 'Max chars per page/post', 'qbmbot' ); ?></label></th>
							<td><input type="number" min="200" max="4000" name="content_per_post_chars" id="content_per_post_chars" value="<?php echo esc_attr( (string) (int) $settings['content_per_post_chars'] ); ?>" /></td>
						</tr>
					</table>

				<?php elseif ( 'providers' === $tab ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Active provider', 'qbmbot' ); ?></th>
							<td>
								<select name="active_provider">
									<option value="openai" <?php selected( $settings['active_provider'], 'openai' ); ?>>OpenAI</option>
									<option value="anthropic" <?php selected( $settings['active_provider'], 'anthropic' ); ?>>Anthropic</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openai_api_key"><?php echo esc_html__( 'OpenAI API key', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="password" autocomplete="off" name="openai_api_key" id="openai_api_key" value="<?php echo esc_attr( \QBMBot\Admin::mask_key( (string) $settings['openai_api_key'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Leave blank to keep existing', 'qbmbot' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="openai_model"><?php echo esc_html__( 'OpenAI model', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="openai_model" id="openai_model" value="<?php echo esc_attr( (string) $settings['openai_model'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="anthropic_api_key"><?php echo esc_html__( 'Anthropic API key', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="password" autocomplete="off" name="anthropic_api_key" id="anthropic_api_key" value="<?php echo esc_attr( \QBMBot\Admin::mask_key( (string) $settings['anthropic_api_key'] ) ); ?>" placeholder="<?php echo esc_attr__( 'Leave blank to keep existing', 'qbmbot' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="anthropic_model"><?php echo esc_html__( 'Anthropic model', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="anthropic_model" id="anthropic_model" value="<?php echo esc_attr( (string) $settings['anthropic_model'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="max_tokens"><?php echo esc_html__( 'Max tokens', 'qbmbot' ); ?></label></th>
							<td><input type="number" min="50" max="4000" name="max_tokens" id="max_tokens" value="<?php echo esc_attr( (string) (int) $settings['max_tokens'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="temperature"><?php echo esc_html__( 'Temperature', 'qbmbot' ); ?></label></th>
							<td><input type="number" step="0.1" min="0" max="2" name="temperature" id="temperature" value="<?php echo esc_attr( (string) $settings['temperature'] ); ?>" /></td>
						</tr>
					</table>

				<?php elseif ( 'faq' === $tab ) : ?>
					<p><?php echo esc_html__( 'Preload suggested questions shown in the chat. Add brief AI instructions to steer answers for each question.', 'qbmbot' ); ?></p>
					<div id="qbmbot-faq-list" class="qbmbot-faq-list">
						<?php
						if ( empty( $faqs ) ) {
							$faqs = array(
								array(
									'id'              => '',
									'question'        => '',
									'answer'          => '',
									'ai_instructions' => '',
									'enabled'         => true,
									'order'           => 0,
								),
							);
						}
						foreach ( $faqs as $i => $item ) :
							?>
							<div class="qbmbot-faq-item">
								<input type="hidden" name="faq[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>" />
								<input type="hidden" name="faq[<?php echo (int) $i; ?>][order]" value="<?php echo esc_attr( (string) (int) ( $item['order'] ?? $i ) ); ?>" />
								<p>
									<label><?php echo esc_html__( 'Question', 'qbmbot' ); ?>
										<input class="large-text" type="text" name="faq[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr( (string) ( $item['question'] ?? '' ) ); ?>" />
									</label>
								</p>
								<p>
									<label><?php echo esc_html__( 'Optional display answer (used without AI if no instructions)', 'qbmbot' ); ?>
										<textarea class="large-text" rows="2" name="faq[<?php echo (int) $i; ?>][answer]"><?php echo esc_textarea( (string) ( $item['answer'] ?? '' ) ); ?></textarea>
									</label>
								</p>
								<p>
									<label><?php echo esc_html__( 'AI instructions', 'qbmbot' ); ?>
										<textarea class="large-text" rows="2" name="faq[<?php echo (int) $i; ?>][ai_instructions]"><?php echo esc_textarea( (string) ( $item['ai_instructions'] ?? '' ) ); ?></textarea>
									</label>
								</p>
								<p>
									<label><input type="checkbox" name="faq[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $item['enabled'] ) ); ?> /> <?php echo esc_html__( 'Enabled', 'qbmbot' ); ?></label>
									<button type="button" class="button-link-delete qbmbot-faq-remove"><?php echo esc_html__( 'Remove', 'qbmbot' ); ?></button>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
					<p><button type="button" class="button" id="qbmbot-faq-add"><?php echo esc_html__( 'Add question', 'qbmbot' ); ?></button></p>
					<template id="qbmbot-faq-template">
						<div class="qbmbot-faq-item">
							<input type="hidden" name="faq[__I__][id]" value="" />
							<input type="hidden" name="faq[__I__][order]" value="__I__" />
							<p>
								<label><?php echo esc_html__( 'Question', 'qbmbot' ); ?>
									<input class="large-text" type="text" name="faq[__I__][question]" value="" />
								</label>
							</p>
							<p>
								<label><?php echo esc_html__( 'Optional display answer (used without AI if no instructions)', 'qbmbot' ); ?>
									<textarea class="large-text" rows="2" name="faq[__I__][answer]"></textarea>
								</label>
							</p>
							<p>
								<label><?php echo esc_html__( 'AI instructions', 'qbmbot' ); ?>
									<textarea class="large-text" rows="2" name="faq[__I__][ai_instructions]"></textarea>
								</label>
							</p>
							<p>
								<label><input type="checkbox" name="faq[__I__][enabled]" value="1" checked /> <?php echo esc_html__( 'Enabled', 'qbmbot' ); ?></label>
								<button type="button" class="button-link-delete qbmbot-faq-remove"><?php echo esc_html__( 'Remove', 'qbmbot' ); ?></button>
							</p>
						</div>
					</template>

				<?php elseif ( 'spam' === $tab ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="spam_threshold"><?php echo esc_html__( 'Spam score threshold', 'qbmbot' ); ?></label></th>
							<td><input type="number" name="spam_threshold" id="spam_threshold" value="<?php echo esc_attr( (string) (int) $settings['spam_threshold'] ); ?>" min="1" />
							<p class="description"><?php echo esc_html__( 'Block (no AI call) when score is greater than or equal to this value.', 'qbmbot' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="min_message_length"><?php echo esc_html__( 'Min message length', 'qbmbot' ); ?></label></th>
							<td><input type="number" name="min_message_length" id="min_message_length" value="<?php echo esc_attr( (string) (int) $settings['min_message_length'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="min_open_ms"><?php echo esc_html__( 'Min open time (ms)', 'qbmbot' ); ?></label></th>
							<td><input type="number" name="min_open_ms" id="min_open_ms" value="<?php echo esc_attr( (string) (int) $settings['min_open_ms'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Honeypot', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="honeypot_enabled" value="1" <?php checked( ! empty( $settings['honeypot_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable honeypot field', 'qbmbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Akismet', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="use_akismet" value="1" <?php checked( ! empty( $settings['use_akismet'] ) ); ?> /> <?php echo esc_html__( 'Use Akismet when available', 'qbmbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="rate_ip_limit"><?php echo esc_html__( 'Per-IP limit', 'qbmbot' ); ?></label></th>
							<td>
								<input type="number" name="rate_ip_limit" id="rate_ip_limit" value="<?php echo esc_attr( (string) (int) $settings['rate_ip_limit'] ); ?>" /> /
								<input type="number" name="rate_ip_window" value="<?php echo esc_attr( (string) (int) $settings['rate_ip_window'] ); ?>" /> <?php echo esc_html__( 'seconds', 'qbmbot' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rate_session_limit"><?php echo esc_html__( 'Per-session daily limit', 'qbmbot' ); ?></label></th>
							<td><input type="number" name="rate_session_limit" id="rate_session_limit" value="<?php echo esc_attr( (string) (int) $settings['rate_session_limit'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rate_daily_site_cap"><?php echo esc_html__( 'Site-wide daily AI cap', 'qbmbot' ); ?></label></th>
							<td><input type="number" name="rate_daily_site_cap" id="rate_daily_site_cap" value="<?php echo esc_attr( (string) (int) $settings['rate_daily_site_cap'] ); ?>" /></td>
						</tr>
					</table>

				<?php elseif ( 'appearance' === $tab ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="appearance_primary"><?php echo esc_html__( 'Primary color', 'qbmbot' ); ?></label></th>
							<td><input class="qbmbot-color" type="text" name="appearance_primary" id="appearance_primary" value="<?php echo esc_attr( (string) $settings['appearance_primary'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_background"><?php echo esc_html__( 'Background', 'qbmbot' ); ?></label></th>
							<td><input class="qbmbot-color" type="text" name="appearance_background" id="appearance_background" value="<?php echo esc_attr( (string) $settings['appearance_background'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_text"><?php echo esc_html__( 'Text color', 'qbmbot' ); ?></label></th>
							<td><input class="qbmbot-color" type="text" name="appearance_text" id="appearance_text" value="<?php echo esc_attr( (string) $settings['appearance_text'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_panel_bg"><?php echo esc_html__( 'Panel background', 'qbmbot' ); ?></label></th>
							<td><input class="qbmbot-color" type="text" name="appearance_panel_bg" id="appearance_panel_bg" value="<?php echo esc_attr( (string) $settings['appearance_panel_bg'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_font"><?php echo esc_html__( 'Font family', 'qbmbot' ); ?></label></th>
							<td>
								<select name="appearance_font" id="appearance_font">
									<?php
									$fonts = array(
										'Georgia, "Times New Roman", serif' => 'Georgia',
										'"Palatino Linotype", Palatino, serif' => 'Palatino',
										'"Trebuchet MS", Helvetica, sans-serif' => 'Trebuchet',
										'Verdana, Geneva, sans-serif' => 'Verdana',
										'"Courier New", Courier, monospace' => 'Courier New',
									);
									$current_font = (string) $settings['appearance_font'];
									$known        = false;
									foreach ( $fonts as $value => $label ) :
										if ( $value === $current_font ) {
											$known = true;
										}
										?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_font, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
									<option value="<?php echo esc_attr( $current_font ); ?>" <?php selected( $known, false ); ?>><?php echo esc_html__( 'Custom (current)', 'qbmbot' ); ?></option>
								</select>
								<p class="description"><?php echo esc_html__( 'Or set a custom stack via Custom CSS targeting --qbmbot-font.', 'qbmbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_radius"><?php echo esc_html__( 'Border radius (px)', 'qbmbot' ); ?></label></th>
							<td><input type="number" name="appearance_radius" id="appearance_radius" value="<?php echo esc_attr( (string) (int) $settings['appearance_radius'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_logo"><?php echo esc_html__( 'Logo / icon URL', 'qbmbot' ); ?></label></th>
							<td><input class="large-text" type="url" name="appearance_logo" id="appearance_logo" value="<?php echo esc_attr( (string) $settings['appearance_logo'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_title"><?php echo esc_html__( 'Title', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="appearance_title" id="appearance_title" value="<?php echo esc_attr( (string) $settings['appearance_title'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_subtitle"><?php echo esc_html__( 'Subtitle', 'qbmbot' ); ?></label></th>
							<td><input class="large-text" type="text" name="appearance_subtitle" id="appearance_subtitle" value="<?php echo esc_attr( (string) $settings['appearance_subtitle'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Offsets (px)', 'qbmbot' ); ?></th>
							<td>
								<label><?php echo esc_html__( 'Left', 'qbmbot' ); ?> <input type="number" name="appearance_offset_x" value="<?php echo esc_attr( (string) (int) $settings['appearance_offset_x'] ); ?>" /></label>
								<label><?php echo esc_html__( 'Bottom', 'qbmbot' ); ?> <input type="number" name="appearance_offset_y" value="<?php echo esc_attr( (string) (int) $settings['appearance_offset_y'] ); ?>" /></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="appearance_custom_css"><?php echo esc_html__( 'Custom CSS', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text code" rows="8" name="appearance_custom_css" id="appearance_custom_css"><?php echo esc_textarea( (string) $settings['appearance_custom_css'] ); ?></textarea></td>
						</tr>
					</table>
					<div class="qbmbot-preview" style="--qbmbot-primary:<?php echo esc_attr( (string) $settings['appearance_primary'] ); ?>;--qbmbot-bg:<?php echo esc_attr( (string) $settings['appearance_background'] ); ?>;--qbmbot-text:<?php echo esc_attr( (string) $settings['appearance_text'] ); ?>;--qbmbot-panel:<?php echo esc_attr( (string) $settings['appearance_panel_bg'] ); ?>;--qbmbot-font:<?php echo esc_attr( (string) $settings['appearance_font'] ); ?>;--qbmbot-radius:<?php echo esc_attr( (string) (int) $settings['appearance_radius'] ); ?>px;">
						<div class="qbmbot-preview-panel">
							<strong><?php echo esc_html( (string) $settings['appearance_title'] ); ?></strong>
							<span><?php echo esc_html( (string) $settings['appearance_subtitle'] ); ?></span>
						</div>
					</div>

				<?php elseif ( 'cf7' === $tab ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Forms', 'qbmbot' ); ?></th>
							<td>
								<?php if ( empty( $cf7_forms ) ) : ?>
									<p class="description"><?php echo esc_html__( 'No Contact Form 7 forms found (or CF7 is inactive). Leave empty to apply to all forms when CF7 is available.', 'qbmbot' ); ?></p>
								<?php else : ?>
									<p class="description"><?php echo esc_html__( 'Leave all unchecked to enable auto-replies for every form.', 'qbmbot' ); ?></p>
									<?php
									$selected = array_map( 'intval', (array) $settings['cf7_form_ids'] );
									foreach ( $cf7_forms as $form ) :
										?>
										<label style="display:block;margin-bottom:4px;">
											<input type="checkbox" name="cf7_form_ids[]" value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php checked( in_array( (int) $form['id'], $selected, true ) ); ?> />
											<?php echo esc_html( $form['title'] . ' (#' . $form['id'] . ')' ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_field_name"><?php echo esc_html__( 'Name field', 'qbmbot' ); ?></label></th>
							<td><input type="text" name="cf7_field_name" id="cf7_field_name" value="<?php echo esc_attr( (string) $settings['cf7_field_name'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_field_email"><?php echo esc_html__( 'Email field', 'qbmbot' ); ?></label></th>
							<td><input type="text" name="cf7_field_email" id="cf7_field_email" value="<?php echo esc_attr( (string) $settings['cf7_field_email'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_field_message"><?php echo esc_html__( 'Message field', 'qbmbot' ); ?></label></th>
							<td><input type="text" name="cf7_field_message" id="cf7_field_message" value="<?php echo esc_attr( (string) $settings['cf7_field_message'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_email_subject"><?php echo esc_html__( 'Reply subject', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="cf7_email_subject" id="cf7_email_subject" value="<?php echo esc_attr( (string) $settings['cf7_email_subject'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_from_name"><?php echo esc_html__( 'From name', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="text" name="cf7_from_name" id="cf7_from_name" value="<?php echo esc_attr( (string) $settings['cf7_from_name'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_from_email"><?php echo esc_html__( 'From email', 'qbmbot' ); ?></label></th>
							<td><input class="regular-text" type="email" name="cf7_from_email" id="cf7_from_email" value="<?php echo esc_attr( (string) $settings['cf7_from_email'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_fallback_body"><?php echo esc_html__( 'Fallback email body', 'qbmbot' ); ?></label></th>
							<td><textarea class="large-text" rows="5" name="cf7_fallback_body" id="cf7_fallback_body"><?php echo esc_textarea( (string) $settings['cf7_fallback_body'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'On spam / rate limit', 'qbmbot' ); ?></th>
							<td><label><input type="checkbox" name="cf7_send_fallback_on_block" value="1" <?php checked( ! empty( $settings['cf7_send_fallback_on_block'] ) ); ?> /> <?php echo esc_html__( 'Send fallback email (no AI) when blocked', 'qbmbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Ask for more details', 'qbmbot' ); ?></th>
							<td>
								<label><input type="checkbox" name="cf7_ask_follow_up" value="1" <?php checked( ! empty( $settings['cf7_ask_follow_up'] ) ); ?> /> <?php echo esc_html__( 'For covered services, ask them to reply with any missing relevant details', 'qbmbot' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Only when the enquiry matches a service you offer. Skipped for off-topic or out-of-area messages.', 'qbmbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Ask for photos', 'qbmbot' ); ?></th>
							<td>
								<label><input type="checkbox" name="cf7_ask_photos" value="1" <?php checked( ! empty( $settings['cf7_ask_photos'] ) ); ?> /> <?php echo esc_html__( 'When it makes sense for the job, ask them to reply with photos/images', 'qbmbot' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Useful for leaks, damage, installations, site conditions, etc. Not used for simple admin or coverage questions.', 'qbmbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cf7_follow_up_guidance"><?php echo esc_html__( 'Follow-up guidance', 'qbmbot' ); ?></label></th>
							<td>
								<textarea class="large-text" rows="5" name="cf7_follow_up_guidance" id="cf7_follow_up_guidance"><?php echo esc_textarea( (string) $settings['cf7_follow_up_guidance'] ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'Optional: steer which details or photo types to request for your trade.', 'qbmbot' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<?php submit_button( __( 'Save changes', 'qbmbot' ) ); ?>
			</div>
		</form>
	<?php endif; ?>
</div>
