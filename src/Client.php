<?php declare(strict_types=1);

namespace Imbo\Storage;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException as HttpClientException;
use GuzzleHttp\RequestOptions;
use Imbo\Storage\Client\Exception;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function strlen;

use const JSON_ERROR_NONE;

class Client
{
    private HttpClient $client;
    private string $bucketId;
    private string $bucketName;
    private string $downloadUrl;

    /**
     * Class constructor.
     *
     * The constructor creates an authorization token, and creates an underlying HTTP client
     *
     * @see https://www.backblaze.com/apidocs/b2-authorize-account
     *
     * @param HttpClient $authClient Pre-configured client instance used for generating the auth token
     * @param HttpClient $httpClient Pre-configured client instance used for API calls
     */
    public function __construct(string $keyId, string $applicationKey, string $bucketId, string $bucketName, ?HttpClient $authClient = null, ?HttpClient $httpClient = null)
    {
        try {
            /** @var array{authorizationToken:string,apiInfo:array{storageApi?:array{apiUrl:string,downloadUrl:string}}} */
            $response = $this->responseAsJson(($authClient ?: new HttpClient())->get('https://api.backblazeb2.com/b2api/v3/b2_authorize_account', [
                RequestOptions::AUTH => [$keyId, $applicationKey],
            ]));
        } catch (HttpClientException $e) {
            throw new Exception('Unable to create HttpClient for the B2 API', 503, $e);
        }

        if (empty($response['apiInfo']['storageApi'])) {
            throw new Exception('The B2 storage API is not enabled for the specified API key', 503);
        }

        $api = $response['apiInfo']['storageApi'];

        $this->bucketId = $bucketId;
        $this->bucketName = $bucketName;
        $this->downloadUrl = rtrim($api['downloadUrl'], '/');
        $this->client = $httpClient ?: new HttpClient([
            'base_uri' => rtrim($api['apiUrl'], '/').'/b2api/v3/',
            'headers' => [
                'Authorization' => $response['authorizationToken'],
            ],
        ]);
    }

    /**
     * Upload a file to B2.
     *
     * @see https://www.backblaze.com/apidocs/b2-get-upload-url
     * @see https://www.backblaze.com/apidocs/b2-upload-file
     *
     * @throws Exception
     */
    public function uploadFile(string $fileName, string $data): bool
    {
        /** @var ?Throwable */
        $e = null;

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                /** @var array{uploadUrl?:string,authorizationToken?:string} */
                $response = $this->responseAsJson($this->client->get('b2_get_upload_url', [
                    RequestOptions::QUERY => [
                        'bucketId' => $this->bucketId,
                    ],
                ]));
            } catch (HttpClientException $e) {
                continue;
            }

            if (!isset($response['uploadUrl']) || !isset($response['authorizationToken'])) {
                continue;
            }

            try {
                $this->client->post($response['uploadUrl'], [
                    RequestOptions::HEADERS => [
                        'Authorization' => $response['authorizationToken'],
                        'Content-Type' => 'b2/x-auto',
                        'Content-Length' => strlen($data),
                        'X-Bz-Content-Sha1' => sha1($data),
                        'X-Bz-File-Name' => $fileName,
                        'X-Bz-Info-src_last_modified_millis' => time() * 1000,
                    ],
                    RequestOptions::BODY => $data,
                ]);
            } catch (HttpClientException $e) {
                continue;
            }

