(function ($) {
    'use strict';

    var config = window.patwcPaymentData || {};
    var pollTimer = null;
    var paymentStartedAt = 0;
    var pollInterval = parseInt(config.pollInterval, 10) || 1500;
    var timeoutMs = parseInt(config.timeoutMs, 10) || 300000;
    var strings = $.extend({
        ready: 'Ready to start payment.',
        starting: 'Starting terminal payment...',
        waiting: 'Waiting for the terminal result...',
        approved: 'Payment approved. Completing the order...',
        retry: 'Payment was not approved. Please check the terminal and try again.',
        canceling: 'Cancel requested. Waiting for final terminal status...',
        timeout: 'Payment timed out while waiting for the terminal. Check the terminal before retrying.',
        error: 'Unable to contact the payment service. Please try again.'
    }, config.strings || {});

    function panel() {
        return $('#patwc-payment-panel');
    }

    function startButton() {
        return $('#patwc-start-payment');
    }

    function cancelButton() {
        return $('#patwc-cancel-payment');
    }

    function statusRegion() {
        return $('#patwc-payment-status');
    }

    function logContainer() {
        return $('#patwc-payment-log');
    }

    function orderId() {
        return config.orderId || panel().data('patwc-order-id') || '';
    }

    function setStatus(message) {
        statusRegion().text(message);
        appendLog(message);
    }

    function appendLog(message) {
        var $log = logContainer();

        if (!$log.length || !message) {
            return;
        }

        $('<div/>', {
            'class': 'patwc-payment-log__entry',
            text: message
        }).appendTo($log);
    }

    function ajaxData(action) {
        return {
            action: action,
            order_id: orderId(),
            order_token: config.orderToken || '',
            _ajax_nonce: config.nonce || ''
        };
    }

    function request(action) {
        return $.ajax({
            url: config.ajaxUrl || window.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: ajaxData(action)
        }).then(function (response) {
            if (response && response.data && typeof response.data === 'object') {
                return response.data;
            }

            return response || {};
        });
    }

    function normalizeStatus(response) {
        return String((response && response.status) || '').toLowerCase();
    }

    function isSuccessStatus(status) {
        return $.inArray(status, ['success', 'approved', 'complete', 'completed', 'paid']) !== -1;
    }

    function isFailureStatus(status) {
        return $.inArray(status, ['decline', 'declined', 'failure', 'failed', 'error', 'timeout', 'canceled', 'cancelled']) !== -1;
    }

    function clearPollTimer() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function submitOrderPayForm() {
        var $form = $('#order_review');

        if (!$form.length) {
            $form = $('form.woocommerce-checkout, form.checkout, form[name="checkout"]').first();
        }

        if (!$form.length) {
            $form = $('form').has('input[name="woocommerce_pay"], button[name="woocommerce_pay"], #place_order').first();
        }

        if ($form.length && $form[0] && typeof $form[0].submit === 'function') {
            $form.trigger('submit');
            return;
        }

        appendLog('Payment approved, but the order form could not be found. Please refresh the page.');
    }

    function finishAsRetry(message) {
        clearPollTimer();
        startButton().prop('disabled', false);
        cancelButton().prop('disabled', true).attr('hidden', 'hidden');
        setStatus(message || strings.retry);
    }

    function handleResponse(response) {
        var status = normalizeStatus(response);
        var message = response && response.message ? response.message : '';

        if (isSuccessStatus(status) || response.submit_form === true) {
            clearPollTimer();
            setStatus(message || strings.approved);
            submitOrderPayForm();
            return;
        }

        if (isFailureStatus(status) || response.retry_allowed === true) {
            finishAsRetry(message || strings.retry);
            return;
        }

        setStatus(message || strings.waiting);

        if (response.continue_polling === false && status !== '') {
            finishAsRetry(message || strings.retry);
        }
    }

    function schedulePoll() {
        clearPollTimer();

        pollTimer = window.setTimeout(function () {
            if (Date.now() - paymentStartedAt >= timeoutMs) {
                finishAsRetry(strings.timeout);
                return;
            }

            request('patwc_poll_payment')
                .done(function (response) {
                    handleResponse(response);

                    if (pollTimer !== null) {
                        schedulePoll();
                    }
                })
                .fail(function () {
                    finishAsRetry(strings.error);
                });
        }, pollInterval);
    }

    function startPayment() {
        clearPollTimer();
        paymentStartedAt = Date.now();
        startButton().prop('disabled', true);
        cancelButton().prop('disabled', false).removeAttr('hidden');
        setStatus(strings.starting);

        request('patwc_start_payment')
            .done(function (response) {
                handleResponse(response);

                if (pollTimer === null && !isSuccessStatus(normalizeStatus(response)) && !isFailureStatus(normalizeStatus(response))) {
                    schedulePoll();
                }
            })
            .fail(function () {
                finishAsRetry(strings.error);
            });
    }

    function cancelPayment() {
        cancelButton().prop('disabled', true);
        setStatus(strings.canceling);

        request('patwc_cancel_payment')
            .done(function (response) {
                handleResponse(response);

                if (pollTimer === null && !isSuccessStatus(normalizeStatus(response)) && !isFailureStatus(normalizeStatus(response))) {
                    schedulePoll();
                }
            })
            .fail(function () {
                cancelButton().prop('disabled', false);
                setStatus(strings.error);
            });
    }

    $(function () {
        startButton().on('click', function (event) {
            event.preventDefault();
            startPayment();
        });

        cancelButton().on('click', function (event) {
            event.preventDefault();
            cancelPayment();
        });
    });
})(jQuery);
