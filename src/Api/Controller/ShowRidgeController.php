<?php

namespace ErnestDefoe\Ridge\Api\Controller;

use ErnestDefoe\Ridge\Heat;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tobyz\JsonApiServer\Exception\NotFoundException;

/**
 * One small read, once per discussion.
 *
 * 🚨 A route of its own rather than an attribute on the discussion resource.
 * The heat is four aggregate queries, and hanging it off DiscussionResource
 * would run them for every row of the discussion LIST — twenty threads' worth
 * of counting to draw nothing, because the list has no scrubber on it.
 */
class ShowRidgeController implements RequestHandlerInterface
{
    public function __construct(private Heat $heat) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = Arr::get($request->getQueryParams(), 'id');

        $discussion = Discussion::query()->whereVisibleTo($actor)->find($id);

        if (! $discussion) {
            throw new NotFoundException();
        }

        return new JsonResponse([
            'data' => [
                'type' => 'ridge',
                'id' => (string) $discussion->id,
                'attributes' => $this->heat->for($discussion, $actor),
            ],
        ]);
    }
}
