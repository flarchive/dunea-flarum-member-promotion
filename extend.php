<?php

use Dunea\MemberPromotion\Api;
use Dunea\MemberPromotion\Command;
use Dunea\MemberPromotion\Listener;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default('dunea-member-promotion.enabled', '0')
        ->default('dunea-member-promotion.excluded_group_ids', '[]')
        ->default('dunea-member-promotion.log_retention_days', '90'),

    (new Extend\Routes('api'))
        ->get('/member-promotion/dashboard', 'dunea.member-promotion.dashboard', Api\DashboardController::class)
        ->post('/member-promotion/settings', 'dunea.member-promotion.settings', Api\SettingsController::class)
        ->post('/member-promotion/rules', 'dunea.member-promotion.rules.create', Api\CreateRuleController::class)
        ->patch('/member-promotion/rules/{id}', 'dunea.member-promotion.rules.update', Api\UpdateRuleController::class)
        ->post('/member-promotion/rules/{id}/copy', 'dunea.member-promotion.rules.copy', Api\CopyRuleController::class)
        ->delete('/member-promotion/rules/{id}', 'dunea.member-promotion.rules.delete', Api\DeleteRuleController::class)
        ->get('/member-promotion/users', 'dunea.member-promotion.users.search', Api\SearchUsersController::class)
        ->get('/member-promotion/users/{id}', 'dunea.member-promotion.users.inspect', Api\InspectUserController::class)
        ->post('/member-promotion/users/{id}/action', 'dunea.member-promotion.users.action', Api\UserActionController::class)
        ->get('/member-promotion/logs', 'dunea.member-promotion.logs', Api\LogsController::class)
        ->get('/member-promotion/status', 'dunea.member-promotion.status', Api\StatusController::class),

    (new Extend\Console())
        ->command(Command\CheckCommand::class)
        ->command(Command\RebuildCountsCommand::class)
        ->command(Command\StatusCommand::class)
        ->command(Command\RunCommand::class)
        ->schedule('member-promotion:run', function (Event $event) {
            $event->everyMinute()->withoutOverlapping(5);
        }),

    (new Extend\Event())
        ->listen(Flarum\Post\Event\Posted::class, [Listener\ContentChangedListener::class, 'postPosted'])
        ->listen(Flarum\Post\Event\Hidden::class, [Listener\ContentChangedListener::class, 'postHidden'])
        ->listen(Flarum\Post\Event\Restored::class, [Listener\ContentChangedListener::class, 'postRestored'])
        ->listen(Flarum\Post\Event\Deleted::class, [Listener\ContentChangedListener::class, 'postDeleted'])
        ->listen(Flarum\Discussion\Event\Hidden::class, [Listener\ContentChangedListener::class, 'discussionChanged'])
        ->listen(Flarum\Discussion\Event\Restored::class, [Listener\ContentChangedListener::class, 'discussionChanged'])
        ->listen(Flarum\Discussion\Event\Deleting::class, [Listener\ContentChangedListener::class, 'discussionDeleting'])
        ->listen('Flarum\\Approval\\Event\\PostWasApproved', [Listener\ContentChangedListener::class, 'postApproved'])
        ->listen(Flarum\User\Event\GroupsChanged::class, Listener\ManualGroupChangeListener::class),
];
