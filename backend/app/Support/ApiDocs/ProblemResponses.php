<?php

namespace App\Support\ApiDocs;

use App\Http\ProblemDetails;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use LogicException;
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

        // Several builder methods of Scramble declare no return type, so they are not chained.
        $schema = new ObjectType;
        $schema->addProperty('type', (new StringType)->setDescription('Always about:blank: status and title say what happened.')->example('about:blank'));
        $schema->addProperty('title', (new StringType)->setDescription('The HTTP status text.')->example($title));
        $schema->addProperty('status', (new IntegerType)->example($status));
        $schema->addProperty('detail', (new StringType)->setDescription('What went wrong, in a sentence for people.'));
        $schema->addProperty('instance', (new StringType)->setDescription('The path of the request.')->example('/api/v1/invoices'));
        $schema->addProperty('request_id', (new StringType)->setDescription('The response\'s X-Request-Id, which the logs carry: quote it when asking about a request.'));
        $schema->addProperty('correlation_id', (new StringType)->setDescription('The response\'s X-Correlation-Id: the request\'s journey id, which every log line, event, webhook and email of the journey carries.')->example('01jcz9w6n8s0k3q4r5t6v7w8x9'));

        $required = ['type', 'title', 'status', 'instance'];

        if ($validation) {
            $messages = new ArrayType;
            $messages->setItems(new StringType);
            $errors = (new ObjectType)->setDescription('Each failed field\'s messages, by field name, such as lines.0.quantity.');
            $errors->additionalProperties($messages);
            $schema->addProperty('errors', $errors);
            $required[] = 'errors';
        }

        $schema->setRequired($required);

        return (new Response($status))
            ->setDescription($description)
            ->setContent(ProblemDetails::CONTENT_TYPE, self::schema($schema));
    }

    /** Schema::fromType() of Scramble, which declares no return type. */
    public static function schema(Type $type): Schema
    {
        $schema = Schema::fromType($type);

        return $schema instanceof Schema ? $schema : throw new LogicException('Schema::fromType() builds a Schema.');
    }
}
