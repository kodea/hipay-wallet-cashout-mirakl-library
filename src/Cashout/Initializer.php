<?php

namespace HiPay\Wallet\Mirakl\Cashout;

use DateTime;
use Exception;
use HiPay\Wallet\Mirakl\Api\Factory;
use HiPay\Wallet\Mirakl\Cashout\Model\Operation\ManagerInterface as OperationManager;
use HiPay\Wallet\Mirakl\Cashout\Model\Operation\OperationInterface;
use HiPay\Wallet\Mirakl\Cashout\Model\Operation\Status;
use HiPay\Wallet\Mirakl\Cashout\Model\Transaction\ValidatorInterface;
use HiPay\Wallet\Mirakl\Common\AbstractApiProcessor;
use HiPay\Wallet\Mirakl\Exception\AlreadyCreatedOperationException;
use HiPay\Wallet\Mirakl\Exception\InvalidOperationException;
use HiPay\Wallet\Mirakl\Exception\NotEnoughFunds;
use HiPay\Wallet\Mirakl\Exception\TransactionException;
use HiPay\Wallet\Mirakl\Exception\ValidationFailedException;
use HiPay\Wallet\Mirakl\Service\Validation\ModelValidator;
use HiPay\Wallet\Mirakl\Vendor\Model\VendorManagerInterface as VendorManager;
use HiPay\Wallet\Mirakl\Notification\Model\LogOperationsManagerInterface as LogOperationsManager;
use HiPay\Wallet\Mirakl\Vendor\Model\VendorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use HiPay\Wallet\Mirakl\Notification\FormatNotification;
use HiPay\Wallet\Mirakl\Cashout\Event\OperationEvent;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\Transfer;
use HiPay\Wallet\Mirakl\Exception\WalletNotFoundException;

/**
 * Generate and save the operation to be executed by the processor.
 *
 * @author    HiPay <support.wallet@hipay.com>
 * @copyright 2017 HiPay
 */
class Initializer extends AbstractApiProcessor
{
    const SCALE = 2;

    /** @var VendorInterface */
    protected $operator;

    /** @var VendorInterface */
    protected $technicalAccount;

    /** @var  ValidatorInterface */
    protected $transactionValidator;

    /** @var OperationManager */
    protected $operationManager;

    /** @var  VendorManager */
    protected $vendorManager;

    /** @var  LogOperationsManager */
    protected $logOperationsManager;

    /**
     * @var FormatNotification class
     */
    protected $formatNotification;
    protected $operationsLogs;

    /**
     * Initializer constructor.
     *
     * @param EventDispatcherInterface $dispatcher
     * @param LoggerInterface $logger
     * @param Factory $factory
     * @param VendorInterface $operatorAccount
     * @param VendorInterface $technicalAccount
     * @param ValidatorInterface $transactionValidator
     * @param OperationManager $operationHandler
     * @param VendorManager $vendorManager
     * @throws ValidationFailedException
     */
    public function __construct(
    EventDispatcherInterface $dispatcher, LoggerInterface $logger, Factory $factory, VendorInterface $operatorAccount,
    VendorInterface $technicalAccount, ValidatorInterface $transactionValidator, OperationManager $operationHandler,
    LogOperationsManager $logOperationsManager, VendorManager $vendorManager
    )
    {
        parent::__construct($dispatcher, $logger, $factory);

        ModelValidator::validate($operatorAccount, 'Operator');
        $this->operator = $operatorAccount;

        ModelValidator::validate($technicalAccount, 'Operator');
        $this->technicalAccount = $technicalAccount;

        $this->operationManager = $operationHandler;

        $this->transactionValidator = $transactionValidator;

        $this->vendorManager = $vendorManager;

        $this->formatNotification = new FormatNotification();

        $this->logOperationsManager = $logOperationsManager;

        $this->operationsLogs = array();
    }

