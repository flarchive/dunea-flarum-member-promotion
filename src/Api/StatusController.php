<?php

namespace Dunea\MemberPromotion\Api;

use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class StatusController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $runtime = $this->db->table('member_promotion_runtime')->pluck('value', 'key');

        return new JsonResponse([
            'heartbeat' => $runtime['last_completed_at'] ?? null,
            'lastStartedAt' => $runtime['last_started_at'] ?? null,
            'lastError' => $runtime['last_error'] ?? null,
            'lastStats' => isset($runtime['last_stats']) ? json_decode($runtime['last_stats'], true) : null,
            'pendingUsers' => $this->db->table('member_promotion_pending')->count(),
            'jobs' => $this->db->table('member_promotion_jobs')->orderByDesc('id')->limit(20)->get(),
            'failedJobs' => $this->db->table('member_promotion_jobs')->where('status', 'failed')->count(),
        ]);
    }
}
