<?php

namespace Dunea\MemberPromotion\Api;

use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LogsController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $params = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($params['limit'] ?? 50)));
        $query = $this->db->table('member_promotion_logs')->orderByDesc('id')->limit($limit + 1);
        foreach (['user_id' => 'user', 'rule_id' => 'rule', 'status' => 'status'] as $column => $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query->where($column, $params[$key]);
            }
        }
        if (! empty($params['before'])) {
            $query->where('id', '<', (int) $params['before']);
        }
        if (! empty($params['from'])) {
            $query->where('created_at', '>=', $params['from']);
        }
        if (! empty($params['to'])) {
            $query->where('created_at', '<=', $params['to']);
        }
        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();

        return new JsonResponse(['logs' => $items, 'next' => $hasMore ? $items->last()->id : null]);
    }
}
