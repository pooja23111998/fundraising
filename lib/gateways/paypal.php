<?php
if(!class_exists('WDF_Gateway_PayPal')) {
	class WDF_Gateway_PayPal extends WDF_Gateway {
		
		// Private gateway slug. Lowercase alpha (a-z) and dashes (-) only please!
		var $plugin_name = 'paypal';
		
		// Name of your gateway, for the admin side.
		var $admin_name = 'PayPal';
		
		// Public name of your gateway, for lists and such.
		var $public_name = 'PayPal';
		
		// Whether or not ssl is needed for checkout page
		var $force_ssl = false;
		
		// An array of allowed payment types (simple, standard, advanced)
		var $payment_types = 'simple, standard, advanced';
		
		// If you are redirecting to a 3rd party make sure this is set to true
		var $skip_form = true;
		
		// Allow recurring payments with your gateway
		var $allow_reccuring = false;
		
		function on_creation() {
			$settings = get_option('wdf_settings');

			$this->query = array();
			
			$this->API_Username = (isset($settings['paypal']['advanced']['api_user']) ? $settings['paypal']['advanced']['api_user'] : '');
			$this->API_Password = (isset($settings['paypal']['advanced']['api_pass']) ? $settings['paypal']['advanced']['api_pass'] : '');
			$this->API_Signature = (isset($settings['paypal']['advanced']['api_sig']) ? $settings['paypal']['advanced']['api_sig'] : '');
			if ($settings['paypal_sb'] == 'yes')	{
				$this->Adaptive_Endpoint = "https://svcs.sandbox.paypal.com/AdaptivePayments/";
				$this->paypalURL = "https://www.sandbox.paypal.com/webscr?cmd=_ap-preapproval&preapprovalkey=";
				// Generic PayPal AppID for Sandbox Testing
				$this->appId = 'APP-80W284485P519543T';
			} else {
				$this->Adaptive_Endpoint = "https://svcs.paypal.com/AdaptivePayments/";
				$this->paypalURL = "https://www.paypal.com/webscr?cmd=_ap-preapproval&preapprovalkey=";
				// Generic PayPal AppID for Sandbox Testing
				$this->appId = (isset($settings['paypal']['advanced']['app_id']) ? $settings['paypal']['advanced']['app_id'] : '');
			}
			if ($settings['paypal_sb'] == 'yes')	{
				$this->Standard_Endpoint = "https://www.sandbox.paypal.com/webscr?";
			} else {
				$this->Standard_Endpoint = "https://www.paypal.com/cgi-bin/webscr?";
			}
			
		}
		function gateway_form() {
			
		}

		function payment_form() {
			//$content = var_export($_SESSION,true);
			$content .= '<div class="wdf_paypal_payment_form">';
			$content .= '<label class="wdf_paypal_email">Insert Your PayPal Email Address</label><br />';
			$content .= '<input type="text" name="wdf_sender_email" value="'.(isset($_SESSION['wdf_sender_email']) ? is_email($_SESSION['wdf_sender_email']) : '').'" />';
			$content .= '</div>';
			return $content;
		}

		function process_simple() {
			$settings = get_option('wdf_settings');
			global $wdf;
			if($funder = get_post($_SESSION['funder_id']) ){
				$pledge_id = $wdf->generate_pledge_id();
				$this->return_url =  wdf_get_funder_page('confirmation',$funder->ID);
			
				if( isset($_SESSION['wdf_recurring']) && $_SESSION['wdf_recurring'] != false ) {
					//$this->add_query('cmd', '_xclick-auto-billing');
					//$this->add_query('&min_amount', 1.00);
					//$this->add_query('&max_amount', $wdf_send_obj->send_amount);
					$nvp = 'cmd=_xclick-subscriptions';
					$nvp .= '&a3='.$_SESSION['wdf_pledge'];
					$nvp .= '&p3=1';
					$nvp .= '&t3='.$_SESSION['wdf_recurring'];
					$nvp .= '&bn=WPMUDonations_Subscribe_WPS_'.$settings['currency'];
					$nvp .= '&src=1';
					$nvp .= '&sra=1';
					$nvp .= '&modify=1';
				} else {
					$nvp = 'cmd=_donations';
					$nvp .= '&amount='.$_SESSION['wdf_pledge'];
					$nvp .= '&cbt='.urlencode( ($settings['paypal_return_text'] ? $settings['paypal_return_text'] : __('Click Here To Complete Your Donation', 'wdf')) );
					$nvp .= '&bn=WPMUDonations_Donate_WPS_'.$settings['currency'];
				}
				$nvp .= '&no_shipping=1';
				$nvp .= '&business='.urlencode($settings['paypal_email']);
				$nvp .= '&item_name='.urlencode($funder->post_title);
				$nvp .= '&item_number='.urlencode(site_url() . ' : ' . $funder->ID);
				$nvp .= '&custom='.urlencode($funder->ID.'||'.$pledge_id);
				$nvp .= '&amp;currency_code='.$settings['currency'];
				$nvp .= '&cpp_header_image='.urlencode($settings['paypal_image_url']);
				$nvp .= '&return='.urlencode($this->return_url);
				$nvp .= '&rm=2';
				$nvp .= '&amp;notify_url='.urlencode($this->ipn_url);
				//var_export($this->Standard_Endpoint . $nvp);
				//die();
				$transaction = array(
					'gross' => $_SESSION['wdf_pledge'],
					'status' => 'Unknown',
					'gateway' => 'PayPal'
				);
				$_SESSION['wdf_pledge_id'] = $pledge_id;

				//Set transient data for one day to handle ipn
				set_transient( 'wdf_'.$this->plugin_name.'_'.$pledge_id.'_'.$_SESSION['wdf_type'], array('pledge_id' => $pledge_id), 60 * 60 * 24 );
				wp_redirect($this->Standard_Endpoint .$nvp);
				exit;
			
			} else {
				//No $_SESSION['funder_id'] was passed to this function.
				$this->create_gateway_error(__('Could not determine fundraiser','wdf'));
			}
			
		}
		function process_standard() {
			
		}
		function process_advanced() {
			$settings = get_option('wdf_settings');
			global $wdf;
			$funder_id = $_SESSION['funder_id'];
			$pledge_id = $wdf->generate_pledge_id();
			$start_stamp = time();
			$end_stamp =  strtotime(get_post_meta($funder_id, 'wdf_goal_end', true));
			$this->ipn_url = $this->ipn_url.'&fundraiser='.$funder_id.'&pledge_id='.$pledge_id;
			
			$nvpstr = "actionType=Preapproval";
			$nvpstr .= "&returnUrl=" . wdf_get_funder_page('confirmation', $funder_id);
			$nvpstr .= "&cancelUrl=" . get_post_permalink($funder_id);
			$nvpstr .= "&ipnNotificationUrl=" . urlencode($this->ipn_url);
			$nvpstr .= "&currencyCode=" . esc_attr($settings['paypal']['advanced']['currency']);
			$nvpstr .= "&feesPayer=PRIMARYRECEIVER";
			$nvpstr .= "&maxAmountPerPayment=" . $wdf->filter_price($_SESSION['wdf_pledge']);
			$nvpstr .= "&maxTotalAmountOfAllPayments=" . $wdf->filter_price($_SESSION['wdf_pledge']);
			$nvpstr .= "&displayMaxTotalAmount=TRUE";
			$nvpstr .= "&memo=" . urlencode(__('If the goal is reached your account will be charged immediately', 'wdf'));
			$nvpstr .= "&startingDate=".gmdate('Y-m-d\Z',$start_stamp);
			$nvpstr .= "&endingDate=".gmdate('Y-m-d\Z',$end_stamp);
			
			// Make the API Call to receive a token
			$response = $this->adaptive_api_call('Preapproval',$nvpstr);
			
			switch($response['responseEnvelope_ack']) {
				case 'Success' ;
					$proceed = true;
					break;
				case 'Failure' ;
					$proceed = false;
					break;
				case 'Warning' ;
					$proceed = false;
					break;
				case 'SuccessWithWarning' ;
					$proceed = true;
					break;
				case 'FailureWithWarning' ;
					$proceed = false;
					break;
			}
			
			if( $proceed && isset($response['preapprovalKey']) ) {		
				$transaction = array(
					'gross' => $_SESSION['wdf_pledge'],
					'status' => 'Unknown',
					'gateway' => 'PayPal',
					'ipn_id' => $response['preapprovalKey']
				);
				$_SESSION['wdf_pledge_id'] = $pledge_id;

				//Set transient data for one day to handle ipn
				set_transient( 'wdf_'.$this->plugin_name.'_'.$pledge_id.'_'.$_SESSION['wdf_type'], array('pledge_id' => $pledge_id), 60 * 60 * 24 );
				
				wp_redirect( $this->paypalURL . $response['preapprovalKey'] );
				exit;
			} else {
				$this->create_gateway_error(__('There was a problem connecting with the paypal gateway.  Please contact the website administrator.','wdf'));
			}

			
		}
		function confirm() {
			//$this->process_payment();
		}
		function payment_info( $content, $transaction ) {
			$content = '<div class="paypal_transaction_info">';
			
			$content .= '</div>';
			return $content;
		}
		function adaptive_api_call($methodName, $nvpStr) {
			global $wdf;
			
			//build args
			$args['headers'] = array(
				'X-PAYPAL-SECURITY-USERID' => $this->API_Username,
				'X-PAYPAL-SECURITY-PASSWORD' => $this->API_Password,
				'X-PAYPAL-SECURITY-SIGNATURE' => $this->API_Signature,
				'X-PAYPAL-DEVICE-IPADDRESS' => $_SERVER['REMOTE_ADDR'],
				'X-PAYPAL-REQUEST-DATA-FORMAT' => 'NV',
				'X-PAYPAL-REQUEST-RESPONSE-FORMAT' => 'NV',
				'X-PAYPAL-APPLICATION-ID' => $this->appId
			);
			$args['user-agent'] = "Fundraising/{$wdf->version}: http://premium.wpmudev.org/project/fundraising/ | PayPal Adaptive Payments Plugin/{$wdf->version}";
			$args['body'] = $nvpStr . '&requestEnvelope.errorLanguage=en_US';
			$args['sslverify'] = false;
			$args['timeout'] = 60;
			
			//use built in WP http class to work with most server setups
			$response = wp_remote_post($this->Adaptive_Endpoint . $methodName, $args);
			
			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
				$this->create_gateway_error( __('There was a problem connecting to PayPal. Please try again.', 'wdf'));
				return $response;
			} else {
				//convert NVPResponse to an Associative Array
				$nvpResArray = $this->deformatNVP($response['body']);
				return $nvpResArray;
			}
		}
		function handle_ipn() {
			wp_mail('cole@imakethe.com','triggered handle IPN',var_export($_REQUEST,true));
			
			if( isset($_POST['transaction_type']) && $_POST['transaction_type'] == 'Adaptive Payment PREAPPROVAL' && isset($_REQUEST['pledge_id']) ) {
				//Handle IPN for advanced payments	
				if($this->verify_paypal()) {
					$nvp = 'preapprovalKey='.$_POST['preapproval_key'];
					$nvp .= '&getBillingAddress=1';
					$details = $this->adaptive_api_call( 'PreapprovalDetails', $nvp );
										
					global $wdf;
					$post_title = $_REQUEST['pledge_id'];
					$funder_id = $_REQUEST['fundraiser'];
					$transaction = array();
					$transaction['payer_email'] = $_POST['sender_email'];
					$transaction['gateway_public'] = $this->public_name;
					$transaction['gateway'] = $this->plugin_name;
					$transaction['gross'] = ( isset($_POST['max_total_amount_of_all_payments']) ? $_POST['max_total_amount_of_all_payments'] : '' );
					$transaction['ipn_id'] = ( isset($_POST['preapproval_key']) ? $_POST['preapproval_key'] : '' );
					
					$full_name = (isset($details['addressList_address(0)_addresseeName']) ? explode(' ',$details['addressList_address(0)_addresseeName'],2) : false);
					if($full_name != false) {
						$transaction['first_name'] = $full_name[0];
						$transaction['last_name'] = $full_name[1];
					}
					switch($_POST['status']) {
						case 'ACTIVE' :
							$status = 'publish';
							$transaction['status'] = 'Pre-Approved';
							break;
						case 'CANCELED' :
							$status = 'draft';
							$transaction['status'] = 'Canceled';
							break;
						default :
							$status = 'draft';
							$transaction['status'] = 'Unknown';
							break;
					}
					
					$wdf->update_pledge( $post_title, $funder_id, $status, $transaction);
						
				} else {
					header("HTTP/1.1 503 Service Unavailable");
					_e( 'There was a problem verifying the IPN string with PayPal. Please try again.', 'mp' );
					exit;
				}
			} elseif ( isset( $_POST['txn_type'] ) ) {
				
				//Handle IPN for simple payments
				if($this->verify_paypal()) {
					$custom = explode('||',$_POST['custom']);
					$funder_id = $custom[0];
					$post_title = $custom[1];
					$transaction = array();
					
					if($_POST['txn_type'] == 'subscr_signup') {
						$transaction['gross'] = $_POST['mc_amount3'];
						$cycle = explode(' ',$_POST['period3']);
						$transaction['cycle'] = $cycle[1];
						$transaction['recurring'] = $_POST['recurring'];
					} else if($_POST['txn_type'] == 'web_accept') {
						$transaction['gross'] = $_POST['payment_gross'];
					} else {
						//Not an accepted transaction type
						die();
					}
					$transaction['ipn_id'] = $_POST['txn_id'];
					$transaction['first_name'] = $_POST['first_name'];
					$transaction['last_name'] = $_POST['last_name'];
					$transaction['payment_fee'] = $_POST['payment_fee'];
					$transaction['payer_email'] = (isset($_POST['payer_email']) ? $_POST['payer_email'] : 'johndoe@' . home_url() );
					$transaction['gateway_public'] = $this->public_name;
					$transaction['gateway'] = $this->plugin_name;
					switch($_POST['payment_status']) {
						case 'Pending' :
							$status = 'publish';
							$transaction['status'] = 'Pending';
							break;
						case 'Refunded' :
							$status = 'draft';
							$transaction['status'] = 'Refunded';
							break;
						case 'Reversed' :
							$status = 'draft';
							$transaction['status'] = 'Reversed';
						case 'Processed' :
							$status = 'publish';
							$transaction['status'] = 'Processed';
							break;
						case 'Completed' :
							$status = 'publish';
							$transaction['status'] = 'Completed';
						default:
							$status = 'draft';
							$transaction['status'] = 'Unknown';
							break;
					}
				
					global $wdf;
					$wdf->update_pledge($post_title,$funder_id,$status,$transaction);
					
					
					
				}
			}
			die();
		}
		function execute_advanced_payment($pledge, $transaction) {
			$debug = var_export($pledge,true);
			$debug .= var_export($transaction,true);
			wp_mail('cole@imakethe.com','gateway execute payment', $debug);
		}
		function verify_paypal() {
			global $wdf;
			
			$settings = get_option('wdf_settings');
			if ($settings['paypal_sb'] == 'yes') {
				$domain = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
			} else {
				$domain = 'https://www.paypal.com/cgi-bin/webscr';
			}
			
			$return = array();
			$return += array('cmd' => '_notify-validate');
			$return += $_POST;
			/*foreach ($_POST as $k => $v) {
				if (get_magic_quotes_gpc()) $v = stripslashes($v);
				$req .= '&' . $k . '=' . urlencode($v);
			}*/
			$args = array();
			$args['user-agent'] = "Fundraising/{$wdf->version}: http://premium.wpmudev.org/project/fundraising/";
			$args['body'] = $return;
			$args['sslverify'] = false;
			$args['timeout'] = 60;
			
			
			
			  //use built in WP http class to work with most server setups
				$response = wp_remote_post($domain, $args);
			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200 || $response['body'] != 'VERIFIED') {
				return false;
			  } else {
				return true;
			  }
		}
		
		//This function will take NVPString and convert it to an Associative Array and it will decode the response.
		function deformatNVP($nvpstr) {
			parse_str($nvpstr, $nvpArray);
			return $nvpArray;
		}
		
		function admin_settings() {
			if (!class_exists('WpmuDev_HelpTooltips')) require_once WDF_PLUGIN_BASE_DIR . '/lib/external/class.wd_help_tooltips.php';
				$tips = new WpmuDev_HelpTooltips();
				$tips->set_icon_url(WDF_PLUGIN_URL.'/img/information.png');
				$settings = get_option('wdf_settings'); ?>
			<table class="form-table">
				<tbody>
				<tr valign="top">
					<th scope="row"> <label for="wdf_settings[paypal_sb]"><?php echo __('Use PayPal Sandbox?','wdf'); ?></label>
					</th>
					<td><select name="wdf_settings[paypal_sb]" id="wdf_settings_paypal_sb">
							<option value="no" <?php selected($settings['paypal_sb'],'no'); ?>>No</option>
							<option value="yes" <?php selected($settings['paypal_sb'],'yes'); ?>>Yes</option>
						</select></td>
				</tr>
			<?php if(in_array('simple', $settings['payment_types'])) : ?>
				<tr>
					<td colspan="2">
						<h4><?php _e('Simple Payment Options','wdf'); ?></h4>
						<div class="message updated below-h2" style="width: auto;"><p><?php _e('In order for donations to be logged properly you must turn on PayPal Instant Payment Notifications and point it to','wdf'); ?> <span class="description"><?php echo $this->ipn_url; ?></span></p></div>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"> <label for="wdf_settings[paypal_email]"><?php echo __('PayPal Email Address:','wdf'); ?></label>
					</th>
					<td><input class="regular-text" type="text" id="wdf_settings_paypal_email" name="wdf_settings[paypal_email]" value="<?php echo esc_attr($settings['paypal_email']); ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"> <label for="wdf_settings[paypal_image_url]"><?php echo __('PayPal Checkout Header Image ','wdf'); ?></label>
						<?php echo $tips->add_tip('PayPal allows you to use a custom header images during the purchase process.  PayPal recommends using an image from a secure https:// link, but this is not required.'); ?> </th>
					<td><input class="regular-text" type="text" name="wdf_settings[paypal_image_url]" value="<?php echo $settings['paypal_image_url']; ?>" /></td>
				</tr>
			<?php endif; ?>
			<?php if(in_array('advanced', $settings['payment_types'])) : ?>
				<tr>
					<td colspan="2">
					<h4><?php _e('Advanced Payment Options','wdf'); ?></h4>
					</td>
				</tr>
				<?php /*?><tr>
					<th scope="row"><?php _e('Fees To Collect', 'wdf'); ?></th>
					<td><span class="description">
						<?php _e('Enter a percentage of all store sales to collect as a fee. Decimals allowed.', 'wdf') ?>
						</span><br />
						<input value="<?php echo esc_attr( (isset($settings['paypal']['advanced']['percentage']) ? $settings['paypal']['advanced']['percentage'] : '') ); ?>" size="3" name="wdf_settings[paypal][advanced][percentage]" type="text" />% 
					</td>
				</tr><?php */?>
				<tr>
					<th scope="row"><?php _e('PayPal Email Address', 'wdf') ?></th>
					<td><span class="description">
						<?php _e('Please enter your PayPal email address or business id you want to recieve fees at.', 'wdf') ?>
						</span><br />
						<input value="<?php echo esc_attr( (isset($settings['paypal']['advanced']['email']) ? $settings['paypal']['advanced']['email'] : '') ); ?>" size="40" name="wdf_settings[paypal][advanced][email]" type="text" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Paypal Currency', 'mp') ?></th>
					<td>
					  <select name="wdf_settings[paypal][advanced][currency]">
					  <?php
					  $sel_currency = isset($settings['paypal']['advanced']['currency']) ? $settings['paypal']['advanced']['currency'] : $settings['currency'];
					  $currencies = array(
						  'AUD' => 'AUD - Australian Dollar',
						  'BRL' => 'BRL - Brazilian Real',
						  'CAD' => 'CAD - Canadian Dollar',
						  'CHF' => 'CHF - Swiss Franc',
						  'CZK' => 'CZK - Czech Koruna',
						  'DKK' => 'DKK - Danish Krone',
						  'EUR' => 'EUR - Euro',
						  'GBP' => 'GBP - Pound Sterling',
						  'ILS' => 'ILS - Israeli Shekel',
						  'HKD' => 'HKD - Hong Kong Dollar',
						  'HUF' => 'HUF - Hungarian Forint',
						  'JPY' => 'JPY - Japanese Yen',
						  'MYR' => 'MYR - Malaysian Ringgits',
						  'MXN' => 'MXN - Mexican Peso',
						  'NOK' => 'NOK - Norwegian Krone',
						  'NZD' => 'NZD - New Zealand Dollar',
						  'PHP' => 'PHP - Philippine Pesos',
						  'PLN' => 'PLN - Polish Zloty',
						  'SEK' => 'SEK - Swedish Krona',
						  'SGD' => 'SGD - Singapore Dollar',
						  'TWD' => 'TWD - Taiwan New Dollars',
						  'THB' => 'THB - Thai Baht',
										'TRY' => 'TRY - Turkish lira',
						  'USD' => 'USD - U.S. Dollar'
					  );
			
					  foreach ($currencies as $k => $v) {
						  echo '		<option value="' . $k . '"' . ($k == $sel_currency ? ' selected' : '') . '>' . wp_specialchars($v, true) . '</option>' . "\n";
					  }
					  ?>
					  </select>
					</td>
					</tr>
				<tr>
					<th scope="row"><?php _e('PayPal API Credentials', 'wdf') ?></th>
					<td><span class="description">
						<?php _e('You must login to PayPal and create an API signature to get your credentials. <a target="_blank" href="https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_NVPAPIBasics#id084DN0AK0HS">Instructions &raquo;</a>', 'wdf') ?>
						</span>
						<p>
							<label>
								<?php _e('API Username', 'wdf') ?>
								<br />
								<input value="<?php echo esc_attr( (isset($settings['paypal']['advanced']['api_user']) ? $settings['paypal']['advanced']['api_user'] : '') ); ?>" size="30" name="wdf_settings[paypal][advanced][api_user]" type="text" />
							</label>
						</p>
						<p>
							<label>
								<?php _e('API Password', 'wdf') ?>
								<br />
								<input value="<?php echo esc_attr( (isset($settings['paypal']['advanced']['api_pass']) ? $settings['paypal']['advanced']['api_pass'] : '') ); ?>" size="20" name="wdf_settings[paypal][advanced][api_pass]" type="text" />
							</label>
						</p>
						<p>
							<label>
								<?php _e('Signature', 'wdf') ?>
								<br />
								<input value="<?php echo esc_attr( (isset($settings['paypal']['advanced']['api_sig']) ? $settings['paypal']['advanced']['api_sig'] : '') ); ?>" size="70" name="wdf_settings[paypal][advanced][api_sig]" type="text" />
							</label>
						</p>
						<span class="description">
						<?php _e('You must register this application with PayPal using your business account login to get an Application ID that will work with your API credentials. A bit of a hassle, but worth it! In the near future we will be looking for ways to simplify this process. <a target="_blank" href="https://appreview.x.com/create-appvetting-app!input.jspa">Register then submit your application</a> while logged in to the developer portal.</a> Note that you do not need an Application ID for testing in sandbox mode. <a target="_blank" href="https://www.x.com/community/ppx/apps101/go-live">More Information &raquo;</a>', 'wdf') ?>
						<br />
						<?php _e('View an example form &raquo;', 'wdf'); ?>
						</a> </span>
						<p>
							<label>
								<?php _e('Application ID', 'wdf') ?>
								<br />
								<input value="<?php echo esc_attr( (isset($settings['paypal']['advanced']['app_id']) ? $settings['paypal']['advanced']['app_id'] : '') ); ?>" size="50" name="mp[paypal][advanced][app_id]" type="text" />
							</label>
						</p></td>
				</tr>
				<?php /*?><tr>
					<th scope="row"><?php _e('Gateway Settings Page Message', 'mp'); ?></th>
					<td><span class="description">
						<?php _e('This message is displayed at the top of the gateway settings page to store admins. It\'s a good place to inform them of your fees or put any sales messages. Optional, HTML allowed.', 'mp') ?>
						</span><br />
						<textarea class="mp_msgs_txt" name="mp[gateways][paypal-chained][msg]"><?php echo esc_html($settings['gateways']['paypal-chained']['msg']); ?></textarea></td>
				</tr><?php */?>
			
			<?php endif; ?>
				</tbody>
			</table>
			<?php
		}
		function save_gateway_settings() {
			
			if( isset($_POST['wdf_settings']['paypal']) ) {
				// Init array for new settings
				$new = array();
				
				// Advanced Settings
				if( isset($_POST['wdf_settings']['paypal']['advanced']) && is_array($_POST['wdf_settings']['paypal']['advanced'])) {
					$new['paypal']['advanced'] = $_POST['wdf_settings']['paypal']['advanced'];
					$new['paypal']['advanced'] = array_map('esc_attr',$new['paypal']['advanced']);
					
					$settings = get_option('wdf_settings');
					$settings = array_merge($settings,$new);
					update_option('wdf_settings',$settings);
				}
					
			}
		}
		
	}
wdf_register_gateway_plugin('WDF_Gateway_PayPal', 'paypal', 'PayPal', array('simple','standard','advanced'));
}

?>
