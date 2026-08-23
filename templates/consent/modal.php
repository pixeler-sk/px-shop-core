<?php
/**
 * Consent settings - the second layer.
 *
 * A switch per category and, inside it, a switch per service with its
 * purpose, its provider and the list of cookies it stores. Nothing is
 * pre-ticked except the necessary category, which cannot be switched off
 * because those cookies do not depend on consent.
 *
 * Override: yourtheme/px-shop-core/consent/modal.php
 *
 * @var array $args {
 *     @type array  $categories Consent categories.
 *     @type array  $grouped    Enabled services grouped by category.
 *     @type string $policy_url Cookie policy page, '' when none is set.
 * }
 *
 * @package PxShopCore
 */

defined( 'ABSPATH' ) || exit;

$categories = isset( $args['categories'] ) ? (array) $args['categories'] : array();
$grouped    = isset( $args['grouped'] ) ? (array) $args['grouped'] : array();
$policy_url = isset( $args['policy_url'] ) ? (string) $args['policy_url'] : '';
?>
<div class="px-consent-modal" id="px-consent-modal" data-px-consent-modal hidden>
	<div class="px-consent-modal__overlay" data-px-consent-close></div>

	<div class="px-consent-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="px-consent-modal-title" tabindex="-1">

		<div class="px-consent-modal__head">
			<h2 class="px-consent-modal__title" id="px-consent-modal-title"><?php esc_html_e( 'Cookie settings', 'px-shop-core' ); ?></h2>
			<button type="button" class="px-consent-modal__close" data-px-consent-close aria-label="<?php esc_attr_e( 'Close settings', 'px-shop-core' ); ?>">&times;</button>
		</div>

		<div class="px-consent-modal__body">

			<p class="px-consent-modal__intro">
				<?php esc_html_e( 'Choose what you allow. You can change your choice at any time - the link in the footer opens this window again.', 'px-shop-core' ); ?>
				<?php if ( $policy_url ) : ?>
					<a class="px-consent-modal__link" href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Cookie policy', 'px-shop-core' ); ?></a>
				<?php endif; ?>
			</p>

			<?php foreach ( $categories as $px_cat_id => $px_cat ) : ?>
				<?php
				$px_locked   = ! empty( $px_cat['locked'] );
				$px_services = isset( $grouped[ $px_cat_id ] ) ? $grouped[ $px_cat_id ] : array();
				$px_body_id  = 'px-consent-group-' . $px_cat_id;

				// A category with no service on this site is a switch that
				// decides nothing - it only makes the choice look bigger.
				if ( ! $px_services ) {
					continue;
				}
				?>
				<section class="px-consent-group<?php echo $px_locked ? ' px-consent-group--locked' : ''; ?>" data-px-consent-group="<?php echo esc_attr( $px_cat_id ); ?>">

					<div class="px-consent-group__head">
						<button type="button" class="px-consent-group__toggle" data-px-consent-expand aria-expanded="false" aria-controls="<?php echo esc_attr( $px_body_id ); ?>">
							<span class="px-consent-group__name"><?php echo esc_html( $px_cat['name'] ); ?></span>
							<span class="px-consent-group__count"><?php echo esc_html( sprintf( /* translators: %d: number of services. */ _n( '%d service', '%d services', count( $px_services ), 'px-shop-core' ), count( $px_services ) ) ); ?></span>
						</button>

						<label class="px-consent-switch">
							<input
								type="checkbox"
								class="px-consent-switch__input"
								data-px-consent-category="<?php echo esc_attr( $px_cat_id ); ?>"
								<?php echo $px_locked ? 'checked disabled' : ''; ?>
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: category name. */ __( 'Allow %s', 'px-shop-core' ), $px_cat['name'] ) ); ?>"
							>
							<span class="px-consent-switch__ui" aria-hidden="true"></span>
							<span class="px-consent-switch__state">
								<?php echo $px_locked ? esc_html__( 'Always on', 'px-shop-core' ) : ''; ?>
							</span>
						</label>
					</div>

					<div class="px-consent-group__body" id="<?php echo esc_attr( $px_body_id ); ?>" hidden>
						<p class="px-consent-group__desc"><?php echo esc_html( $px_cat['desc'] ); ?></p>

						<?php if ( $px_services ) : ?>
							<ul class="px-consent-services">
								<?php foreach ( $px_services as $px_id => $px_service ) : ?>
									<?php $px_detail_id = 'px-consent-service-' . $px_id; ?>
									<li class="px-consent-service" data-px-consent-service-row="<?php echo esc_attr( $px_id ); ?>">

										<div class="px-consent-service__head">
											<label class="px-consent-switch px-consent-switch--service">
												<input
													type="checkbox"
													class="px-consent-switch__input"
													data-px-consent-service="<?php echo esc_attr( $px_id ); ?>"
													data-px-consent-service-category="<?php echo esc_attr( $px_cat_id ); ?>"
													<?php echo $px_locked ? 'checked disabled' : ''; ?>
													aria-label="<?php echo esc_attr( sprintf( /* translators: %s: service name. */ __( 'Allow %s', 'px-shop-core' ), $px_service['name'] ) ); ?>"
												>
												<span class="px-consent-switch__ui" aria-hidden="true"></span>
												<span class="px-consent-service__name"><?php echo esc_html( $px_service['name'] ); ?></span>
											</label>

											<button type="button" class="px-consent-service__more" data-px-consent-expand aria-expanded="false" aria-controls="<?php echo esc_attr( $px_detail_id ); ?>">
												<?php esc_html_e( 'Details', 'px-shop-core' ); ?>
											</button>
										</div>

										<div class="px-consent-service__detail" id="<?php echo esc_attr( $px_detail_id ); ?>" hidden>
											<?php if ( $px_service['purpose'] ) : ?>
												<p class="px-consent-service__purpose"><?php echo esc_html( $px_service['purpose'] ); ?></p>
											<?php endif; ?>

											<p class="px-consent-service__provider">
												<?php if ( $px_service['provider'] ) : ?>
													<span class="px-consent-service__provider-name">
														<?php
														printf(
															/* translators: %s: name and address of the provider. */
															esc_html__( 'Provider: %s', 'px-shop-core' ),
															esc_html( $px_service['provider'] )
														);
														?>
													</span>
												<?php endif; ?>
												<?php if ( $px_service['privacy'] ) : ?>
													<a class="px-consent-service__privacy" href="<?php echo esc_url( $px_service['privacy'] ); ?>" target="_blank" rel="noopener nofollow">
														<?php esc_html_e( 'Privacy policy', 'px-shop-core' ); ?>
													</a>
												<?php endif; ?>
											</p>

											<?php if ( $px_service['cookies'] ) : ?>
												<table class="px-consent-cookies">
													<caption class="px-consent-sr">
														<?php
														printf(
															/* translators: %s: service name. */
															esc_html__( 'Cookies stored by %s', 'px-shop-core' ),
															esc_html( $px_service['name'] )
														);
														?>
													</caption>
													<thead>
														<tr>
															<th scope="col"><?php esc_html_e( 'Name', 'px-shop-core' ); ?></th>
															<th scope="col"><?php esc_html_e( 'Expiry', 'px-shop-core' ); ?></th>
															<th scope="col"><?php esc_html_e( 'Purpose', 'px-shop-core' ); ?></th>
														</tr>
													</thead>
													<tbody>
														<?php foreach ( $px_service['cookies'] as $px_cookie ) : ?>
															<tr>
																<td data-px-label="<?php esc_attr_e( 'Name', 'px-shop-core' ); ?>"><code><?php echo esc_html( $px_cookie['name'] ); ?></code></td>
																<td data-px-label="<?php esc_attr_e( 'Expiry', 'px-shop-core' ); ?>"><?php echo esc_html( isset( $px_cookie['expiry'] ) ? $px_cookie['expiry'] : '' ); ?></td>
																<td data-px-label="<?php esc_attr_e( 'Purpose', 'px-shop-core' ); ?>"><?php echo esc_html( isset( $px_cookie['desc'] ) ? $px_cookie['desc'] : '' ); ?></td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											<?php endif; ?>
										</div>

									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>

				</section>
			<?php endforeach; ?>

		</div>

		<div class="px-consent-modal__actions">
			<button type="button" class="px-consent__btn px-consent__btn--accept" data-px-consent-accept>
				<?php esc_html_e( 'Accept all', 'px-shop-core' ); ?>
			</button>
			<button type="button" class="px-consent__btn px-consent__btn--reject" data-px-consent-reject>
				<?php esc_html_e( 'Reject all', 'px-shop-core' ); ?>
			</button>
			<button type="button" class="px-consent__btn px-consent__btn--save" data-px-consent-save>
				<?php esc_html_e( 'Save choice', 'px-shop-core' ); ?>
			</button>
		</div>

	</div>
</div>