    /**
     * Main processing function
     * Generate and save operations.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @param DateTime $cycleDate
     * @param string $transactionFilterRegex
     *
     * @throws Exception
     *
     * @codeCoverageIgnore
     */
    public function process(
    DateTime $startDate, DateTime $endDate, DateTime $cycleDate, $transactionFilterRegex = null
    )
    {
        $this->logger->info('Control Mirakl Settings', array('miraklId' => null, "action" => "Operations creation"));
        // control mirakl settings
        $boolControl = $this->getControlMiraklSettings($this->documentTypes);
        if ($boolControl === false) {
            // log critical
            $title   = $this->criticalMessageMiraklSettings;
            $message = $this->formatNotification->formatMessage($title);
            $this->logger->critical($message, array('miraklId' => null, "action" => "Operations creation"));
        } else {
            $this->logger->info('Control Mirakl Settings OK',
                                array('miraklId' => null, "action" => "Operations creation"));
        }

        $this->logger->info('Cashout Initializer', array('miraklId' => null, "action" => "Operations creation"));

        //Fetch 'PAYMENT' transaction
        $this->logger->info(
            'Fetch payment transaction from Mirakl from '.
            $startDate->format('Y-m-d H:i').
            ' to '.
            $endDate->format('Y-m-d H:i')
            , array('miraklId' => null, "action" => "Operations creation")
        );

        $paymentTransactions = $this->getPaymentTransactions(
            $startDate, $endDate
        );

        $paymentDebits = $this->extractPaymentAmounts($paymentTransactions);

        $transactionError = null;
        $operations       = array();

        $this->logger->info('[OK] Fetched '.count($paymentTransactions).' payment transactions',
                                                  array('miraklId' => null, "action" => "Operations creation"));

        //Compute amounts (vendor and operator) by payment vouchers
        $this->logger->info('Compute amounts and create vendor operation',
                            array('miraklId' => null, "action" => "Operations creation"));

        foreach ($paymentDebits as $paymentVoucher => $debitedAmounts) {
            $voucherOperations = $this->handlePaymentVoucher($paymentVoucher, $debitedAmounts, $cycleDate,
                                                             $transactionFilterRegex);
            if (is_array($voucherOperations)) {
                $operations = array_merge($voucherOperations, $operations);
            } else {
                $transactionError[] = $paymentVoucher;
            }
        }

        if ($transactionError) {
            foreach ($transactionError as $voucher) {
                // log error
                $title   = "The transaction for the payment voucher number $voucher are wrong";
                $message = $this->formatNotification->formatMessage($title);
                $this->logger->error($message, array('miraklId' => null, "action" => "Operations creation"));
            }
            return;
        }

        $totalAmount = $this->sumOperationAmounts($operations);
        $this->logger->debug("Total amount ".$totalAmount, array('miraklId' => null, "action" => "Operations creation"));

        $this->logger->info(
            "Check if technical account has sufficient funds",
            array('miraklId' => null, "action" => "Operations creation")
        );
        if (!$this->hasSufficientFunds($totalAmount)) {
            $this->handleException(new NotEnoughFunds());
            return;
        }
        $this->logger->info('[OK] Technical account has sufficient funds',
                            array('miraklId' => null, "action" => "Operations creation"));

        $this->saveOperations($operations);
    }

    /**
     * Fetch from mirakl the payments transaction.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     *
     * @return array
     */
    public function getPaymentTransactions(
    DateTime $startDate, DateTime $endDate
    )
    {
        $transactions = $this->mirakl->getTransactions(
            null, $startDate, $endDate, null, null, null, null, null, array('PAYMENT')
        );

        return $transactions;
    }

    /**
     * Tells if at least one transaction's transactionNumber parameter matches the filter regex.
     *
     * @param array $transactions
     * @param string $transactionFilterRegex
     * @return bool
     */
    protected function transactionsMatchFilterRegex(array $transactions, $transactionFilterRegex = null)
    {
        // No regex configured, they all match
        if ($transactionFilterRegex == null) {
            return true;
        } else {
            return array_reduce($transactions,
                                function ($bool, $transaction) use ($transactionFilterRegex) {
                return $bool || preg_match($transactionFilterRegex, $transaction['transaction_number']);
            }, false);
        }
    }

