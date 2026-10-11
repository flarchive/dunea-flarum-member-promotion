<?php

namespace Dunea\MemberPromotion\Api;

use Dunea\MemberPromotion\Service\Evaluator;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class InspectUserController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private Evaluator $evaluator, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $report = $this->evaluator->evaluate($id, true);
        $report['state'] = $this->db->table('member_promotion_user_states')->where('user_id', $id)->first();
        $report['grants'] = $this->db->table('member_promotion_grants')->where('user_id', $id)->orderByDesc('id')->get();
        $report['blocks'] = $this->db->table('member_promotion_blocks')->where('user_id', $id)->whereNull('cleared_at')->get();

        return new JsonResponse($report);
    }
}
