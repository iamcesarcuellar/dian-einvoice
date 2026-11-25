<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Tag;
use ReflectionProperty;
use SplObjectStorage;

/**
 * Puts the API reference into Spanish: a document transformer of the Spanish reference (ApiDocumentation), run after
 * Scramble has built the whole document. Every summary and description of the document, and the name of every group
 * where it is declared and where an operation names it, go through ReferenceCatalog; nothing else changes, so both
 * references describe the same contract. It walks the objects of Scramble's generator only, each once: a reference to
 * a component leads back to the components, which hold them all.
 */
final class TranslateReference
{
    private const string GENERATOR = 'Dedoc\\Scramble\\Support\\Generator\\';

    public function __invoke(OpenApi $document): void
    {
        /** @var SplObjectStorage<object, null> $seen */
        $seen = new SplObjectStorage;
        $this->walk($document, ReferenceCatalog::spanish(), $seen);
    }

    /**
     * @param  SplObjectStorage<object, null>  $seen
     */
    private function walk(object $node, ReferenceCatalog $catalog, SplObjectStorage $seen): void
    {
        if ($seen->offsetExists($node) || ! str_starts_with($node::class, self::GENERATOR)) {
            return;
        }

        $seen->offsetSet($node);

        foreach (get_object_vars($node) as $property => $value) {
            if (is_string($value) && $value !== '' && ($property === 'description' || $property === 'summary' || ($property === 'name' && $node instanceof Tag))) {
                (new ReflectionProperty($node, $property))->setValue($node, $catalog->translate($value));
            } elseif ($property === 'tags' && $node instanceof Operation) {
                $node->tags = array_map(fn (mixed $tag): mixed => is_string($tag) ? $catalog->translate($tag) : $tag, $node->tags);
            } else {
                $this->walkValue($value, $catalog, $seen);
            }
        }
    }

    /**
     * @param  SplObjectStorage<object, null>  $seen
     */
    private function walkValue(mixed $value, ReferenceCatalog $catalog, SplObjectStorage $seen): void
    {
        if (is_object($value)) {
            $this->walk($value, $catalog, $seen);
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->walkValue($item, $catalog, $seen);
            }
        }
    }
}
