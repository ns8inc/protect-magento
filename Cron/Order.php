<?php
namespace NS8\Protect\Cron;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order as MagentoOrder;
use NS8\Protect\Helper\Order as OrderHelper;
use NS8\ProtectSDK\Order\Client as NS8Order;
use NS8\ProtectSDK\Queue\Client as QueueClient;
use NS8\ProtectSDK\Logging\Client as LoggingClient;

/**
 * Cron-job to permit polling to NS8 Protect Services
 */
class Order
{
    /**
     * Order states where the order is no longer active and we should not update it.
     */
    const INACTIVE_ORDER_STATES = [
        MagentoOrder::STATE_CANCELED,
        MagentoOrder::STATE_CLOSED,
        MagentoOrder::STATE_COMPLETE
    ];

    /**
     * Comments applied to order upon status update events
     */
    const ORDER_CANCELED_COMMENT = 'NS8 Protect Order Cancelled';
    const ORDER_APPROVED_COMMENT = 'NS8 Protect Order Approved';
    const ORDER_HOLDED_COMMENT = 'NS8 Protect Order Requires Review';

    /**
     * Max number of minutes the cron should run for.
     * This value should be one minute less than the cron's scheduled rate.
     */
    const MAX_RUN_TIME_MINUTES = 14;

    /**
     * Number of seconds to sleep when no messages are received
     */
    const SLEEP_TIME = 5;

    /**
     * @var OrderHelper
     */
    protected $orderHelper;

    /**
     * @var LoggingClient
     */
    protected $loggingClient;

    /**
     * Default constructor
     *
     * @param Order $order
     */
    public function __construct(
        OrderHelper $orderHelper
    ) {
        $this->orderHelper=$orderHelper;
        $this->loggingClient = new LoggingClient();
    }

    /**
     * Execute cron job to process messages from the NS8 Protect queue and update orders
     *
     * @return void
     */
    public function execute() : void
    {
        try {
            $maxEndTime = strtotime(sprintf("+%d minutes", self::MAX_RUN_TIME_MINUTES));
            QueueClient::initialize();
            do {
                $messages = QueueClient::getMessages();
                $currentTime = strtotime("now");
                if (empty($messages)) {
                    // phpcs:ignore
                    sleep(self::SLEEP_TIME);
                } else {
                    $this->processMessageArray($messages);
                }
            } while ($currentTime < $maxEndTime);
        } catch (\Exception $e) {
            $this->loggingClient->error('Protect Order Update Cron Job has failed to execute successfully.', $e);
            throw $e;
        }
    }

    /**
     * Process an array of messages (message batch) to update the associated orders
     *
     * @param array $messages Array of messages we want to iterate through and update
     *
     * @return void
     */
    protected function processMessageArray(array $messages) : void
    {
        foreach ($messages as $messageData) {
            // Update order details based on message
            $order =  $this->orderHelper->getOrderByIncrementId($messageData['orderId']);
            if (!$order || !$order->getId()) {
                $this->loggingClient->error(sprintf('Unable to fetch order %s', $messageData['orderId']));
                continue;
            }

            if (!is_null($messageData['score']) && $messageData['score'] !== '') {
                $this->orderHelper->setEQ8Score((int) $messageData['score'], $order);
            }

            $isActionSuccessful = $this->processOrderStatusUpdate($order, $messageData['status']);
            if ($isActionSuccessful) {
                $order->save();
                QueueClient::deleteMessage($messageData['receipt_handle']);
            }

        }
    }

