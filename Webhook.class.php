<?php
/**
 * This file contains the IPN processor for Paylike.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2020 Lee Garner
 * @package     shop
 * @version     v1.3.0
 * @since       v1.3.0
 * @license     http://opensource.org/licenses/gpl-2.0.php 
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\paylike;
use Shop\Config;
use Shop\Order;
use Shop\Payment;
use Shop\Models\OrderState;


/**
 * Paylike IPN Processor.
 * @package shop
 */
class Webhook extends \Shop\Webhook
{
    /** Transaction data holder.
     * @var object */
    private $txn = NULL;


    /**
     * Constructor.
     * Most of the variables for this IPN come from the transaction,
     * which is retrieved in Verify().
     *
     * @param   array   $A  Array of IPN data
     */
    public function __construct($blob='')
    {
        $this->setSource('paylike');
        $this->setData($_GET);
        $this->blob = json_encode($this->getData());
        $this->setHeaders(NULL);
        $this->setTimestamp();
        $this->GW = \Shop\Gateway::getInstance($this->getSource());

        $this->setId(SHOP_getVar($this->getData(), 'txn_id'));
        $this->setOrderId(SHOP_getVar($this->getData(), 'order_id'));
        $this->gw_name = $this->GW->getDscp();
        $this->setEvent('authorized');
    }


    /**
     * Verify that the message is valid and can be processed.
     * Checks key elements of the order and its status.
     *
     * @return  boolean     True if valid, False if not
     */
    public function Verify()
    {
        if (empty($this->getId())) {
            SHOP_log("{$this->gw_name} Webhook: txn_id is empty", SHOP_LOG_ERROR);
            return false;
        }

        if (
            !isset($this->getData()['shop_test_ipn']) &&
            !$this->isUniqueTxnId()
        ) {
            return false;
        }

        try {
            $this->txn = $this->GW->getTransaction($this->getId());
        } catch (Exception $e) {
            // catch errors thrown by Paylike API for invalid requests
            SHOP_log("{$this->gw_name} Webhook transaction ID {$this->getId()} is invalid", SHOP_LOG_ERROR);
            return false;
        }

        $this->Order = Order::getInstance($this->getOrderId());
        if (!$this->Order) {
            SHOP_log("Order ID $order invalid during {$this->gw_name} verification.", SHOP_LOG_ERROR);
            return false;
        }

        // Payment amount in the transaction is integer
        $this->setPayment(SHOP_getVar($this->txn, 'amount', 'float') / 100);
        if ($this->Order->getBalanceDue() > $this->getPayment()) {
            SHOP_log("{$this->gw_name} txn {$this->getId()} amt {$this->getPayment()} is insufficient for order {$this->Order->getOrderId()}", SHOP_LOG_ERROR);
            return false;
        }

        return true;
    }


    /**
     * Process the transaction.
     * Verifies that the transaction is valid, then records the purchase and
     * notifies the buyer and administrator
     *
     * @uses    self::Verify()
     * @uses    BaseIPN::isUniqueTxnId()
     * @uses    BaseIPN::handlePurchase()
     */
    public function Dispatch()
    {
        global $LANG_SHOP;

        $LogID = $this->logIPN();
        $this->blob = json_encode($this->txn);
        switch ($this->getEvent()) {
        case 'authorized':
            $status = $this->Capture();
            if ($status) {
                $Pmt = Payment::getByReference($this->getID());
                if ($Pmt->getPmtID() == 0) {
                    $Pmt->setRefID($this->getID())
                        ->setAmount($this->getPayment())
                        ->setGateway($this->getSource())
                        ->setMethod($this->getSource())
                        ->setComment('Webhook ' . $this->getID())
                        ->setOrderID($this->getOrderID())
                        ->Save();
                    if ($this->handlePurchase($this->Order)) {
                        COM_refresh(Config::get('url') . '?thanks=' . $this->gw_name);
                    }
                }
            }
            COM_setMsg($LANG_SHOP['pmt_error']);
            COM_refresh(Config::get('url') . '/index.php');
            break;

        default:
            return false;
            break;
        }
        return true;
    }


    /**
     * Capture the payment for the authorized transaction.
     *
     * @return  boolean     True on success, False on error.
     */
    private function Capture()
    {
        // amounts in the txn array are integer (amunt * 100)
        $amt_pending = SHOP_getVar($this->txn, 'pendingAmount', 'integer');
        $curr_code = SHOP_getVar($this->txn, 'currency');

        try {
            $status = $this->GW->captureTransaction($this->getId(), array(
                'amount'   => $amt_pending,
                'currency' => $curr_code,
            ) );
        } catch (Exception $e) {
            // catch errors thrown by Paylike API for invalid requests
            $status = false;
            SHOP_log("{$this->gw_name} Capture transaction ID {$this->getTxnId()} failed", SHOP_LOG_ERROR);
        }
        return $status;
    }

}
