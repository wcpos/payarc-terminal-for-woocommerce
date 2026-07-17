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
        error: 'Unable to contact the payment service. Please try again.',
        notAuthorized: 'Payment is unavailable: this session is not authorized for this order. Refresh the page or reopen the order, then try again.',
        showLog: 'Show activity',
        hideLog: 'Hide activity'
    }, config.strings || {});
    var maxLogEntries = 200;

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

    function logTimestamp() {
        var now = new Date();

        function pad(value) {
            return (value < 10 ? '0' : '') + value;
        }

        return pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
    }

    function appendLog(message) {
        var $log = logContainer();

        if (!$log.length || !message) {
            return;
        }

        $('<div/>', {
            'class': 'patwc-payment-log__entry',
            text: '[' + logTimestamp() + '] ' + message
        }).appendTo($log);

        var $entries = $log.children();
        if ($entries.length > maxLogEntries) {
            $entries.slice(1, $entries.length - maxLogEntries + 1).remove();
        }

        if ($log[0]) {
            $log[0].scrollTop = $log[0].scrollHeight;
        }
    }

    function bodyMessage(body) {
        if (!body || typeof body !== 'object') {
            return '';
        }

        if (typeof body.message === 'string' && $.trim(body.message) !== '') {
            return $.trim(body.message);
        }

        if (body.data && typeof body.data === 'object' && typeof body.data.message === 'string') {
            return $.trim(body.data.message);
        }

        return '';
    }

    function failMessage(xhr) {
        var message = bodyMessage(xhr && xhr.responseJSON);

        if (!message && xhr && typeof xhr.responseText === 'string' && xhr.responseText !== '') {
            try {
                message = bodyMessage(JSON.parse(xhr.responseText));
            } catch (parseError) {
                message = '';
            }
        }

        if (!message) {
            message = strings.error;
        }

        if (xhr && xhr.status) {
            return message + ' (HTTP ' + xhr.status + ')';
        }

        return message;
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

        setStatus('Payment approved, but the order form could not be found. Please refresh the page.');
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
                .fail(function (xhr) {
                    finishAsRetry(failMessage(xhr));
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
            .fail(function (xhr) {
                finishAsRetry(failMessage(xhr));
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
            .fail(function (xhr) {
                cancelButton().prop('disabled', false);
                setStatus(failMessage(xhr));
            });
    }

    function toggleLog() {
        var $log = logContainer();
        var $toggle = $('#patwc-toggle-payment-log');
        var $clear = $('#patwc-clear-payment-log');
        var hidden = $log.is('[hidden]');

        if (hidden) {
            $log.removeAttr('hidden');
            $toggle.text(strings.hideLog).attr('aria-expanded', 'true');
            $clear.removeAttr('hidden');
            if ($log[0]) {
                $log[0].scrollTop = $log[0].scrollHeight;
            }
        } else {
            $log.attr('hidden', 'hidden');
            $toggle.text(strings.showLog).attr('aria-expanded', 'false');
            $clear.attr('hidden', 'hidden');
        }
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

        $(document).on('click', '#patwc-toggle-payment-log', function (event) {
            event.preventDefault();
            toggleLog();
        });

        $(document).on('click', '#patwc-clear-payment-log', function (event) {
            event.preventDefault();
            logContainer().empty();
        });

        if (config.authorized === false) {
            appendLog(strings.notAuthorized);
        }
    });
})(jQuery);
