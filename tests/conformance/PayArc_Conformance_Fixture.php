<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\PayArcTerminal\Gateway;
use WCPOS\WooCommercePOS\PayArcTerminal\Server\PayArc_Server_Provider;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;

require_once __DIR__ . '/Fake_PayArc_Transport.php';
require_once __DIR__ . '/Recording_PayArc_Provider.php';

/**
 * Capabilities claimed beyond the floor, and why:
 * - `cancel`, `cancel_final`, `cancel_requested_then_completed`: a cancel is accepted while the sale waits
 *   for a card (the read then shows ABORTED, final) and refused once the card is processing; the adapter
 *   then reports `requested` and polling decides.
 * - `webhook`: the callback is authenticated (the plugin's own token in the callbackURL, or PayArc's
 *   bearer token), and the authenticated read is the evidence; failures are reported as such.
 * - `refund`, `partial_refund`: a refund is a terminal command answered with a traceId; the quick read
 *   after it may already know the outcome, else `pending` is a real state.
 * - `expiry`: PayArc ends a sale no card was presented for (TIMEOUT).
 * - `legacy_adoption`, `historical_webview_refund`: the callback resolves an adopted sale through Pro's
 *   record, and a refund reads the sale back by the traceId Free kept as the transaction reference.
 * Not claimed: `cancel_unsupported`, `manual_capture` (AUTH/POSTAUTH exist but are not used),
 * `prompt`, `test_live_isolation` (one set of credentials; a reference carries no mode),
 * `non_idempotent_create` (sales replay under X-Idempotency-Key), `webhook_money_only`,
 * `refund_synchronous`.
 */
final class PayArc_Conformance_Fixture implements Conformance_Fixture
{
    /** @var Fake_PayArc_Transport */
    public $transport;
    private $registry_property;
    private $old_registry;
    private $old_gateways;
    private $old_options;
    private $old_currency;
    private $calls = array();
    private $aliases = array();

    public function gateway_id(): string
    {
        return Settings::GATEWAY_ID;
    }

