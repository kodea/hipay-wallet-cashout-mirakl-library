<?php
/**
 * File InvalidOperation.php.
 *
 * @category
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
namespace Hipay\MiraklConnector\Exception;

use Exception;
use Hipay\MiraklConnector\Cashout\Model\Operation\OperationInterface;

/**
 * Class InvalidOperation.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class InvalidOperationException extends DispatchableException
{
    /**
     * @var OperationInterface
     */
    protected $operation;

    /**
     * InvalidOperation constructor.
     *
     * @param OperationInterface $operation
     * @param string             $message
     * @param int                $code
     * @param Exception          $previous
     */
    public function __construct(
        $operation,
        $message = '',
        $code = 0,
        Exception $previous = null
    ) {
        $this->operation = $operation;
        parent::__construct(
            $message ?: "An operation for {$operation->getMiraklId()} is already
            created for the cycle {$operation->getCycleDate()}",
            $code,
            $previous
        );
    }

    /**
     * @return OperationInterface
     */
    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * @return string
     */
    public function getEventName()
    {
        return 'invalid.operation';
    }
}
