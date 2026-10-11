<?php

namespace Dunea\MemberPromotion\Listener;

use Dunea\MemberPromotion\Service\CounterService;
use Dunea\MemberPromotion\Service\JobService;

final class ContentChangedListener
{
    public function __construct(private CounterService $counters, private JobService $jobs)
    {
    }

    public function postPosted(object $event): void
    {
        $this->counters->registerDiscussionUser($event->post);
        if ($this->counters->eligible($event->post)) {
            $this->counters->adjust($event->post, 1, 'reply_posted');
        }
    }

    public function postHidden(object $event): void
    {
        if ($this->counters->eligible($event->post, true)) {
            $this->counters->adjust($event->post, -1, 'reply_hidden');
        }
    }

    public function postRestored(object $event): void
    {
        if ($this->counters->eligible($event->post)) {
            $this->counters->adjust($event->post, 1, 'reply_restored');
        }
    }

    public function postDeleted(object $event): void
    {
        if ($this->counters->eligible($event->post)) {
            $this->counters->adjust($event->post, -1, 'reply_deleted');
        }
    }

    public function postApproved(object $event): void
    {
        if ($this->counters->eligible($event->post)) {
            $this->counters->adjust($event->post, 1, 'reply_approved');
        }
    }

    public function discussionChanged(object $event): void
    {
        $this->jobs->enqueue('discussion_changed', null, (int) $event->discussion->id);
    }

    public function discussionDeleting(object $event): void
    {
        $this->jobs->enqueue('discussion_deleted', null, (int) $event->discussion->id);
    }
}
