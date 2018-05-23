<?php
/**
 */

namespace Commercetools\Symfony\ReviewBundle\Manager;


use Commercetools\Core\Request\AbstractAction;
use Commercetools\Core\Model\Review\Review;
use Commercetools\Symfony\CtpBundle\Model\QueryParams;
use Commercetools\Symfony\ReviewBundle\Event\ReviewUpdateEvent;
use Commercetools\Symfony\ReviewBundle\Event\ReviewPostUpdateEvent;
use Commercetools\Symfony\ReviewBundle\Model\Repository\ReviewRepository;
use Commercetools\Symfony\ReviewBundle\Model\ReviewUpdateBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ReviewManager
{
    /**
     * @var ReviewRepository
     */
    private $repository;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * ReviewManager constructor.
     * @param ReviewRepository $repository
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(ReviewRepository $repository, EventDispatcherInterface $dispatcher)
    {
        $this->repository = $repository;
        $this->dispatcher = $dispatcher;
    }

    public function getById($locale, $reviewId, QueryParams $params = null)
    {
        return $this->repository->getReviewById($locale, $reviewId, $params);
    }

    /**
     * @param Review $review
     * @return ReviewUpdateBuilder
     */
    public function update(Review $review)
    {
        return new ReviewUpdateBuilder($review, $this);
    }

    public function dispatch(Review $review, AbstractAction $action, $eventName = null)
    {
        $eventName = is_null($eventName) ? get_class($action) : $eventName;

        $event = new ReviewUpdateEvent($review, $action);
        $event = $this->dispatcher->dispatch($eventName, $event);

        return $event->getActions();
    }

    /**
     * @param Review $review
     * @param array $actions
     * @return Review
     */
    public function apply(Review $review, array $actions)
    {
        $review = $this->repository->update($review, $actions);

        return $this->dispatchPostUpdate($review, $actions);
    }

    public function dispatchPostUpdate(Review $review, array $actions)
    {
        $event = new ReviewPostUpdateEvent($review, $actions);
        $event = $this->dispatcher->dispatch(ReviewPostUpdateEvent::class, $event);

        return $event->getReview();
    }
}
