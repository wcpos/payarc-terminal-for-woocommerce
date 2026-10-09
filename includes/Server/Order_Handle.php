<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

/** The one thing the old panel's attempt index needs of an order: its id. */
final class Order_Handle
{
    /** @var int */
    private $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function get_id(): int
    {
        return $this->id;
    }
}
