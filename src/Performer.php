<?php
/**
 * Created by PhpStorm.
 * User: ww
 * Date: 11.10.15
 * Time: 17:56
 */
namespace React\PublisherPulsar;

use AdjutantHandlers\Objects\Logging;
use React\FractalBasic\Abstracts\BaseExecutor;
use React\PublisherPulsar\Interfaces\PerformerZmqSubscriber;
use React\PublisherPulsar\Inventory\ActionResultingPushDto;
use React\PublisherPulsar\Inventory\BecomeTheSubscriberReplyDto;
use React\PublisherPulsar\Inventory\Exceptions\PublisherPulsarException;
use React\PublisherPulsar\Inventory\Exceptions\PublisherPulsarExceptionsConstants;
use React\PublisherPulsar\Inventory\PerformerConstants;
use React\PublisherPulsar\Inventory\PerformerDto;
use React\PublisherPulsar\Inventory\PerformerEarlyTerminated;
use React\PublisherPulsar\Inventory\PerformerSocketsParamsDto;
use React\PublisherPulsar\Inventory\PreparingRequestDto;
use React\PublisherPulsar\Inventory\PublisherToSubscribersDto;
use React\PublisherPulsar\Inventory\ReadyToGetSubscriptionMsg;
use Monolog\Logger;

class Performer extends BaseExecutor implements PerformerZmqSubscriber
{
    /**
     * @var PerformerSocketsParamsDto
     */
    protected $socketsParams;

    /**
     * @var BecomeTheSubscriberReplyDto
     */
    protected $becomeTheSubscriberReplyDto;

    /**
     * @var PublisherToSubscribersDto
     */
    protected $publisherToSubscribersDto;

    /**
     * @var \ZMQSocket
     */
    protected $requestSocket;

    /**
     * @var \ZMQSocket
     */
    protected $subscriberSocket;

    /**
     * @var \ZMQSocket
     */
    protected $pushSocket;

    /**
     * @var PerformerEarlyTerminated
     */
    protected $performerEarlyTerminated;

    /**
     * @return PerformerEarlyTerminated
     */
    public function getPerformerEarlyTerminated()
    {
        return $this->performerEarlyTerminated;
    }

    /**
     * @return PerformerSocketsParamsDto
     */
    public function getSocketsParams()
    {
        return $this->socketsParams;
    }

    /**
     * @param PerformerSocketsParamsDto $socketsParams
     */
    public function setSocketsParams(PerformerSocketsParamsDto $socketsParams)
    {
        $this->socketsParams = $socketsParams;
    }

    /**
     * @return BecomeTheSubscriberReplyDto
     */
    public function getBecomeTheSubscriberReplyDto()
    {
        return $this->becomeTheSubscriberReplyDto;
    }

    /**
     * @param BecomeTheSubscriberReplyDto $becomeTheSubscriberReplyDto
     */
    public function setBecomeTheSubscriberReplyDto($becomeTheSubscriberReplyDto)
    {
        $this->becomeTheSubscriberReplyDto = $becomeTheSubscriberReplyDto;
    }

    public function __construct(PerformerDto $performerDto)
    {
        parent::__construct($performerDto);
        $this->performerEarlyTerminated = new PerformerEarlyTerminated();

        return null;
    }

