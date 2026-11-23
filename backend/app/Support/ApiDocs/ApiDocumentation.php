<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\HttpSecurityScheme;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * The public API reference (Scramble, /docs/api, exported to openapi.json by make openapi): the
 * endpoints an API key can call, grouped by resource, behind a bearer security scheme. Endpoints
 * of the web app session only (sign-in, companies, settings, API keys, webhooks, the development
 * tools) are left out.
 */
final class ApiDocumentation
{
    /** Tag of each group of routes, by the first part of the route name, in the order shown. */
    private const array TAGS = [
        'health' => ['Health', 'Whether the API and its database respond.'],
        'reference' => ['Reference data', 'DIAN code lists and the municipalities of Colombia (DIVIPOLA).'],
        'contacts' => ['Contacts', 'The company\'s customers and suppliers.'],
        'items' => ['Items', 'Products and services, with their price and tax.'],
        'taxes' => ['Taxes', 'The company\'s tax rates (read only with API keys).'],
        'withholdings' => ['Withholdings', 'The company\'s withholding rates (read only with API keys).'],
        'number-templates' => ['Numbering', 'Numbering ranges authorized by the DIAN (read only with API keys).'],
        'invoices' => ['Invoices', 'Electronic sales invoices: drafts, issue, the electronic document chain and the delivery to the customer.'],
        'credit-notes' => ['Credit notes', 'Credit notes of accepted invoices, voids included.'],
        'debit-notes' => ['Debit notes', 'Debit notes of accepted invoices.'],
        'payments' => ['Payments', 'Payments received from customers, applied to their invoices, and the receivables.'],
    ];

    public static function configure(): void
    {
        // routes() and SecurityScheme::http() of Scramble declare no return type, so they are not chained.
        $config = Scramble::configure();
        $config->routes(fn (Route $route): bool => self::isPublic($route));
        $config->withTags(self::tags())
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure((new HttpSecurityScheme('bearer', 'API key'))
                    ->as('apiKey')
                    ->setDescription('A company API key, created by an owner or admin in Settings → Developers: Authorization: Bearer dei_...'));
            });

        Scramble::resolveTagsUsing(fn (RouteInfo $routeInfo, Operation $operation): array => [self::tag($routeInfo->route)]);
    }

    /**
     * The groups with their descriptions, weighted in the order of TAGS.
     *
     * @return list<Tag>
     */
    private static function tags(): array
    {
        $tags = [];

        foreach (array_values(self::TAGS) as $weight => [$name, $description]) {
            $tag = new Tag($name, $description);
            $tag->setAttribute('weight', $weight);
            $tags[] = $tag;
        }

        return $tags;
    }

    /** The routes an API key may call, plus the health check. */
    private static function isPublic(Route $route): bool
    {
        if (! Str::startsWith($route->uri(), 'api/v1/')) {
            return false;
        }

        if ($route->getName() === 'health') {
            return true;
        }

        $middleware = $route->gatherMiddleware();

        return in_array('auth:api-key,sanctum', $middleware, true) && ! in_array('panel', $middleware, true);
    }

    private static function tag(Route $route): string
    {
        $name = (string) $route->getName();
        $group = match (true) {
            $name === 'receivables', $name === 'contacts.open-invoices' => 'payments',
            default => Str::before($name, '.'),
        };

        return self::TAGS[$group][0] ?? Str::headline($group);
    }
}
