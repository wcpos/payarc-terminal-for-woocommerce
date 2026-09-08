<?php

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

foreach (array('Settings', 'PaymentAttempt', 'Utils/Money', 'PaymentReconciler') as $class) {
    require_once dirname(__DIR__, 2) . '/includes/' . $class . '.php';
}

class PatwcPaymentMethodClaimOrder
{
    public $method = '';
    public $title = '';
    public $method_at_completion = null;
    public $setter_calls = 0;
    public $save_count = 0;

    public function get_payment_method(): string
    {
        return $this->method;
    }

    public function set_payment_method($method): void
    {
        $this->method = $method;
        $this->setter_calls++;
    }

    public function set_payment_method_title($title): void
    {
        $this->title = $title;
        $this->setter_calls++;
    }

    public function get_id(): int
    {
        return 1001;
    }

    public function get_total(): string
    {
        return '10.23';
    }

    public function get_currency(): string
    {
        return 'USD';
    }

    public function payment_complete($transaction_id = ''): void
    {
        $this->method_at_completion = $this->get_payment_method();
    }

    public function save(): void
    {
        $this->save_count++;
    }
}

function patwc_payment_method_claim_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// A late claim must precede payment_complete, where POS chooses the paid status.
$order = new PatwcPaymentMethodClaimOrder();
$order->method = 'pos_cash';
$reconciler = new PaymentReconciler(new Settings(array('title' => 'Counter terminal')));
$result = $reconciler->reconcile($order, array(
    'status' => 'SUCCESS',
    'chargeId' => 'charge-1001',
    'metadata' => array('order_id' => '1001'),
    'amount' => array('approved' => 1023, 'currency' => 'USD'),
), 'poll');
patwc_payment_method_claim_expect($result['status'] === 'success', 'Payment should reconcile successfully.');
patwc_payment_method_claim_expect($order->method_at_completion === Settings::GATEWAY_ID, 'Gateway must be claimed before payment_complete.');
patwc_payment_method_claim_expect($order->title === 'Counter terminal', 'Completion should use the configured gateway title.');

$order = new PatwcPaymentMethodClaimOrder();
PaymentAttempt::claim_order_gateway($order, (new Settings(array()))->title());
patwc_payment_method_claim_expect($order->method === Settings::GATEWAY_ID, 'An empty payment method should be claimed.');
patwc_payment_method_claim_expect($order->title === 'PayArc Terminal', 'Missing title setting should default to PayArc Terminal.');
patwc_payment_method_claim_expect($order->save_count === 0, 'Claiming must leave saving to the caller.');

$order->title = 'Custom order title';
$order->setter_calls = 0;
PaymentAttempt::claim_order_gateway($order, 'Replacement title');
patwc_payment_method_claim_expect($order->method === Settings::GATEWAY_ID, 'Our payment method should remain unchanged.');
patwc_payment_method_claim_expect($order->title === 'Custom order title', 'An existing custom title should be preserved.');
patwc_payment_method_claim_expect($order->setter_calls === 0, 'An already claimed order should not invoke either setter.');
