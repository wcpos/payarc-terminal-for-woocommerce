<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Tests\Conformance;

use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;

require_once __DIR__ . '/PayArc_Conformance_Fixture.php';

class Test_PayArc_Provider_Conformance extends Provider_Conformance_Test_Case
{
    protected function fixture(): Conformance_Fixture
    {
        return new PayArc_Conformance_Fixture();
    }
}
