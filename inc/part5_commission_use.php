<?php

/*******************
 ******************* Tutoriel expliquant ce fichier : https://mosaika.fr/utiliser-cagnotte-reduction-parrain-woocommerce
 *******************/

/**
 * On affiche la checkbox "J'utilise mes X points" sur la page de commande
 */
function msk_display_use_points_checkbox() {
	$user_points = msk_get_customer_commission_balance(get_current_user_id())['balance'];

	if ($user_points > 0) {
		if (isset($_POST['post_data'])) {
			parse_str($_POST['post_data'], $form_data);
		} else {
			$form_data = $_POST; // fallback for final checkout (non-ajax)
		} 

		if (empty($form_data)) $form_data['use-points'] = 'on';

		if ($user_points > WC()->cart->subtotal_ex_tax) {
			$user_points = WC()->cart->subtotal_ex_tax;
		} ?>
		
		<!-- Idéalement, placer ce bout de Javascript dans un fichier .js de votre thème/plugin -->
		<script>jQuery('form.checkout').on('change', '#use-points', function(){ jQuery('body').trigger('update_checkout'); });</script>

		<fieldset class="use-points">
			<label for="use-points">
				<input type="hidden" name="use-points" value="off" />
				<input type="checkbox" <?php checked($form_data['use-points'], 'on'); ?> id="use-points" name="use-points" value="on" />
				<span><?php printf(__('J\'utilise mes %1$s points.', 'mosaika'), msk_money_to_points_value($user_points)); ?></span>
			</label>	
		</fieldset>
	<?php }
}
add_action('woocommerce_checkout_before_order_review', 'msk_display_use_points_checkbox');

/**
 * On applique une réduction sur la commande si le client a coché l'usage de ses points
 */
function msk_add_discount_to_cart_total($cart) {
	if (!$_POST || (is_admin() && !is_ajax())) {
		return;
	}

	if (isset($_POST['post_data'])) {
		parse_str($_POST['post_data'], $form_data);
	} else {
		$form_data = $_POST; // fallback for final checkout (non-ajax)
	}

	if (isset($form_data['use-points']) && $form_data['use-points'] == 'on') {
		$discount = msk_get_customer_commission_balance(get_current_user_id())['balance'];

		if ($discount > 0) {
			$cart_subtotal = WC()->cart->subtotal_ex_tax;

			if ($discount > $cart_subtotal) {
				$discount = $cart_subtotal;
			}

			WC()->cart->add_fee(__('Utilisation de vos points', 'mosaika'), -$discount, false, '');
		}
	}
}
add_action('woocommerce_cart_calculate_fees', 'msk_add_discount_to_cart_total');


/**
 * Lorsqu'un parrain utilise ses points gagnés via commissions, on enregistre leur usage dans la BDD
 */
function msk_save_commissions_use_from_order($order_id, $old_status, $new_status) {
	global $wpdb;
	$commissions_table_name = $wpdb->prefix . 'commissions';

	$order = wc_get_order($order_id);
	$order_status = $new_status;
	$order_data = $order->get_data();
	$type = 'use';

	if ($old_status == 'completed') {
		$wpdb->delete(
			$commissions_table_name,
			array('order_id' => $order_id, 'type' => $type),
			array('%d', '%s')
		);
	}

	if ($order_status == 'completed' && isset($order_data['fee_lines'])) {
		foreach ($order_data['fee_lines'] as $fee) {
			if (is_a($fee, 'WC_Order_Item_Fee')) {
				if ($fee->get_name() == __('Utilisation de vos points', 'mosaika')) {
					$commission_used = abs($fee->get_total());

					if ($commission_used > 0) {
						$data = array(
							'type' => $type,
							'amount' => $commission_used,
							'user_id' => $order->get_customer_id(),
							'order_id' => $order_id,
							'time' => current_time('mysql')
						);

						$wpdb->insert(
							$commissions_table_name,
							$data
						);
					}
				}
			}
		}
	}
}
add_action('woocommerce_order_status_changed', 'msk_save_commissions_use_from_order', 10, 3);