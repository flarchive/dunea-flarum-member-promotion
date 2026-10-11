<?php

namespace Dunea\MemberPromotion\Api;

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DashboardController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db, private SettingsRepositoryInterface $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $groups = $this->db->table('groups')->where('id', '>', Group::MODERATOR_ID)->orderBy('name_singular')->get();
        $rules = $this->db->table('member_promotion_rules')->where('archived', false)->orderByDesc('priority')->orderBy('id')->get();

        return new JsonResponse([
            'settings' => [
                'enabled' => $this->settings->get('dunea-member-promotion.enabled', '0') === '1',
                'excludedGroupIds' => json_decode($this->settings->get('dunea-member-promotion.excluded_group_ids', '[]'), true) ?: [],
                'logRetentionDays' => (int) $this->settings->get('dunea-member-promotion.log_retention_days', '90'),
            ],
            'groups' => $groups,
            'rules' => $rules,
        ]);
    }
}
