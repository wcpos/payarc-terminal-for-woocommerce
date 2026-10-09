<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\PayArcTerminal\Server\PayArc_Server_Provider;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

/**
 * The real adapter with each operation recorded on the fixture's transcript: the lessons count what
 * Pro asked the adapter to do, not the wire messages behind it. Registered in place of
 * PayArc_Server_Provider; the scripted PayArc sits behind `pre_http_request`.
 */
final class Recording_PayArc_Provider extends PayArc_Server_Provider
{
    /** @var PayArc_Conformance_Fixture|null */
    public static $fixture;

    /**
     * Recorded once the call has been made: the action a create names is the sale PayArc made for the
     * row's idempotency key (the fake knows it even when the response was lost). A refused create names none.
     */
    public function create_reader_action(array $row, string $reader_id)
    {
        $result = parent::create_reader_action($row, $reader_id);
        $trace = self::$fixture->transport->sale_for_key((string) $row['id']);
        self::$fixture->record('create', $trace === null ? '' : $trace, 'amount=' . $row['amount'] . ' currency=' . $row['currency'] . ' reader=' . $reader_id . ' mode=' . self::mode());

        return $result;
    }

    public function fetch(string $ref)
    {
        self::$fixture->record('fetch', $ref, 'mode=' . self::mode());
        self::$fixture->transport->advance($ref);

        return parent::fetch($ref);
    }

    public function cancel(string $ref)
    {
        self::$fixture->record('cancel', $ref, 'mode=' . self::mode());

        return parent::cancel($ref);
    }

    public function refund(array $row, int $refund_id, string $amount)
    {
        $action = (string) ($row['provider_refs']['action'] ?? '');
        $reference = (string) ($row['provider_refs']['transaction_id'] ?? '');
        self::$fixture->record('refund', $action !== '' ? $action : $reference, 'amount=' . $amount . ' currency=' . $row['currency'] . ' mode=' . self::mode() . ' transaction_id=' . self::$fixture->alias($reference, 'charge'));

        return parent::refund($row, $refund_id, $amount);
    }

    public function verify_webhook(\WP_REST_Request $request)
    {
        $body = json_decode((string) $request->get_body(), true);
        self::$fixture->record('webhook', (string) ($body['traceId'] ?? ''), 'event=callback status=' . (string) ($body['status'] ?? ''));

        return parent::verify_webhook($request);
    }

    private static function mode(): string
    {
        return (new Settings())->mode() === 'production' ? 'live' : 'test';
    }
}
