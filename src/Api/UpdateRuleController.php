<?php

namespace Dunea\MemberPromotion\Api;

use Dunea\MemberPromotion\Exception\RuleValidationException;
use Dunea\MemberPromotion\Service\RuleService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UpdateRuleController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private RuleService $rules)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $data = $this->body($request);
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        try {
            $result = $this->rules->update($id, $data, (int) $actor->id, (bool) ($data['confirmStructural'] ?? false));

            return new JsonResponse($result, isset($result['preview']) ? 409 : 200);
        } catch (RuleValidationException $e) {
            return new JsonResponse(['errors' => $e->errors()], 422);
        }
    }
}
