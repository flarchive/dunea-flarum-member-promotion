<?php

namespace Dunea\MemberPromotion\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractAdminController
{
    protected function actor(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        return $actor;
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed['data']['attributes'] ?? $parsed;
        }
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? ($decoded['data']['attributes'] ?? $decoded) : [];
    }
}