    /**
     * Create the operations for a payment voucher
     *
     * @param $paymentVoucher
     * @param $debitedAmountsByShop
     * @param $cycleDate
     * @param $transactionFilterRegex
     * @return bool|array
     */
    public function handlePaymentVoucher($paymentVoucher, $debitedAmountsByShop, $cycleDate,
                                         $transactionFilterRegex = null)
    {
        $operatorAmount   = 0;
        $transactionError = false;
        $this->logger->debug(
            "Payment Voucher : $paymentVoucher",
            array('miraklId' => null, "action" => "Operations creation", 'paymentVoucherNumber' => $paymentVoucher)
        );

        $this->logger->debug(
            "Transaction filter regex: ".var_export($transactionFilterRegex, true),
                                                    array('miraklId' => null, "action" => "Operations creation")
        );

        $orderTransactions = array();
        $operations        = array();
        foreach ($debitedAmountsByShop as $shopId => $debitedAmount) {
            try {
                $this->logger->debug(
                    "ShopId : $shopId", array('miraklId' => $shopId, "action" => "Operations creation")
                );

                //Fetch the corresponding order transactions
                $orderTransactions = $this->getOrderTransactions(
                    $shopId, $paymentVoucher
                );

                $shouldIncludeShop = $this->transactionsMatchFilterRegex($orderTransactions, $transactionFilterRegex);

                if ($shouldIncludeShop) {
                    //Compute the vendor amount for this payment voucher
                    $vendorAmount = $this->computeVendorAmount(
                        $orderTransactions, $debitedAmount
                    );

                    $this->logger->debug("Vendor amount ".$vendorAmount,
                                         array('miraklId' => $shopId, "action" => "Operations creation"));

                    if ($vendorAmount > 0) {
                        //Create the vendor operation
                        $operation = $this->createOperation($vendorAmount, $cycleDate, $paymentVoucher, $shopId);
                        if ($operation !== null) {
                            $operations[] = $operation;
                        }
                    }

                    //Compute the operator amount for this payment voucher
                    $operatorAmount += round($this->computeOperatorAmountByVendor($orderTransactions), static::SCALE);
                } else {
                    $this->logger->debug(
                        "Skipped shop because no transaction for this shop matched the transaction filter regex.",
                        array('miraklId' => $shopId, "action" => "Operations creation")
                    );
                }
            } catch (Exception $e) {
                $transactionError = true;
                /** @var Exception $transactionError */
                $this->handleException(
                    new TransactionException(
                    $orderTransactions, $e->getMessage(), $e->getCode(), $e
                    )
                );
            }
        };

        $this->logger->debug("Operator amount ".$operatorAmount,
                             array('miraklId' => null, "action" => "Operations creation"));

        if ($operatorAmount > 0) {
            // Create operator operation
            $operation = $this->createOperation($operatorAmount, $cycleDate, $paymentVoucher);
            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        return $transactionError ? false : $operations;
    }

    /**
     * Fetch from mirakl the payment related to the orders.
     *
     * @param int $shopId
     * @param string $paymentVoucher
     *
     * @return array
     */
    public function getOrderTransactions($shopId, $paymentVoucher)
    {
        $transactions = $this->mirakl->getTransactions(
            $shopId, null, null, null, null, null, $paymentVoucher, null, $this->getOrderTransactionTypes()
        );

        return $transactions;
    }

    /**
     * Returns the transaction types to get on the second call from to TL01.
     *
     * @return array
     */
    public function getOrderTransactionTypes()
    {
        return array(
            'COMMISSION_FEE',
            'COMMISSION_VAT',
            'REFUND_COMMISSION_FEE',
            'REFUND_COMMISSION_VAT',
            'SUBSCRIPTION_FEE',
            'SUBSCRIPTION_VAT',
            'ORDER_AMOUNT',
            'ORDER_SHIPPING_AMOUNT',
            'REFUND_ORDER_SHIPPING_AMOUNT',
            'REFUND_ORDER_AMOUNT',
            'MANUAL_CREDIT',
            'MANUAL_CREDIT_VAT',
            'MANUAL_INVOICE',
            'MANUAL_INVOICE_VAT',
        );
    }

    /**
     * Compute the vendor amount to withdrawn from the technical account.
     *
     * @param array $transactions
     * @param int $payedAmount
     *
     * @return string
     *
     * @throws Exception
     */
    protected function computeVendorAmount(
    $transactions, $payedAmount
    )
    {
        $amount = 0;
        $errors = false;
        foreach ($transactions as $transaction) {
            $amount += round($transaction['amount_credited'], static::SCALE) - round($transaction['amount_debited'],
                                                                                     static::SCALE);
            $errors |=!$this->transactionValidator->isValid($transaction);
        }
        if (round($amount, static::SCALE) != round($payedAmount, static::SCALE)) {
            throw new TransactionException(
            array($transactions),
            'There is a difference between the transactions'.
            PHP_EOL."$amount for the transactions".
            PHP_EOL."{$payedAmount} for the earlier payment transaction"
            );
        }
        if ($errors) {
            throw new TransactionException(
            array($transactions), 'There are errors in the transactions'
            );
        }
        return $amount;
    }

    /**
     * Create the vendor operation
     * dispatch <b>after.operation.create</b>.
     *
     * @param int $amount
     * @param DateTime $cycleDate
     * @param string $paymentVoucher
     * @param bool|int $miraklId false if it an operator operation
     *
     * @return OperationInterface|null
     */
    public function createOperation(
    $amount, DateTime $cycleDate, $paymentVoucher, $miraklId = null
    )
    {
        if ($amount <= 0) {
            $this->logger->notice("Operation wasn't created du to null amount",
                                  array('miraklId' => $miraklId, "action" => "Operations creation"));
            return null;
        }

        //Set hipay id
        $hipayId = null;
        if ($miraklId) {
            $vendor = $this->vendorManager->findByMiraklId($miraklId);
            if ($vendor) {
                $hipayId = $vendor->getHiPayId();
            }
        } else {
            $vendor  = $this->operator;
            $hipayId = $this->operator->getHiPayId();
        }

        if ($miraklId && $vendor == null) {
            $this->logger->notice("Operation wasn't created because vendor doesn't exit in database (verify HiPay process value in Mirakl BO)",
                                  array('miraklId' => $miraklId, "action" => "Operations creation"));
            return null;
        }

        //Call implementation function
        $operation = $this->operationManager->create($amount, $cycleDate, $paymentVoucher, $miraklId);

        $operation->setHiPayId($hipayId);

        //Sets mandatory values
        $operation->setMiraklId($miraklId);
        $operation->setStatus(new Status(Status::CREATED));
        $operation->setUpdatedAt(new DateTime());
        $operation->setAmount($amount);
        $operation->setCycleDate($cycleDate);
        $operation->setPaymentVoucher($paymentVoucher);

        $this->operationsLogs[] = $this->logOperationsManager->create($miraklId, $hipayId, $paymentVoucher, $amount,
                                                                      $this->hipay->getBalance($vendor));

        return $operation;
    }

    /**
     * Compute the amount due to the operator by vendor.
     *
     * @param $transactions
     *
     * @return float
     */
    protected function computeOperatorAmountByVendor($transactions)
    {
        $amount = 0;
        foreach ($transactions as $transaction) {
            if (in_array(
                    $transaction['transaction_type'], $this->getOperatorTransactionTypes()
                )) {
                $amount += round($transaction['amount_credited'], static::SCALE) - round($transaction['amount_debited'],
                                                                                         static::SCALE);
            }
        }

        return (-1) * round($amount, static::SCALE);
    }

    /**
     * Return the transaction type used to calculate the operator amount.
     *
     * @return array
     */
    public function getOperatorTransactionTypes()
    {
        return array(
            'COMMISSION_FEE',
            'COMMISSION_VAT',
            'REFUND_COMMISSION_FEE',
            'REFUND_COMMISSION_VAT',
            'SUBSCRIPTION_FEE',
            'SUBSCRIPTION_VAT',
        );
    }

    /**
     * Sum operations amounts
     *
     * @param OperationInterface[] $operations
     * @return mixed
     */
    protected function sumOperationAmounts(array $operations)
    {
        $scale = static::SCALE;
        return array_reduce($operations,
                            function ($carry, OperationInterface $item) use ($scale) {
            $carry = round($carry, $scale) + round($item->getAmount(), $scale);
            return $carry;
        }, 0);
    }

    /**
     * Check if technical account has sufficient funds.
     *
     * @param $amount
     *
     * @returns boolean
     */
    public function hasSufficientFunds($amount)
    {
        return round($this->hipay->getBalance($this->technicalAccount), static::SCALE) >= round($amount, static::SCALE);
    }

    /**
     * Save operations
     *
     * @param array $operations
     */
    public function saveOperations(array $operations)
    {
        if ($this->areOperationsValid($operations)) {
            $this->logger->info('[OK] Operations validated',
                                array('miraklId' => null, "action" => "Operations creation"));

            $this->logger->info('Save operations', array('miraklId' => null, "action" => "Operations creation"));
            $this->operationManager->saveAll($operations);
            $this->logOperationsManager->saveAll($this->operationsLogs);
            $this->logger->info('[OK] Operations saved', array('miraklId' => null, "action" => "Operations creation"));
        } else {
            // log error
            $title   = 'Some operation were wrong. Operations not saved';
            $message = $this->formatNotification->formatMessage($title);
            $this->logger->error($message, array('miraklId' => null, "action" => "Operations creation"));
        }

        $this->logger->info("Transfer Process", array('miraklId' => null, "action" => "Transfer process"));
        $this->transferOperations();
    }

    /**
     * Validate operations
     *
     * @param OperationInterface[] $operations
     *
     * @return bool
     */
    public function areOperationsValid(array $operations)
    {
        //Valid the operation and check if operation wasn't created before
        $this->logger->info('Validate the operations', array('miraklId' => null, "action" => "Operations creation"));

        $operationError = false;
        /** @var OperationInterface $operation */
        foreach ($operations as $operation) {
            $operationError = !$this->isOperationValid($operation) || $operationError;
        }

        return !$operationError;
    }

    /**
     * Validate an operation
     *
     * @param OperationInterface $operation
     *
     * @return bool
     */
    public function isOperationValid(OperationInterface $operation)
    {
        try {
            if ($this->operationManager
                    ->findByMiraklIdAndPaymentVoucherNumber(
                        $operation->getMiraklId(), $operation->getPaymentVoucher()
                    )
            ) {
                throw new AlreadyCreatedOperationException($operation);
            }

            if (!$this->operationManager->isValid($operation)) {
                throw new InvalidOperationException($operation);
            }

            ModelValidator::validate($operation);
        } catch (Exception $e) {
            $this->handleException($e);
            return false;
        }
        return true;
    }

    /**
     * @param $paymentTransactions
     * @return array
     */
    protected function extractPaymentAmounts($paymentTransactions)
    {
        $paymentDebits = array();
        foreach ($paymentTransactions as $transaction) {
            $paymentDebits[$transaction['payment_voucher_number']][$transaction['shop_id']] = $transaction['amount_debited'];
        }
        return $paymentDebits;
    }

    /**
     * Execute the operation needing transfer.
     */
    protected function transferOperations()
    {
        $this->logger->info("Transfer operations", array('miraklId' => null, "action" => "Transfer"));

        $toTransfer = $this->getTransferableOperations();

        $this->logger->info("Operation to transfer : ".count($toTransfer),
                                                             array('miraklId' => null, "action" => "Transfer"));

        /** @var OperationInterface $operation */
        foreach ($toTransfer as $operation) {
            try {
                $eventObject = new OperationEvent($operation);

                $this->dispatcher->dispatch('before.transfer', $eventObject);

                $transferId = $this->transfer($operation);

                $eventObject->setTransferId($transferId);
                $this->dispatcher->dispatch('after.transfer', $eventObject);

                $this->logger->info("[OK] Transfer operation ".$operation->getTransferId()." executed",
                                    array('miraklId' => $operation->getMiraklId(), "action" => "Transfer"));
            } catch (Exception $e) {
                $this->logger->info("[OK] Transfer operation failed",
                                    array('miraklId' => $operation->getMiraklId(), "action" => "Transfer"));
                $this->handleException($e, 'critical');
            }
        }
    }

    /**
     * Transfer money between the technical
     * wallet and the operator|seller wallet.
     *
     * @param OperationInterface $operation
     *
     * @return int
     *
     * @throws Exception
     */
    public function transfer(OperationInterface $operation)
    {
        try {
            $vendor = $this->getVendor($operation);

            if (!$vendor || $this->hipay->isAvailable($vendor->getEmail())) {
                throw new WalletNotFoundException($vendor);
            }

            $operation->setHiPayId($vendor->getHiPayId());

            $transfer = new Transfer(
                round($operation->getAmount(), self::SCALE), $vendor,
                      $this->operationManager->generatePrivateLabel($operation),
                                                                    $this->operationManager->generatePublicLabel($operation)
            );


            //Transfer
            $transferId = $this->hipay->transfer($transfer, $vendor);

            $operation->setStatus(new Status(Status::TRANSFER_SUCCESS));
            $operation->setTransferId($transferId);
            $operation->setUpdatedAt(new DateTime());
            $this->operationManager->save($operation);

            $this->logOperation(
                $operation->getMiraklId(), $operation->getPaymentVoucher(), Status::TRANSFER_SUCCESS, ""
            );

            return $transferId;
        } catch (Exception $e) {
            $operation->setStatus(new Status(Status::TRANSFER_FAILED));
            $operation->setUpdatedAt(new DateTime());
            $this->operationManager->save($operation);

            $this->logOperation(
                $operation->getMiraklId(), $operation->getPaymentVoucher(), Status::TRANSFER_FAILED, $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Fetch the operation to transfer from the storage
     * @return OperationInterface[]
     */
    protected function getTransferableOperations()
    {
        $previousDay = new DateTime('-1 day');
        //Transfer
        $toTransfer  = $this->operationManager->findByStatus(
            new Status(Status::CREATED)
        );
        $toTransfer  = array_merge(
            $toTransfer,
            $this->operationManager
                ->findByStatusAndBeforeUpdatedAt(
                    new Status(Status::TRANSFER_FAILED), $previousDay
                )
        );
        return $toTransfer;
    }

    /**
     * Return the right vendor for an operation
     *
     * @param OperationInterface $operation
     *
     * @return VendorInterface|null
     */
    protected function getVendor(OperationInterface $operation)
    {
        if ($operation->getMiraklId()) {
            return $this->vendorManager->findByMiraklId($operation->getMiraklId());
        }
        return $this->operator;
    }

    /**
     * Control if Mirakl Setting is ok with HiPay prerequisites
     */
    public function getControlMiraklSettings($docTypes)
    {
        $this->mirakl->controlMiraklSettings($docTypes);
    }

    /**
     * Log Operations
     * @param type $miraklId
     * @param type $paymentVoucherNumber
     * @param type $status
     * @param type $message
     */
    private function logOperation($miraklId, $paymentVoucherNumber, $status, $message)
    {
        $logOperation = $this->logOperationsManager->findByMiraklIdAndPaymentVoucherNumber($miraklId,
                                                                                           $paymentVoucherNumber);

        if ($logOperation == null) {
            $this->logger->warning(
                "Could not fnd existing log for this operations : paymentVoucherNumber = ".$paymentVoucherNumber,
                array("action" => "Operation process", "miraklId" => $miraklId)
            );
        } else {

            $logOperation->setStatusTransferts($status);

            $logOperation->setMessage($message);

            $this->logOperationsManager->save($logOperation);
        }
    }
}