    /**Make a request and wait response in blocking mode. After response
     * start subscription.
     * @return null
     */
    public function requestForActionPermission($dontWait = null)
    {
        $sendSuccess = null;

        if (!$this->requestSocket) {
            $this->requestSocket = $this->context->getSocket(\ZMQ::SOCKET_REQ);
            $this->requestSocket->connect($this->socketsParams->getRequestPulsarRsSocketAddress());
        }

        $requestDto = new PreparingRequestDto();

        if ($dontWait) {

            try {

                $this->requestSocket->send(serialize($requestDto));
                $sendSuccess = true;

            } catch (\Exception $e) {

                $this->logger->debug("Possibly work PerformerImitator.");

            }

            $this->becomeTheSubscriberReplyDto = unserialize($this->requestSocket->recv(\ZMQ::MODE_DONTWAIT));

        } else {

            $this->requestSocket->send(serialize($requestDto));

            $this->logger->debug("Performer send preparing request. Wait receive");
            //->recv() block execution until data will received
            $this->becomeTheSubscriberReplyDto = unserialize($this->requestSocket->recv());
            if (!$this->becomeTheSubscriberReplyDto->isAllowToStartSubscription()) {
                throw new PublisherPulsarException(PublisherPulsarExceptionsConstants::SUBSCRIPTION_IS_NOT_ALLOWED);
            }

            $this->logger->debug("Performer got allowing becomeTheSubscriberReply.");

        }

        return $sendSuccess;
    }

    /**Wait subscription message before doing action
     * @return null
     */
    public function waitAllowingSubscriptionMessage()
    {
        if (!$this->subscriberSocket) {
            $this->subscriberSocket = $this->context->getSocket(\ZMQ::SOCKET_SUB);
            $this->subscriberSocket->connect($this->socketsParams->getPublisherPulsarSocketAddress());
        }

        $this->subscriberSocket->setSockOpt(\ZMQ::SOCKOPT_SUBSCRIBE, "");
        $this->logger->debug("Performer become subscriber.");

        //use in Pulsar to divide termination of current subscribers and potential subscribers, if early termination occurred
        $this->performerEarlyTerminated->setStandOnSubscription(true);

        $this->pushReadyToGetSubscriptionMsg();

        //->recv() block execution until data will received; all subscribers will continue execution synchronously
        $this->logger->debug("Performer wait subscription msg.");
        $this->publisherToSubscribersDto = unserialize($this->subscriberSocket->recv());
        $this->logger->debug("Performer got subscription msg.");

        if (!$this->publisherToSubscribersDto->isAllowAction()) {
            throw new PublisherPulsarException(PublisherPulsarExceptionsConstants::ACTION_IS_NOT_ALLOWED);
        }

        return null;
    }

    /**After action done worker-subscriber stop subscription and by push socket send action's info
     * @param ActionResultingPushDto $resultingPushDto
     * @return null
     */
    public function pushActionResultInfo(ActionResultingPushDto $resultingPushDto)
    {
        $this->subscriberSocket->setSockOpt(\ZMQ::SOCKOPT_UNSUBSCRIBE, "");

        $this->performerEarlyTerminated->setStandOnSubscription(false);

        $this->logger->debug("Performer was unsubscribed.");

        $this->initOrCheckPushConnection();

        $this->pushSocket->send(serialize($resultingPushDto));

        $this->logger->debug("Performer send actionResulting msg.");

        return null;
    }

    /**
     * @return null
     */
    public function pushPerformerEarlyTerminated()
    {
        $this->initOrCheckPushConnection();

        $this->pushSocket->send(serialize($this->performerEarlyTerminated));

        $this->logger->debug("Performer sent pushPerformerEarlyTerminated");

        return null;
    }

    /**
     * @return null
     */
    public function pushReadyToGetSubscriptionMsg()
    {
        $this->initOrCheckPushConnection();

        $this->pushSocket->send(serialize(new ReadyToGetSubscriptionMsg()));
        $this->logger->debug("Performer send that ready to get subscription msg.");

        return null;
    }

    protected function initOrCheckPushConnection()
    {
        if (!$this->pushSocket) {
            $this->pushSocket = $this->context->getSocket(\ZMQ::SOCKET_PUSH);
            $this->pushSocket->connect($this->socketsParams->getPushPulsarSocketAddress());
        }

        return null;
    }

    /**
     * @return null
     */
    public function pushActionResultInfoWithoutPulsarCorrectionBehavior()
    {
        $actionResult = new ActionResultingPushDto();
        $actionResult->setActionCompleteCorrectly(true);

        $this->pushActionResultInfo($actionResult);

        return null;
    }
}