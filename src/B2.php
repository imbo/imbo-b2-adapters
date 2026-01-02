<?php declare(strict_types=1);
namespace Imbo\Storage;

use DateTime;
use Imbo\Exception\StorageException;

class B2 implements StorageInterface
{
    private Client $client;
    private string $keyId;
    private string $applicationKey;
    private string $bucketName;
    private string $bucketId;

    /**
     * Class constructor
     *
     * If a pre-configured client is not provided the constructor will create the client, resulting
     * in an account authorization, which can end up throwing an exception.
     *
     * @param string $keyId          B2 key ID
     * @param string $applicationKey B2 application key
     * @param string $bucketId       ID of the bucket to store the files in
     * @param string $bucketName     Name of the bucket to store the files in
     * @param Client $client         A pre-configured client
     */
    public function __construct(string $keyId, string $applicationKey, string $bucketId, string $bucketName, ?Client $client = null)
    {
        $this->keyId          = $keyId;
        $this->applicationKey = $applicationKey;
        $this->bucketId       = $bucketId;
        $this->bucketName     = $bucketName;
        $this->client         = $client ?: new Client($this->keyId, $this->applicationKey, $this->bucketId, $this->bucketName);
    }

    public function store(string $user, string $imageIdentifier, string $imageData): bool
    {
        try {
            return $this->client->uploadFile(
                $this->getImagePath($user, $imageIdentifier),
                $imageData,
            );
        } catch (Client\Exception $e) {
            throw new StorageException('Unable to upload image to B2', 503, $e);
        }
    }

    public function delete(string $user, string $imageIdentifier): bool
    {
        if (!$this->imageExists($user, $imageIdentifier)) {
            throw new StorageException('File not found', 404);
        }

        try {
            return $this->client->deleteFile($this->getImagePath($user, $imageIdentifier));
        } catch (Client\Exception $e) {
            throw new StorageException('Unable to delete image', 503, $e);
        }
    }

    public function getImage(string $user, string $imageIdentifier): ?string
    {
        if (!$this->imageExists($user, $imageIdentifier)) {
            throw new StorageException('File not found', 404);
        }

        try {
            return $this->client->getFile($this->getImagePath($user, $imageIdentifier));
        } catch (Client\Exception $e) {
            throw new StorageException('Unable to get image', 503, $e);
        }
    }

    public function getLastModified(string $user, string $imageIdentifier): DateTime
    {
        if (!$this->imageExists($user, $imageIdentifier)) {
            throw new StorageException('File not found', 404);
        }

        try {
            $info = $this->client->getFileInfo($this->getImagePath($user, $imageIdentifier));
        } catch (Client\Exception $e) {
            throw new StorageException('Unable to get file info', 503, $e);
        }

        $timestamp = isset($info['x-bz-info-src_last_modified_millis'])
            ? (int) $info['x-bz-info-src_last_modified_millis']
            : (time() * 1000);

        return new DateTime('@' . (int) ($timestamp / 1000));
    }

    public function getStatus(): bool
    {
        return $this->client->getStatus();
    }

    public function imageExists(string $user, string $imageIdentifier): bool
    {
        try {
            return $this->client->fileExists($this->getImagePath($user, $imageIdentifier));
        } catch (Client\Exception $e) {
            throw new StorageException('Unable to check if image exists', 503, $e);
        }
    }

    /**
     * Get the path to an image
     *
     * @param string $user The user which the image belongs to
     * @param string $imageIdentifier Image identifier
     * @return string
     */
    protected function getImagePath(string $user, string $imageIdentifier): string
    {
        return $user . '/' . $imageIdentifier;
    }
}
