<?php

namespace App\Support\ApiDocs;

use App\Http\ProblemDetails;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The error responses the API really sends, for the API reference: application/problem+json
 * (RFC 9457), as App\Http\ProblemDetails writes them.
 */
final class ProblemResponses
{
    public static function make(int $status, string $description, bool $validation = false): Response
    {
        $title = HttpResponse::$statusTexts[$status] ?? 'Error';

        $schema = (new ObjectType)
            ->addProperty('type', (new StringType)->setDescription('Always about:blank: status and title say what happened.')->example('about:blank'))
            ->addProperty('title', (new StringType)->setDescription('The HTTP status text.')->example($title))
            ->addProperty('status', (new IntegerType)->example($status))
            ->addProperty('detail', (new StringType)->setDescription('What went wrong, in a sentence for people.'))
            ->addProperty('instance', (new StringType)->setDescription('The path of the request.')->example('/api/v1/invoices'));

        $required = ['type', 'title', 'status', 'instance'];

        if ($validation) {
            $schema->addProperty('errors', (new ObjectType)
                ->setDescription('The messages of each field that failed, by field name, such as lines.0.quantity.')
                ->additionalProperties((new ArrayType)->setItems(new StringType)));
            $required[] = 'errors';
        }

        return Response::make($status)
            ->setDescription($description)
            ->setContent(ProblemDetails::CONTENT_TYPE, Schema::fromType($schema->setRequired($required)));
    }
}
