<?php

namespace Hipay\MiraklConnector\Api\Hipay\Model\Status;

use Hipay\MiraklConnector\Common\AbstractEnumeration;

/**
 * Class BankInfo.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class BankInfo extends AbstractEnumeration
{
    // Hipay Wallet bankInfosStatus response 'status' values (in en_GB)
    const BLANK = 'No bank information';
    const VALIDATED = 'Validated';
    const TO_VALIDATE = 'To validate';
    const VALIDATION_PROGRESS = 'Validation in progress';
}
