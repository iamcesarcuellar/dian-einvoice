<?php

namespace App\Support\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * S3 adapter of DocumentStorage: one object per file in the documents bucket of the CDK app (private,
 * encrypted by default, versioned, with lifecycle rules), under the same relative paths as the local disk,
 * so every key starts with the company id. S3 checks each upload against its SHA-256. The bucket is never
 * public: files reach clients only through authorized API endpoints, as on the local disk.
 *
 * In a versioned bucket a delete leaves a delete marker; the earlier versions expire with the lifecycle
 * rules of the bucket.
 */
final class S3DocumentStorage implements DocumentStorage
{
    /** Objects per DeleteObjects call, the most S3 takes. */
    private const int DELETE_BATCH = 1000;

    private const array CONTENT_TYPES = [
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
    ];

    public function __construct(
        private readonly S3ClientInterface $s3,
        private readonly string $bucket,
    ) {}

    public function put(string $path, string $contents): StoredDocument
    {
        $key = $this->key($path);
        $sha256 = hash('sha256', $contents, true);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $contents,
            'ContentType' => self::CONTENT_TYPES[strtolower(pathinfo($key, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'ChecksumSHA256' => base64_encode($sha256),
        ]);

        return new StoredDocument($path, bin2hex($sha256), strlen($contents));
    }

    public function get(string $path): string
    {
        try {
            $object = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                throw new RuntimeException("The document {$path} does not exist.", previous: $exception);
            }

            throw $exception;
        }

        $body = $object['Body'];

        return match (true) {
            $body instanceof StreamInterface => (string) $body,
            is_string($body) => $body,
            default => throw new RuntimeException("The document {$path} came back without a body."),
        };
    }

    public function exists(string $path): bool
    {
        try {
            $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function delete(string $path): void
    {
        // Deleting a key that does not exist succeeds.
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->deleteUnder(rtrim($this->key($prefix), '/').'/');
    }

    public function purge(): void
    {
        $this->deleteUnder('');
    }

    /** Deletes every current object whose key starts with the prefix, a page of the listing at a time. */
    private function deleteUnder(string $prefix): void
    {
        $token = null;

        do {
            $page = $this->s3->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => self::DELETE_BATCH,
                ...($token === null ? [] : ['ContinuationToken' => $token]),
            ]);

            /** @var list<array{Key: string}> $contents */
            $contents = $page['Contents'] ?? [];

            if ($contents !== []) {
                $result = $this->s3->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => array_map(fn (array $object): array => ['Key' => $object['Key']], $contents), 'Quiet' => true],
                ]);

                /** @var list<array{Key?: string, Message?: string}> $errors */
                $errors = $result['Errors'] ?? [];

                if ($errors !== []) {
                    throw new RuntimeException(sprintf('%d documents under %s could not be deleted, such as %s: %s', count($errors), $prefix === '' ? 'the bucket' : $prefix, $errors[0]['Key'] ?? '?', $errors[0]['Message'] ?? 'no reason given'));
                }
            }

            $next = $page['NextContinuationToken'] ?? null;
            $token = ($page['IsTruncated'] ?? false) === true && is_string($next) ? $next : null;
        } while ($token !== null);
    }

    /** The object key of a relative path; like the local disk, paths that climb out are refused. */
    private function key(string $path): string
    {
        $key = ltrim(str_replace('\\', '/', $path), '/');

        if ($key === '' || in_array('..', explode('/', $key), true)) {
            throw new InvalidArgumentException("The document path {$path} is not a relative path inside the storage.");
        }

        return $key;
    }
}