    public function install(): void
    {
        $this->old_options = get_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', array());
        $this->old_currency = get_option('woocommerce_currency');
        update_option('woocommerce_currency', 'USD');
        update_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', array(
            'mode' => 'test',
            'connect_mid' => '123456789012',
            'connect_secret_key' => Fake_PayArc_Transport::SECRET,
            'v3_auth_credential' => 'secret_key',
            'default_terminal_id' => '',
            'terminal_registry' => array(
                array('terminal_id' => '1234567890', 'label' => 'Front counter', 'enabled' => true),
                array('terminal_id' => '0987654321', 'label' => 'Back office', 'enabled' => true),
            ),
            'callback_url_token' => 'cbtoken-conformance',
            'callback_bearer_token' => 'callback-conformance',
            'tender_type' => 'CREDIT',
            'print_receipt' => '0',
        ));
        $this->transport = new Fake_PayArc_Transport();
        Recording_PayArc_Provider::$fixture = $this;
        add_filter('pre_http_request', array($this->transport, 'handle'), 10, 3);
        $this->registry_property = new \ReflectionProperty(Server_Providers::class, 'instance');
        $this->registry_property->setAccessible(true);
        $this->old_registry = $this->registry_property->getValue();
        $this->registry_property->setValue(null, null);
        wcpos_pro_register_server_provider(Settings::GATEWAY_ID, Recording_PayArc_Provider::class);
        $this->old_gateways = WC()->payment_gateways;
        add_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        WC()->payment_gateways = new \WC_Payment_Gateways();
        Reader_Curation::forget(Settings::GATEWAY_ID);
        delete_option('wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID);
    }

    public function uninstall(): void
    {
        remove_filter('pre_http_request', array($this->transport, 'handle'), 10);
        remove_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        Recording_PayArc_Provider::$fixture = null;
        Reader_Curation::forget(Settings::GATEWAY_ID);
        delete_option('wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID);
        $this->registry_property->setValue(null, $this->old_registry);
        WC()->payment_gateways = $this->old_gateways;
        update_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', $this->old_options);
        update_option('woocommerce_currency', $this->old_currency);
    }

    /**
     * @param array<int, mixed> $gateways
     * @return array<int, mixed>
     */
    public function register_gateway(array $gateways): array
    {
        $gateways[] = Gateway::class;

        return $gateways;
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, array('cancel', 'cancel_final', 'cancel_requested_then_completed', 'webhook', 'refund', 'partial_refund', 'expiry', 'legacy_adoption', 'historical_webview_refund'), true);
    }

    public function script(string $scenario): void
    {
        // Scenario => states successive fetches observe, the refund's terminal outcome, cancel behaviour.
        $scripts = array(
            'create_ok' => array(array('created')),
            'create_indeterminate' => array(array('created')),
            'webhook_replay' => array(array('created')),
            'webhook_out_of_order' => array(array('created')),
            'pending_then_completed' => array(array('processing', 'APPROVED')),
            'declined' => array(array('DECLINE')),
            'cancel_requested_then_cancelled' => array(array('ABORTED'), 'APPROVED', 'refused'),
            'cancel_requested_then_completed' => array(array('APPROVED'), 'APPROVED', 'refused'),
            'cancel_final' => array(array('created'), 'APPROVED', 'accepted'),
            'amount_mismatch' => array(array('APPROVED:short')),
            'currency_mismatch' => array(array('APPROVED:eur')),
            'expired' => array(array('TIMEOUT')),
            'refund_ok' => array(array('APPROVED'), 'APPROVED'),
            'refund_pending' => array(array('APPROVED'), 'processing'),
            'refund_failed' => array(array('APPROVED'), 'DECLINE'),
        );
        if (!isset($scripts[$scenario])) {
            throw new \OutOfBoundsException('Unknown conformance scenario: ' . $scenario);
        }
        $this->transport->script($scenario, ...$scripts[$scenario]);
    }

    public function webhook_request(string $event): \WP_REST_Request
    {
        $tampered = $event === 'tampered';
        $event = $tampered ? 'completed' : $event;
        $states = array('completed' => 'APPROVED', 'failed' => 'DECLINE', 'cancelled' => 'ABORTED');
        if (!isset($states[$event])) {
            throw new \OutOfBoundsException('Unknown webhook event: ' . $event);
        }
        $trace = (string) $this->transport->current;
        $this->transport->observe($trace, $states[$event]);
        $request = new \WP_REST_Request('POST', Payments_Webhook_Controller::ROUTE);
        // PayArc posts to the callbackURL the sale named: Pro's route with the plugin's token in the query
        // (a tampered delivery carries neither the token nor PayArc's bearer).
        $request->set_query_params(array('provider' => PayArc_Server_Provider::PROVIDER, 'patwc_cb' => $tampered ? 'wrong-token' : 'cbtoken-conformance'));
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Authorization', 'Bearer ' . ($tampered ? 'wrong-bearer' : 'callback-conformance'));
        $request->set_body($this->transport->callback_body($trace));

        return $request;
    }

    /** One stable alias per PayArc id; no id (a refused create) is `none`. */
    public function alias(string $ref, string $kind = 'action'): string
    {
        if ($ref === '') {
            return 'none';
        }
        if (!isset($this->aliases[$ref])) {
            $this->aliases[$ref] = $kind . '_' . (count($this->aliases) + 1);
        }

        return $this->aliases[$ref];
    }

    public function record(string $op, string $ref, string $details): void
    {
        $this->calls[] = array('op' => $op, 'request' => 'action=' . $this->alias($ref) . ' ' . $details);
    }

    public function transcript(): array
    {
        return $this->calls;
    }

    public function reset_transcript(): void
    {
        $this->calls = array();
        $this->aliases = array();
    }

    public function transcript_dir(): ?string
    {
        return __DIR__ . '/transcripts';
    }
}
