<?php

namespace Dunea\MemberPromotion\Api;

use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SearchUsersController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $builder = $this->db->table('users')->select(['id', 'username', 'joined_at'])->orderBy('id')->limit(20);
        if ($query !== '') {
            $builder->where(function ($where) use ($query) {
                // Prefix search can use Flarum's username index. A contains search forces a
                // full users-table scan and becomes unusable on million-user communities.
                $where->where('username', 'like', addcslashes($query, '%_\\').'%');
                if (ctype_digit($query)) {
                    $where->orWhere('id', (int) $query);
                }
            });
        }

        return new JsonResponse(['users' => $builder->get()]);
    }
}
