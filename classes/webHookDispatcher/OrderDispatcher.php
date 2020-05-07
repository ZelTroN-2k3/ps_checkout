<?php
/**
 * 2007-2020 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\Module\PrestashopCheckout;

use PrestaShop\Module\PrestashopCheckout\Presenter\Date\DatePresenter;

class OrderDispatcher implements Dispatcher
{
    const PS_CHECKOUT_PAYMENT_REVERSED = 'PaymentCaptureReversed';
    const PS_CHECKOUT_PAYMENT_REFUNED = 'PaymentCaptureRefunded';
    const PS_CHECKOUT_PAYMENT_AUTH_VOIDED = 'PaymentAuthorizationVoided';
    const PS_CHECKOUT_PAYMENT_PENDING = 'PaymentCapturePending';
    const PS_CHECKOUT_PAYMENT_COMPLETED = 'PaymentCaptureCompleted';
    const PS_CHECKOUT_PAYMENT_DENIED = 'PaymentCaptureDenied';

    /**
     * Dispatch the Event Type to manage the merchant status
     *
     * {@inheritdoc}
     */
    public function dispatchEventType($payload)
    {
        $psOrderId = $this->getPrestashopOrderId($payload['orderId']);

        if (false === $psOrderId) {
            return false;
        }

//        if ($payload['eventType'] === self::PS_CHECKOUT_PAYMENT_REFUNED
//            || $payload['eventType'] === self::PS_CHECKOUT_PAYMENT_REVERSED) {
//            return $this->dispatchPaymentAction($payload['eventType'], $payload['resource'], $psOrderId);
//        }

        if ($payload['eventType'] === self::PS_CHECKOUT_PAYMENT_COMPLETED
            || $payload['eventType'] === self::PS_CHECKOUT_PAYMENT_DENIED
            || $payload['eventType'] === self::PS_CHECKOUT_PAYMENT_AUTH_VOIDED) {
            return $this->dispatchPaymentStatus($payload['eventType'], $payload['resource'], $psOrderId);
        }

        // For now, if pending, do not change anything
        if ($payload['eventType'] === self::PS_CHECKOUT_PAYMENT_PENDING) {
            return true;
        }

        return true;
    }

    /**
     * Check the PSL orderId value and transform it into a Prestashop OrderId
     *
     * @param string $orderId paypal order id
     *
     * @return bool|int
     */
    private function getPrestashopOrderId($orderId)
    {
        $orderError = (new WebHookValidation())->validateRefundOrderIdValue($orderId);

        if (!empty($orderError)) {
            throw new UnauthorizedException($orderError);
        }

        $psOrderId = (new \OrderMatrice())->getOrderPrestashopFromPaypal($orderId);

        if (!$psOrderId) {
            throw new UnprocessableException('order #' . $orderId . ' does not exist');
        }

        return $psOrderId;
    }

    /**
     * Dispatch the Event Type to the payments action Refunded or Revesed
     *
     * @param string $eventType
     * @param array $resource
     * @param int $orderId
     *
     * @return bool
     */
    private function dispatchPaymentAction($eventType, $resource, $orderId)
    {
        $orderError = (new WebHookValidation())->validateRefundResourceValues($resource);

        if (!empty($orderError)) {
            throw new UnauthorizedException($orderError);
        }

        $initiateBy = 'Merchant';

        if ($eventType === self::PS_CHECKOUT_PAYMENT_REVERSED) {
            $initiateBy = 'Paypal';
        }

        return (new WebHookOrder($initiateBy, $resource, $orderId))->updateOrder();
    }

    /**
     * Dispatch the event Type the the payment status PENDING / COMPLETED / DENIED / AUTH_VOIDED
     *
     * @param string $eventType
     * @param array $resource
     * @param int $orderId
     *
     * @return bool
     */
    private function dispatchPaymentStatus($eventType, $resource, $orderId)
    {
        $orderError = (new WebHookValidation())->validateRefundOrderIdValue($orderId);

        if (!empty($orderError)) {
            throw new UnauthorizedException($orderError);
        }

        $order = new \Order($orderId);
        $currentOrderStateId = (int) $order->getCurrentState();
        $newOrderStateId = (int) $this->getNewState($eventType, $resource, $currentOrderStateId);
        $orderPaymentCollection = $order->getOrderPaymentCollection();
        $orderPaymentCollection->where('amount', '=', $resource['amount']['value']);
        $shouldAddOrderPayment = true;

        /** @var \OrderPayment[] $orderPayments */
        $orderPayments = $orderPaymentCollection->getAll();
        foreach ($orderPayments as $orderPayment) {
            if (\Validate::isLoadedObject($orderPayment)) {
                $orderPayment->transaction_id = $resource['id'];
                $orderPayment->payment_method = $this->getPaymentMessageTranslation($resource);
                $orderPayment->save();
                $shouldAddOrderPayment = false;
            }
        }

        if (true === $shouldAddOrderPayment) {
            $order->addOrderPayment(
                $resource['amount']['value'],
                $this->getPaymentMessageTranslation($resource),
                $resource['id'],
                \Currency::getCurrencyInstance(\Currency::getIdByIsoCode($resource['amount']['currency_code'])),
                (new DatePresenter($resource['create_time'], 'Y-m-d H:i:s'))->present()
            );
        }

        // Prevent duplicate state entry
        if ($currentOrderStateId === $newOrderStateId
            || $order->hasBeenPaid()
            || $order->hasBeenShipped()
            || $order->hasBeenDelivered()
            || $order->isInPreparation()) {
            return true;
        }

        $orderHistory = new \OrderHistory();
        $orderHistory->id_order = $orderId;
        $orderHistory->changeIdOrderState(
            $newOrderStateId,
            $orderId
        );

        if (true !== $orderHistory->addWithemail()) {
            throw new UnauthorizedException('unable to change the order state');
        }

        return true;
    }

    /**
     * @param string $eventType
     * @param array $resource
     * @param int $currentOrderStateId
     *
     * @return int
     */
    private function getNewState($eventType, $resource, $currentOrderStateId)
    {
        if (static::PS_CHECKOUT_PAYMENT_AUTH_VOIDED === $eventType) {
            return (int) \Configuration::getGlobalValue('PS_OS_CANCELED');
        }

        if (static::PS_CHECKOUT_PAYMENT_COMPLETED === $eventType) {
            return $this->getPaidStatusId($currentOrderStateId);
        }

        if (static::PS_CHECKOUT_PAYMENT_DENIED === $eventType) {
            return (int) \Configuration::getGlobalValue('PS_OS_ERROR');
        }

        return $this->getPendingStatusId($resource);
    }

    /**
     * @param int $currentOrderStateId Current OrderState identifier
     *
     * @return int OrderState paid identifier
     */
    private function getPaidStatusId($currentOrderStateId)
    {
        if ($currentOrderStateId === (int) \Configuration::getGlobalValue('PS_OS_OUTOFSTOCK_UNPAID')) {
            return (int) \Configuration::getGlobalValue('PS_OS_OUTOFSTOCK_PAID');
        }

        return (int) \Configuration::getGlobalValue('PS_OS_PAYMENT');
    }

    /**
     * @param array $resource
     *
     * @return int OrderState identifier
     */
    private function getPendingStatusId($resource)
    {
        if (isset($resource['processor_response']['avs_code'])) {
            return (int) \Configuration::getGlobalValue('PS_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT');
        }

        return (int) \Configuration::getGlobalValue('PS_CHECKOUT_STATE_WAITING_PAYPAL_PAYMENT');
    }

    /**
     * @param array $resource
     *
     * @return string
     */
    private function getPaymentMessageTranslation(array $resource)
    {
        $module = \Module::getInstanceByName('ps_checkout');

        if (isset($resource['processor_response']['avs_code'])) {
            return $module->l('Payment by card');
        }

        return $module->l('Payment by PayPal');
    }
}