    /**
     * Process an order status update given the order and the new status
     * @param OrderInterface $order The order we are going to try to update
     * @param string $newStatus The new status of the order
     *
     * @return bool Returns true if the action was successful otherwise false
     */
    protected function processOrderStatusUpdate(OrderInterface $order, string $newStatus) : bool
    {
        // Before updating the order, make sure it is active
        $currentOrderState = $order->getState();
        if (in_array($currentOrderState, self::INACTIVE_ORDER_STATES)) {
            $this->loggingClient->info('Attempting to update an order not in an active state.');
            return true;
        }

        $isActionSuccessful = false;
        switch ($newStatus) {
            case NS8Order::CANCELLED_STATE:
                $isActionSuccessful = $this->cancelOrder($order);
                break;
            case NS8Order::APPROVED_STATE:
                $isActionSuccessful = $this->approveOrder($order);
                break;
            case NS8Order::MERCHANT_REVIEW_STATE:
                $isActionSuccessful = $this->holdOrder($order);
                break;
            default:
                $this->loggingClient->error(sprintf('Message with unrecognized status: %s', $newStatus));
                break;
        }

        return $isActionSuccessful;
    }

    /**
     * Cancel an order and update order history
     *
     * @param OrderInterface $order The order we intend to cancel
     *
     * @return bool Returns true if intended result occurred, false if an exception was encountered
     */
    protected function cancelOrder(OrderInterface $order) : bool
    {
        try {
            if (!$order->canCancel()) {
                $this->loggingClient->info(
                    sprintf('Unable to cancel Order #%s as it cannot be canceled', $order->getIncrementId())
                );
                return true;
            }

            $order->cancel();
            $this->addOrderComment($order, NS8Order::CANCELLED_STATE, self::ORDER_CANCELED_COMMENT);
            return true;
        } catch (\Exception $e) {
            $this->loggingClient->error(
                sprintf('Unable to cancel Order #%s due to an Exception', $order->getIncrementId()),
                $e
            );

            return false;
        }
    }

    /**
     * Approve an order and remove any hold
     *
     * @param OrderInterface $order The order we want to approvd
     *
     * @return bool Returns true if intended result occurred, false if an exception was encountered
     */
    protected function approveOrder(OrderInterface $order) : bool
    {
        try {
            if ($order->getState() != MagentoOrder::STATE_HOLDED) {
                return true;
            }

            if (!$order->canUnhold()) {
                $this->loggingClient->info(
                    sprintf('Unable to unhold/approve Order #%s as it cannot be unholded', $order->getIncrementId())
                );
                return true;
            }

            $order->unhold();
            $this->addOrderComment($order, NS8Order::APPROVED_STATE, self::ORDER_APPROVED_COMMENT);
            return true;
        } catch (\Exception $e) {
            $this->loggingClient->error(
                sprintf('Unable to approve Order #%s due to an Exception', $order->getIncrementId()),
                $e
            );
            return false;
        }
    }

    /**
     * Marks an order as on hold (holded)
     *
     * @param OrderInterface The order we want to hold
     *
     * @return bool Returns true if intended result occurred, false if an exception was encountered
     */
    protected function holdOrder(OrderInterface $order) : bool
    {
        try {
            if (!$order->canHold()) {
                $this->loggingClient->info(
                    sprintf('Unable to hold Order #%s as it cannot be holded', $order->getIncrementId())
                );
                return true;
            }

            $order->hold();
            $this->addOrderComment($order, NS8Order::MERCHANT_REVIEW_STATE, self::ORDER_HOLDED_COMMENT);
            return true;
        } catch (\Exception $e) {
            $this->loggingClient->error(
                sprintf('Unable to hold Order #%s due to an Exception', $order->getIncrementId()),
                $e
            );

            return false;
        }
    }

    /**
     * Add a comment to the specified order
     *
     * @param OrderInterface $order The order we want to add a comment to
     * @param string $status The status the order is being set to
     * @param string $comment The comment we want to add
     *
     * @return bool Returns true if we successfully added a comment otherwise false
     */
    protected function addOrderComment(OrderInterface $order, string $status, string $comment) : bool
    {
        try {
            if (!$order->canComment()) {
                return false;
            }

            $order->addStatusHistoryComment($comment, $status);
            $returnStatus = true;
        } catch (\Exception $e) {
            $returnStatus = false;
        }

        return $returnStatus;
    }
}
