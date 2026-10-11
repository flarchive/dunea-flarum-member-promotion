<?php

namespace Dunea\MemberPromotion\Api;

use Dunea\MemberPromotion\Exception\RuleValidationException;
use Dunea\MemberPromotion\Service\RuleService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CopyRuleController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private RuleService $rules)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        try {
            $rule = $this->rules->copy((int) ($request->getQueryParams()['id'] ?? 0), (int) $actor->id);

            return new JsonResponse(['rule' => $rule], 201);
        } catch (RuleValidationException $e) {
            return new JsonResponse(['errors' => $e->errors()], 422);
        }
    }
}
