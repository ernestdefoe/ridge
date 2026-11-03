<?php

namespace ErnestDefoe\Ridge\Api\Controller;

use ErnestDefoe\Ridge\Marks;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tobyz\JsonApiServer\Exception\NotFoundException;

/**
 * The labels for the post marks, asked for the first time a reader points at
 * the scrubber — a page nobody hovers never sends it.
 */
class ShowMarksController implements RequestHandlerInterface
{
    public function __construct(private Marks $marks)
    {
    }

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
                'type' => 'ridge-marks',
                'id' => (string) $discussion->id,
                'attributes' => $this->marks->for($discussion, $actor),
            ],
        ]);
    }
}
