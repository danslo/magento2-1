<?php
/**
 * Copyright © 2017 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Checkout\Model\Session as CheckoutSession;
use Mollie\Payment\Helper\General as MollieHelper;

class Mollie extends AbstractMethod
{

    protected $_isInitializeNeeded = true;
    protected $_isGateway = true;
    protected $_isOffline = false;
    protected $_canRefund = true;

    protected $issuers = [];
    protected $objectManager;
    protected $mollieHelper;
    protected $checkoutSession;
    protected $storeManager;
    protected $order;
    protected $scopeConfig;
    protected $orderSender;
    protected $invoiceSender;
    protected $orderRepository;
    protected $searchCriteriaBuilder;

    /**
     * Mollie constructor.
     *
     * @param Context                    $context
     * @param Registry                   $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory      $customAttributeFactory
     * @param Data                       $paymentData
     * @param ScopeConfigInterface       $scopeConfig
     * @param Logger                     $logger
     * @param MollieHelper               $mollieHelper
     * @param CheckoutSession            $checkoutSession
     * @param StoreManagerInterface      $storeManager
     * @param Order                      $order
     * @param OrderSender                $orderSender
     * @param InvoiceSender              $invoiceSender
     * @param OrderRepository            $orderRepository
     * @param SearchCriteriaBuilder      $searchCriteriaBuilder
     * @param AbstractResource|null      $resource
     * @param AbstractDb|null            $resourceCollection
     * @param array                      $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ObjectManagerInterface $objectManager,
        MollieHelper $mollieHelper,
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager,
        Order $order,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->objectManager = $objectManager;
        $this->mollieHelper = $mollieHelper;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->order = $order;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Extra checks for method availability
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     *
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {

        if ($quote == null) {
            $quote = $this->checkoutSession->getQuote();
        }

        if (!$this->mollieHelper->isAvailable($quote->getStoreId())) {
            return false;
        }

        if (!$this->mollieHelper->isCurrencyAllowed($quote->getBaseCurrencyCode())) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $order->setIsNotified(false);

        $status = $this->mollieHelper->getStatusPending($order->getId());
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_NEW);
        $stateObject->setStatus($status);
        $stateObject->setIsNotified(false);
    }

    /**
     * Start transaction
     *
     * @param Order $order
     *
     * @return bool
     */
    public function startTransaction(Order $order, $issuer = '')
    {
        $storeId = $order->getStoreId();
        $orderId = $order->getId();

        if (!$apiKey = $this->mollieHelper->getApiKey($storeId)) {
            return false;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $paymentData = [
            'amount'      => $order->getBaseGrandTotal(),
            'description' => $order->getIncrementId(),
            'redirectUrl' => $this->mollieHelper->getRedirectUrl($orderId),
            'webhookUrl'  => $this->mollieHelper->getWebhookUrl(),
            'method'      => $this->mollieHelper->getMethodCode($order),
            'issuer'      => $issuer,
            'metadata'    => [
                'order_id' => $orderId,
                'store_id' => $order->getStoreId()
            ]
        ];

        if ($billingAddress) {
            $billingData = [
                'billingAddress' => $billingAddress->getStreetLine(1),
                'billingCity'    => $billingAddress->getCity(),
                'billingRegion'  => $billingAddress->getRegion(),
                'billingPostal'  => $billingAddress->getPostcode(),
                'billingCountry' => $billingAddress->getCountryId()
            ];
            $paymentData = array_merge($paymentData, $billingData);
        }

        if ($shippingAddress) {
            $shippingData = [
                'shippingAddress' => $shippingAddress->getStreetLine(1),
                'shippingCity'    => $shippingAddress->getCity(),
                'shippingRegion'  => $shippingAddress->getRegion(),
                'shippingPostal'  => $shippingAddress->getPostcode(),
                'shippingCountry' => $shippingAddress->getCountryId()
            ];
            $paymentData = array_merge($paymentData, $shippingData);
        }

        $this->mollieHelper->addTolog('request', $paymentData);

        $payment = $mollieApi->payments->create($paymentData);
        $paymentUrl = $payment->getPaymentUrl();
        $transactionId = $payment->id;

        $message = __('Customer redirected to Mollie, url: %1', $paymentUrl);
        $status = $this->mollieHelper->getStatusPending($storeId);
        $order->addStatusToHistory($status, $message, false);
        $order->setMollieTransactionId($transactionId);
        $order->save();

        return $paymentUrl;
    }

    /**
     * Process Transaction (webhook / success)
     *
     * @param        $orderId
     * @param string $type
     *
     * @return array
     */
    public function processTransaction($orderId, $type = 'webhook')
    {
        $msg = '';

        $order = $this->order->load($orderId);
        if (empty($order)) {
            $msg = ['error' => true, 'msg' => __('Order not found')];
            $this->mollieHelper->addTolog('error', $msg);

            return $msg;
        }

        $storeId = $order->getStoreId();

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = ['error' => true, 'msg' => __('Transaction ID not found')];
            $this->mollieHelper->addTolog('error', $msg);

            return $msg;
        }

        $apiKey = $this->mollieHelper->getApiKey($storeId);
        if (empty($apiKey)) {
            $msg = ['error' => true, 'msg' => __('Api key not found')];
            $this->mollieHelper->addTolog('error', $msg);

            return $msg;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        $paymentData = $mollieApi->payments->get($transactionId);
        $this->mollieHelper->addTolog($type, $paymentData);

        if (($paymentData->isPaid() == true) && ($paymentData->isRefunded() == false)) {
            $amount = $paymentData->amount;
            $payment = $order->getPayment();

            if (!$payment->getIsTransactionClosed()) {
                $payment->setTransactionId($transactionId);
                $payment->setCurrencyCode('EUR');
                $payment->setIsTransactionClosed(true);
                $payment->registerCaptureNotification($amount, true);
                $order->save();

                $invoice = $payment->getCreatedInvoice();
                $status = $this->mollieHelper->getStatusProcessing($storeId);
                $sendInvoice = $this->mollieHelper->sendInvoice($storeId);

                if ($invoice && !$order->getEmailSent()) {
                    $this->orderSender->send($order);
                    $message = __('New order email sent');
                    $order->addStatusToHistory($status, $message, true)->save();
                }
                if ($invoice && !$invoice->getEmailSent() && $sendInvoice) {
                    $this->invoiceSender->send($invoice);
                    $message = __('Notified customer about invoice #%1', $invoice->getIncrementId());
                    $order->addStatusToHistory($status, $message, true)->save();
                }

                $msg = ['success' => true, 'status' => 'paid', 'order_id' => $orderId, 'type' => $type];
                $this->mollieHelper->addTolog('success', $msg);
            }
        } elseif ($paymentData->isRefunded() == true) {
            $msg = ['success' => true, 'status' => 'refunded', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
        } elseif ($paymentData->isOpen() == true) {
            if ($paymentData->method == 'banktransfer' && !$order->getEmailSent()) {
                $this->orderSender->send($order);
                $message = __('New order email sent');
                $status = $this->mollieHelper->getStatusPendingBanktransfer($storeId);
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $order->addStatusToHistory($status, $message, true)->save();
            }
            $msg = ['success' => true, 'status' => 'open', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
        } elseif ($paymentData->isPending() == true) {
            $msg = ['success' => true, 'status' => 'pending', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
        } elseif (!$paymentData->isOpen()) {
            $this->cancelOrder($order);
            $msg = ['success' => false, 'status' => 'cancel', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
        }

        return $msg;
    }

    /**
     * Cancel order
     *
     * @param $order
     *
     * @return bool
     */
    protected function cancelOrder($order)
    {
        if ($order->getId() && $order->getState() != Order::STATE_CANCELED) {
            $comment = __("The order was canceled");
            $this->mollieHelper->addTolog('info', $order->getIncrementId() . ' ' . $comment);
            $order->registerCancellation($comment)->save();

            return true;
        }

        return false;
    }

    /**
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float                                $amount
     *
     * @return $this|array
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $storeId = $order->getStoreId();

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = ['error' => true, 'msg' => __('Transaction ID not found')];
            $this->mollieHelper->addTolog('error', $msg);

            return $msg;
        }

        $apiKey = $this->mollieHelper->getApiKey($storeId);
        if (empty($apiKey)) {
            $msg = ['error' => true, 'msg' => __('Api key not found')];
            $this->mollieHelper->addTolog('error', $msg);

            return $msg;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        try {
            $payment = $mollieApi->payments->get($transactionId);
            $mollieApi->payments->refund($payment, $amount);
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
        }

        return $this;
    }

    /**
     * Get order by TransactionId
     *
     * @param $transactionId
     *
     * @return mixed
     */
    public function getOrderIdByTransactionId($transactionId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('mollie_transaction_id', $transactionId, 'eq')->create();
        $orderList = $this->orderRepository->getList($searchCriteria);
        $orderId = $orderList->getFirstItem()->getId();

        if ($orderId) {
            return $orderId;
        } else {
            $this->mollieHelper->addTolog('error', __('No order found for transaction id %1', $transactionId));

            return false;
        }
    }

    /**
     * Get list of iDeal Issuers from API
     *
     * @return array
     */
    public function getIssuers()
    {
        $issuers = [];
        $storeId = $this->storeManager->getStore()->getId();
        $apiKey = $this->mollieHelper->getApiKey($storeId);

        if (empty($apiKey)) {
            return false;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        try {
            foreach ($mollieApi->issuers->all() as $issuer) {
                $issuers[] = $issuer;
            }
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
        }

        return $issuers;
    }

    /**
     * @param $storeId
     *
     * @return bool
     */
    public function getPaymentMethods($storeId)
    {
        $apiKey = $this->mollieHelper->getApiKey($storeId);

        if (empty($apiKey)) {
            return false;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        return $mollieApi->methods->all();
    }

    /**
     * @param $apiKey
     *
     * @return mixed
     */
    public function loadMollieApi($apiKey)
    {
        $mollieApi = $this->objectManager->create('Mollie_API_Client');
        $mollieApi->setApiKey($apiKey);
        $mollieApi->addVersionString('Magento/' . $this->mollieHelper->getMagentoVersion());
        $mollieApi->addVersionString('MollieMagento2/' . $this->mollieHelper->getExtensionVersion());
        return $mollieApi;
    }
}
