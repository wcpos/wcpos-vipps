(function ($) {
	'use strict';

	var wcposVipps = {
		polling: null,
		pollCount: 0,
		maxPolls: 150, // 5 minutes at 2-second intervals

		init: function () {
			$(document).on('click', '#wcpos-vipps-generate-qr', this.generateQr.bind(this));
			$(document).on('click', '#wcpos-vipps-send-push', this.sendPush.bind(this));
			$(document).on('click', '#wcpos-vipps-cancel', this.cancelPayment.bind(this));
			$(document).on('input', '#wcpos-vipps-phone', this.togglePushButton.bind(this));
		},

		togglePushButton: function () {
			var phone = $('#wcpos-vipps-phone').val().trim();
			$('#wcpos-vipps-send-push').prop('disabled', !phone);
		},

		generateQr: function (e) {
			e.preventDefault();
			this.createPayment('qr');
		},

		sendPush: function (e) {
			e.preventDefault();
			var phone = $('#wcpos-vipps-phone').val().trim();

			if (!phone) {
				this.showStatus(wcposVippsData.strings.phoneRequired, 'error');
				return;
			}

			this.createPayment('push', phone);
		},

		createPayment: function (flow, phone) {
			var self = this;
			this.setLoading(true);
			this.showStatus(
				flow === 'qr' ? wcposVippsData.strings.generatingQr : wcposVippsData.strings.sendingPush,
				'message'
			);

			$.post(wcposVippsData.ajaxUrl, {
				action: 'wcpos_vipps_create_payment',
				order_id: wcposVippsData.orderId,
				token: wcposVippsData.token,
				flow: flow,
				phone: phone || ''
			})
			.done(function (response) {
				if (response.success) {
					if (flow === 'qr' && response.data.qrUrl) {
						$('#wcpos-vipps-qr-image').attr('src', response.data.qrUrl);
						$('#wcpos-vipps-qr-display').show();
					}

					self.showStatus(wcposVippsData.strings.waitingForPayment, 'message');
					$('#wcpos-vipps-cancel').show();
					self.startPolling();
				} else {
					self.showStatus(response.data.message || wcposVippsData.strings.paymentFailed, 'error');
					self.setLoading(false);
				}
			})
			.fail(function () {
				self.showStatus(wcposVippsData.strings.networkError, 'error');
				self.setLoading(false);
			});
		},

		startPolling: function () {
			this.pollCount = 0;
			this.polling = setInterval(this.checkStatus.bind(this), 2000);
		},

		stopPolling: function () {
			if (this.polling) {
				clearInterval(this.polling);
				this.polling = null;
			}
		},

		checkStatus: function () {
			var self = this;
			this.pollCount++;

			if (this.pollCount >= this.maxPolls) {
				this.stopPolling();
				this.showStatus(wcposVippsData.strings.paymentExpired, 'error');
				this.resetUI();
				return;
			}

			$.post(wcposVippsData.ajaxUrl, {
				action: 'wcpos_vipps_check_status',
				order_id: wcposVippsData.orderId,
				token: wcposVippsData.token
			})
			.done(function (response) {
				if (!response.success) {
					return;
				}

				var state = response.data.state;

				if (state === 'AUTHORIZED') {
					self.stopPolling();
					self.showStatus(wcposVippsData.strings.paymentSuccess, 'success');
					self.submitForm();
				} else if (state === 'ABORTED' || state === 'TERMINATED') {
					self.stopPolling();
					self.showStatus(wcposVippsData.strings.paymentCancelled, 'cancelled');
					self.resetUI();
				} else if (state === 'EXPIRED') {
					self.stopPolling();
					self.showStatus(wcposVippsData.strings.paymentExpired, 'error');
					self.resetUI();
				}
				// CREATED = still waiting, continue polling
			});
		},

		cancelPayment: function (e) {
			e.preventDefault();
			this.stopPolling();

			$.post(wcposVippsData.ajaxUrl, {
				action: 'wcpos_vipps_cancel_payment',
				order_id: wcposVippsData.orderId,
				token: wcposVippsData.token
			});

			this.showStatus(wcposVippsData.strings.paymentCancelled, 'cancelled');
			this.resetUI();
		},

		submitForm: function () {
			var $form = $('form#order_review, form.checkout');
			if ($form.length) {
				var $button = $form.find('#place_order, button[type="submit"]');
				if ($button.length) {
					$button.click();
				} else {
					$form.submit();
				}
			}
		},

		showStatus: function (message, type) {
			$('#wcpos-vipps-status')
				.removeClass('wcpos-vipps-status-success wcpos-vipps-status-error wcpos-vipps-status-cancelled wcpos-vipps-status-message')
				.addClass('wcpos-vipps-status-' + type)
				.text(message)
				.show();
		},

		setLoading: function (loading) {
			$('#wcpos-vipps-generate-qr, #wcpos-vipps-send-push').prop('disabled', loading);
			if (loading) {
				$('#wcpos-vipps-phone').prop('disabled', true);
			}
		},

		resetUI: function () {
			this.setLoading(false);
			$('#wcpos-vipps-phone').prop('disabled', false);
			$('#wcpos-vipps-cancel').hide();
			$('#wcpos-vipps-qr-display').hide();
			this.togglePushButton();
		}
	};

	$(document).ready(function () {
		wcposVipps.init();
	});
})(jQuery);
