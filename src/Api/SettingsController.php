<?php

namespace Dunea\MemberPromotion\Api;

use Dunea\MemberPromotion\Service\JobService;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SettingsController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ConnectionInterface $db,
        private JobService $jobs
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $data = $this->body($request);
        $retention = (int) ($data['logRetentionDays'] ?? 90);
        $excluded = array_values(array_unique(array_map('intval', $data['excludedGroupIds'] ?? [])));
        if ($retention < 1 || $retention > 3650) {
            return new JsonResponse(['error' => '日志保留天数超出允许范围。'], 422);
        }
        if ($excluded && $this->db->table('groups')->whereIn('id', $excluded)->count() !== count($excluded)) {
            return new JsonResponse(['error' => '排除组中包含不存在的用户组。'], 422);
        }
        $wasEnabled = $this->settings->get('dunea-member-promotion.enabled', '0') === '1';
        $enabled = (bool) ($data['enabled'] ?? false);
        $this->settings->set('dunea-member-promotion.enabled', $enabled ? '1' : '0');
        $this->settings->set('dunea-member-promotion.excluded_group_ids', json_encode($excluded));
        $this->settings->set('dunea-member-promotion.log_retention_days', (string) $retention);
        if ($enabled && ! $wasEnabled) {
            $this->jobs->enqueue('scan_users');
        }

        return new JsonResponse(['saved' => true]);
    }
}
