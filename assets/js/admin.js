(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var webhookUrl = document.querySelector('[name="woocommerce_payarc_terminal_for_woocommerce_webhook_url"]');

    if (webhookUrl) {
      webhookUrl.setAttribute('readonly', 'readonly');
    }
  });
}());
