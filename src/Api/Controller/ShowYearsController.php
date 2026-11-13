<?php

namespace ErnestDefoe\Ridge\Api\Controller;

use ErnestDefoe\Ridge\Years;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The year rail: discussions per year for whatever the list is showing.
 *
 * Takes the list's own `filter[...]` parameters, so a tag page, a search or
 * the following list each get their own years.
 */
class ShowYearsController implements RequestHandlerInterface
{
    public function __construct(
        private Years $years,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $filters = Arr::get($request->getQueryParams(), 'filter');
        $filters = is_array($filters) ? array_filter($filters, fn ($value) => is_string($value) || is_array($value)) : [];

        $years = $this->settings->get('ridge.years')
            ? $this->years->for(RequestUtil::getActor($request), $filters)
            : [];

        return new JsonResponse([
            'data' => [
                'type' => 'ridge-years',
                'id' => $this->years->column(),
                'attributes' => ['years' => $years],
            ],
        ]);
    }
}
