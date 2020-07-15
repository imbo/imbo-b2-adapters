<?php declare(strict_types=1);
namespace Imbo\Storage;

use Imbo\Exception\StorageException;
use ChrisWhite\B2\Client;
use ChrisWhite\B2\Exceptions\NotFoundException;
use Exception;
use DateTime;
use DateTimeZone;

class B2 implements StorageInterface {
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
    public function __construct(string $keyId, string $applicationKey, string $bucketId, string $bucketName, Client $client = null) {
        $this->keyId          = $keyId;
        $this->applicationKey = $applicationKey;
        $this->bucketId       = $bucketId;
        $this->bucketName     = $bucketName;
        $this->client         = $client ?: new Client($this->keyId, $this->applicationKey);
    }

    public function store(string $user, string $imageIdentifier, string $imageData) : bool {
        try {
            $this->client->upload([
                'BucketId' => $this->bucketId,
                'FileName' => $this->getImagePath($user, $imageIdentifier),
                'Body'     => $imageData,
            ]);
        } catch (Exception $e) {
            throw new StorageException('Unable to upload image to B2', 503, $e);
        }

        return true;
    }

    public function delete(string $user, string $imageIdentifier) : bool {
        try {
            $this->client->deleteFile([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (NotFoundException $e) {
            throw new StorageException('File not found', 404, $e);
        } catch (Exception $e) {
            throw new StorageException('Unable to delete image', 503, $e);
        }

        return true;
    }

    public function getImage(string $user, string $imageIdentifier) : ?string {
        try {
            return (string) $this->client->download([
                'BucketName' => $this->bucketName,
                'FileName'   => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (NotFoundException $e) {
            throw new StorageException('File not found', 404, $e);
        }
    }

    public function getLastModified(string $user, string $imageIdentifier) : DateTime {
        try {
            $info = $this->client->getFile([
                'BucketName' => $this->bucketName,
                'FileName'   => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (NotFoundException $e) {
            throw new StorageException('File not found', 404, $e);
        }

        return new DateTime('@' . floor((int) $info->getUploadTimestamp() / 1000), new DateTimeZone('UTC'));
    }

    public function getStatus() : bool {
        return !empty($this->client->listBuckets());
    }

    public function imageExists(string $user, string $imageIdentifier) : bool {
        try {
            return $this->client->fileExists([
                'BucketId' => $this->bucketId,
                'FileName' => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     * Get the path to an image
     *
     * @param string $user The user which the image belongs to
     * @param string $imageIdentifier Image identifier
     * @return string
     */
    protected function getImagePath(string $user, string $imageIdentifier) : string {
        return $user . '/' . $imageIdentifier;
    }
}