            return true;
        }

        throw new Exception('Unable to upload file to B2', 503, $e);
    }

    /**
     * Delete all versions of a file.
     *
     * @see https://www.backblaze.com/apidocs/b2-list-file-versions
     * @see https://www.backblaze.com/apidocs/b2-delete-file-version
     *
     * @throws Exception
     */
    public function deleteFile(string $fileName): bool
    {
        if (!$this->fileExists($fileName)) {
            throw new Exception('File does not exist', 404);
        }

        $startFileName = $fileName;
        $startFileId = null;
        $fileIdsToDelete = [];

        while ($startFileName === $fileName) {
            try {
                /** @var array{files:list<array{fileId:string,fileName:string}>,nextFileName:?string,nextFileId:?string} */
                $response = $this->responseAsJson($this->client->get('b2_list_file_versions', [
                    RequestOptions::QUERY => [
                        'bucketId' => $this->bucketId,
                        'startFileName' => $startFileName,
                        'startFileId' => $startFileId,
                    ],
                ]));
            } catch (HttpClientException $e) {
                throw new Exception('Unable to list file versions', 503, $e);
            }

            foreach ($response['files'] as $file) {
                if ($file['fileName'] !== $fileName) {
                    break;
                }

                $fileIdsToDelete[] = $file['fileId'];
            }

            $startFileName = $response['nextFileName'];
            $startFileId = $response['nextFileId'];
        }

        foreach ($fileIdsToDelete as $fileId) {
            try {
                $this->client->post('b2_delete_file_version', [
                    RequestOptions::JSON => [
                        'fileId' => $fileId,
                        'fileName' => $fileName,
                    ],
                ]);
            } catch (HttpClientException $e) {
                throw new Exception('Unable to delete file version', 503, $e);
            }
        }

        return true;
    }

    /**
     * Remove all file versions from the bucket.
     *
     * @see https://www.backblaze.com/apidocs/b2-list-file-versions
     * @see https://www.backblaze.com/apidocs/b2-delete-file-version
     *
     * @throws Exception
     */
    public function emptyBucket(): bool
    {
        $startFileName = null;
        $startFileId = null;

        do {
            try {
                /** @var array{files:list<array{fileId:string,fileName:string}>,nextFileName:?string,nextFileId:?string} */
                $response = $this->responseAsJson($this->client->get('b2_list_file_versions', [
                    RequestOptions::QUERY => [
                        'bucketId' => $this->bucketId,
                        'startFileName' => $startFileName,
                        'startFileId' => $startFileId,
                    ],
                ]));
            } catch (HttpClientException $e) {
                throw new Exception('Unable to list file versions', 503, $e);
            }

            $startFileName = $response['nextFileName'];
            $startFileId = $response['nextFileId'];

            foreach ($response['files'] as $file) {
                try {
                    $this->client->post('b2_delete_file_version', [
                        RequestOptions::JSON => [
                            'fileId' => $file['fileId'],
                            'fileName' => $file['fileName'],
                        ],
                    ]);
                } catch (HttpClientException $e) {
                    throw new Exception('Unable to delete file version', 503, $e);
                }
            }
        } while (null !== $startFileName && null !== $startFileId);

        return true;
    }

    /**
     * Check status.
     *
     * @see https://www.backblaze.com/apidocs/b2-list-file-names
     */
    public function getStatus(): bool
    {
        try {
            $this->client->get('b2_list_file_names', [
                RequestOptions::QUERY => [
                    'bucketId' => $this->bucketId,
                    'maxFileCount' => 1,
                ],
            ]);
        } catch (HttpClientException $e) {
            return false;
        }

        return true;
    }

    /**
     * Check if a file exists.
     *
     * @throws Exception
     */
    public function fileExists(string $fileName): bool
    {
        try {
            $this->client->head($this->getFileUrl($fileName));
        } catch (HttpClientException $e) {
            if (404 === $e->getCode()) {
                return false;
            }

            throw new Exception('Unable to check if file exists', 503, $e);
        }

        return true;
    }

    /**
     * Get a file.
     *
     * @throws Exception
     */
    public function getFile(string $fileName): string
    {
        try {
            return $this->client->get($this->getFileUrl($fileName))->getBody()->getContents();
        } catch (HttpClientException $e) {
            if (404 === $e->getCode()) {
                throw new Exception('File does not exist', 404, $e);
            }

            throw new Exception('Unable to get file', 503, $e);
        }
    }

    /**
     * Get information regarding a single file.
     *
     * @return array<string,string>
     */
    public function getFileInfo(string $fileName): array
    {
        try {
            $response = $this->client->head($this->getFileUrl($fileName));
        } catch (HttpClientException $e) {
            if (404 === $e->getCode()) {
                throw new Exception('File does not exist', 404, $e);
            }

            throw new Exception('Unable to get file info', 503, $e);
        }

        /** @var array<string,string> */
        return array_map(static fn (array $header): string => implode('', $header), $response->getHeaders());
    }

    /**
     * Convert the Guzzle response instance.
     *
     * @return array<mixed>
     */
    private function responseAsJson(ResponseInterface $response): array
    {
        /** @var array<mixed> */
        $json = json_decode($response->getBody()->getContents(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new Exception('B2 API returned invalid JSON: '.json_last_error_msg(), 503);
        }

        return $json;
    }

    /**
     * Get the URL for a file.
     */
    private function getFileUrl(string $fileName): string
    {
        return $this->downloadUrl.'/file/'.$this->bucketName.'/'.$fileName;
    }
}
