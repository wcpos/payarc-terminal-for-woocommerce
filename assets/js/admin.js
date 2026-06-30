(function () {
  'use strict';

  var gatewayId = 'payarc_terminal_for_woocommerce';
  var prefix = 'woocommerce_' + gatewayId + '_';

  function field(name) {
    return document.getElementById(prefix + name) || document.querySelector('[name="' + prefix + name + '"]');
  }

  function fieldValue(name) {
    var el = field(name);
    return el && typeof el.value === 'string' ? el.value.trim() : '';
  }

  function setResult(button, message, isError) {
    var resultId = button.getAttribute('data-result-id');
    var result = resultId ? document.getElementById(resultId) : null;
    if (!result) {
      return;
    }

    result.textContent = message;
    result.className = 'patwc-connection-result ' + (isError ? 'notice notice-error' : 'notice notice-success');
  }

  function updateTerminalSelect(terminals, defaultTerminalId) {
    var select = field('default_terminal_id');
    if (!select || !Array.isArray(terminals)) {
      return;
    }

    while (select.firstChild) {
      select.removeChild(select.firstChild);
    }

    if (terminals.length === 0) {
      var empty = document.createElement('option');
      empty.value = '';
      empty.textContent = 'No PayArc terminals discovered';
      select.appendChild(empty);
      return;
    }

    terminals.forEach(function (terminal) {
      if (!terminal || !terminal.terminal_id || !terminal.label) {
        return;
      }

      var option = document.createElement('option');
      option.value = terminal.terminal_id;
      option.textContent = terminal.label;
      if (terminal.terminal_id === defaultTerminalId) {
        option.selected = true;
      }
      select.appendChild(option);
    });
  }

  function connectionPayload(button) {
    var data = new FormData();
    var action = button.getAttribute('data-action') || 'patwc_connect_payarc';
    data.append('action', action);
    data.append('_ajax_nonce', button.getAttribute('data-nonce') || '');
    if (action === 'patwc_connect_payarc') {
      ['mode', 'connect_email', 'connect_mid', 'connect_client_secret', 'connect_secret_key', 'callback_bearer_token'].forEach(function (name) {
        var value = fieldValue(name);
        if (value !== '') {
          data.append(name, value);
        }
      });
    }
    return data;
  }

  function handleConnectionClick(event) {
    var button = event.currentTarget;
    var ajaxUrl = button.getAttribute('data-ajax-url') || 'admin-ajax.php';
    var action = button.getAttribute('data-action') || '';
    var busyText = action === 'patwc_refresh_payarc_terminals' ? 'Refreshing PayArc terminals...' : (action === 'patwc_disconnect_payarc' ? 'Disconnecting PayArc...' : 'Connecting to PayArc...');

    if (!window.fetch || !window.FormData) {
      setResult(button, 'This browser cannot run the PayArc connection request. Save settings and try from a modern browser.', true);
      return;
    }

    button.disabled = true;
    setResult(button, busyText, false);

    window.fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: connectionPayload(button)
    }).then(function (response) {
      return response.json();
    }).then(function (body) {
      if (!body || body.status === 'error') {
        setResult(button, body && body.message ? body.message : 'PayArc connection failed.', true);
        return;
      }

      updateTerminalSelect(body.terminals || [], body.default_terminal_id || '');
      var message = body.message || 'PayArc connection updated.';
      if (body.terminal_count !== undefined) {
        message += ' Terminals discovered: ' + body.terminal_count + '.';
      }
      if (body.warning) {
        message += ' ' + body.warning;
      }
      setResult(button, message, false);
    }).catch(function () {
      setResult(button, 'PayArc connection request failed. Check the browser console and WooCommerce logs.', true);
    }).finally(function () {
      button.disabled = false;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var webhookUrl = document.querySelector('[name="' + prefix + 'webhook_url"]');

    if (webhookUrl) {
      webhookUrl.setAttribute('readonly', 'readonly');
    }

    ['.patwc-connect-payarc', '.patwc-refresh-payarc-terminals', '.patwc-disconnect-payarc'].forEach(function (selector) {
      var buttons = document.querySelectorAll(selector);
      Array.prototype.forEach.call(buttons, function (button) {
        button.addEventListener('click', handleConnectionClick);
      });
    });
  });
}());
