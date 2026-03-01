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
			$(document).on('click', '#wcpos-vipps-log-toggle', this.toggleLog.bind(this));
		},

		togglePushButton: function () {
			var phone = $('#wcpos-vipps-phone').val().trim();
			$('#wcpos-vipps-send-push').prop('disabled', !phone);
		},

		// ---- Log panel ----

		toggleLog: function (e) {
			e.preventDefault();
			var $btn = $('#wcpos-vipps-log-toggle');
			var $container = $('#wcpos-vipps-log-container');

			$btn.toggleClass('open');
			$container.toggleClass('open');

			var isOpen = $btn.hasClass('open');
			$btn.find('.label').text(
				isOpen ? wcposVippsData.strings.hideLog : wcposVippsData.strings.showLog
			);
		},

		appendLog: function (message) {
			if (!wcposVippsData.debug) {
				return;
			}

			var $log = $('#wcpos-vipps-log');
			if (!$log.length) {
				return;
			}

			var current = $log.val();
			var prefix = new Date().toLocaleTimeString('en-GB', { hour12: false });
			$log.val((current ? current + '\n' : '') + prefix + ' ' + message);

			// Auto-scroll to bottom.
			$log[0].scrollTop = $log[0].scrollHeight;
		},

		processLogEntries: function (response) {
			if (!wcposVippsData.debug || !response || !response.data) {
				return;
			}

			var entries = response.data.log_entries;
			if (!entries || !entries.length) {
				return;
			}

			var $log = $('#wcpos-vipps-log');
			if (!$log.length) {
				return;
			}

			var current = $log.val();
			for (var i = 0; i < entries.length; i++) {
				current = (current ? current + '\n' : '') + entries[i];
			}
			$log.val(current);
			$log[0].scrollTop = $log[0].scrollHeight;
		},

		// ---- Payment flows ----

		generateQr: function (e) {
			e.preventDefault();
			this.appendLog('[CLIENT] Generating QR code...');
			this.createPayment('qr');
		},

		sendPush: function (e) {
			e.preventDefault();
			var phone = $('#wcpos-vipps-phone').val().trim();

			if (!phone) {
				this.showStatus(wcposVippsData.strings.phoneRequired, 'error');
				this.appendLog('[CLIENT] Push failed — no phone number');
				return;
			}

			this.appendLog('[CLIENT] Sending push to ' + phone + '...');
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
				self.processLogEntries(response);

				if (response.success) {
					if (flow === 'qr' && response.data.qrUrl) {
						$('#wcpos-vipps-qr-image').attr('src', response.data.qrUrl);
						$('#wcpos-vipps-qr-display').show();
						self.appendLog('[CLIENT] QR code displayed');
					}

					self.showStatus(wcposVippsData.strings.waitingForPayment, 'message');
					$('#wcpos-vipps-cancel').show();
					self.appendLog('[CLIENT] Polling started (every 2s, max 5 min)');
					self.startPolling();
				} else {
					var msg = response.data.message || wcposVippsData.strings.paymentFailed;
					self.showStatus(msg, 'error');
					self.appendLog('[CLIENT] Payment creation failed: ' + msg);
					self.setLoading(false);
				}
			})
			.fail(function () {
				self.showStatus(wcposVippsData.strings.networkError, 'error');
				self.appendLog('[CLIENT] Network error during payment creation');
				self.setLoading(false);
			});
		},

		// ---- Polling ----

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
				this.appendLog('[CLIENT] Polling timed out after 5 minutes');
				this.resetUI();
				return;
			}

			$.post(wcposVippsData.ajaxUrl, {
				action: 'wcpos_vipps_check_status',
				order_id: wcposVippsData.orderId,
				token: wcposVippsData.token
			})
			.done(function (response) {
				self.processLogEntries(response);

				if (!response.success) {
					return;
				}

				var state = response.data.state;

				if (state === 'AUTHORIZED') {
					self.stopPolling();
					self.showStatus(wcposVippsData.strings.paymentSuccess, 'success');
					self.appendLog('[CLIENT] Payment authorized — submitting order');
					self.submitForm();
				} else if (state === 'ABORTED' || state === 'TERMINATED') {
					self.stopPolling();
					self.showStatus(wcposVippsData.strings.paymentCancelled, 'cancelled');
					self.appendLog('[CLIENT] Payment ' + state.toLowerCase() + ' by customer');
					self.resetUI();
				} else if (state === 'EXPIRED') {
					self.stopPolling();
					self.showStatus(wcposVippsData.strings.paymentExpired, 'error');
					self.appendLog('[CLIENT] Payment expired');
					self.resetUI();
				}
				// CREATED = still waiting, continue polling
			})
			.fail(function () {
				self.appendLog('[CLIENT] Network error during status check');
			});
		},

		// ---- Cancel ----

		cancelPayment: function (e) {
			e.preventDefault();
			this.stopPolling();
			this.appendLog('[CLIENT] Cancelling payment...');

			var self = this;

			$.post(wcposVippsData.ajaxUrl, {
				action: 'wcpos_vipps_cancel_payment',
				order_id: wcposVippsData.orderId,
				token: wcposVippsData.token
			})
			.done(function (response) {
				self.processLogEntries(response);
			});

			this.showStatus(wcposVippsData.strings.paymentCancelled, 'cancelled');
			this.resetUI();
		},

		// ---- UI helpers ----

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
