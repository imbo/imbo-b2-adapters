<?php declare(strict_types=1);
namespace Imbo\Storage;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException as HttpClientException;
use Imbo\Storage\Client\Exception;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Client {
    private HttpClient $client;
    private string $bucketId;
    private string $bucketName;
    private string $downloadUrl;

    /**
     * Class constructor
     *
     * The constructor creates an authorization token, and creates an underlying HTTP client
     *
     * @param string $keyId
     * @param string $applicationKey
     * @param string $bucketId
     * @param string $bucketName
     * @param HttpClient $authClient Pre-configured client instance used for generating the auth token
     * @param HttpClient $httpClient Pre-configured client instance used for API calls
     */
    public function __construct(string $keyId, string $applicationKey, string $bucketId, string $bucketName, HttpClient $authClient = null, HttpClient $httpClient = null) {
        try {
            /** @var array{authorizationToken: string, apiUrl: string, downloadUrl: string} */
            $response = $this->responseAsJson(($authClient ?: new HttpClient())->get('https://api.backblazeb2.com/b2api/v2/b2_authorize_account', [
                'auth' => [$keyId, $applicationKey],
            ]));
        } catch (HttpClientException $e) {
            throw new Exception('Unable to create HttpClient for the B2 API', 503, $e);
        }

        $this->bucketId    = $bucketId;
        $this->bucketName  = $bucketName;
        $this->downloadUrl = rtrim($response['downloadUrl'], '/');
        $this->client      = $httpClient ?: new HttpClient([
            'base_uri' => rtrim($response['apiUrl'], '/') . '/b2api/v2/',
            'headers'  => [
                'Authorization' => $response['authorizationToken'],
            ],
        ]);
    }

    /**
     * Upload a file to B2
     *
     * This method will try to upload the file to B2 five times, as per the docs.
     *
     * @see https://www.backblaze.com/b2/docs/uploading.html
     * @param string $fileName
     * @param string $data
     * @throws Exception
     * @return bool
     */
    public function uploadFile(string $fileName, string $data) : bool {
        /** @var ?Throwable */
        $e = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                /** @var array{uploadUrl?: string, authorizationToken?: string} */
                $response = $this->responseAsJson($this->client->post('b2_get_upload_url', [
                    'json' => [
                        'bucketId' => $this->bucketId,
                    ]
                ]));
            } catch (HttpClientException $e) {
                continue;
            }

            if (!isset($response['uploadUrl']) || !isset($response['authorizationToken'])) {
                continue;
            }

            try {
                $this->client->post($response['uploadUrl'], [
                    'headers' => [
                        'Authorization'                      => $response['authorizationToken'],
                        'Content-Type'                       => 'b2/x-auto',
                        'Content-Length'                     => strlen($data),
                        'X-Bz-Content-Sha1'                  => sha1($data),
                        'X-Bz-File-Name'                     => $fileName,
                        'X-Bz-Info-src_last_modified_millis' => time() * 1000,
                    ],
                    'body' => $data,
                ]);
            } catch (HttpClientException $e) {
                continue;
            }

            return true;
        }

        throw new Exception('Unable to upload file to B2', 503, $e);
    }

    /**
     * Delete all versions of a file
     *
     * @param string $fileName
     * @throws Exception
     * @return bool
     */
    public function deleteFile(string $fileName) : bool {
        if (!$this->fileExists($fileName)) {
            throw new Exception('File does not exist', 404);
        }

        $startFileName   = $fileName;
        $startFileId     = null;
        $fileIdsToDelete = [];

        while ($startFileName === $fileName) {
            try {
                /** @var array{files: array<int, array{fileId: string, fileName: string}>, nextFileName: ?string, nextFileId: ?string} */
                $response = $this->responseAsJson($this->client->post('b2_list_file_versions', [
                    'json' => [
                        'bucketId'      => $this->bucketId,
                        'startFileName' => $startFileName,
                        'startFileId'   => $startFileId,
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
            $startFileId   = $response['nextFileId'];
        }

        foreach ($fileIdsToDelete as $fileId) {
            try {
                $this->client->post('b2_delete_file_version', [
                    'json' => [
                        'fileId'   => $fileId,
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
     * Remove all file versions from the bucket
     *
     * @throws Exception
     * @return bool
     */
    public function emptyBucket() : bool {
        $startFileName = null;
        $startFileId   = null;

        do {
            try {
                /** @var array{files: array<int, array{fileId: string, fileName: string}>, nextFileName: ?string, nextFileId: ?string} */
                $response = $this->responseAsJson($this->client->post('b2_list_file_versions', [
                    'json' => [
                        'bucketId'      => $this->bucketId,
                        'startFileName' => $startFileName,
                        'startFileId'   => $startFileId,
                    ],
                ]));
            } catch (HttpClientException $e) {
                throw new Exception('Unable to list file versions', 503, $e);
            }

            $startFileName = $response['nextFileName'];
            $startFileId   = $response['nextFileId'];

            foreach ($response['files'] as $file) {
                try {
                    $this->client->post('b2_delete_file_version', [
                        'json' => [
                            'fileId'   => $file['fileId'],
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
     * Check status
     *
     * @return bool
     */
    public function getStatus() : bool {
        try {
            $this->client->post('b2_list_file_names', [
                'json' => [
                    'bucketId'     => $this->bucketId,
                    'maxFileCount' => 1,
                ]
            ]);
        } catch (HttpClientException $e) {
            return false;
        }

        return true;
    }

    /**
     * Check if a file exists
     *
     * @param string $fileName
     * @throws Exception
     * @return bool
     */
    public function fileExists(string $fileName) : bool {
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
     * Get a file
     *
     * @param string $fileName
     * @throws Exception
     * @return string
     */
    public function getFile(string $fileName) : string {
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
     * Get information regarding a single file
     *
     * @param string $fileName
     * @return array<string, string>
     */
    public function getFileInfo(string $fileName) : array {
        try {
            $response = $this->client->head($this->getFileUrl($fileName));
        } catch (HttpClientException $e) {
            if (404 === $e->getCode()) {
                throw new Exception('File does not exist', 404, $e);
            }

            throw new Exception('Unable to get file info', 503, $e);
        }

        /** @var array<string, string> */
        return array_map(fn(array $header) : string => implode($header), $response->getHeaders());
    }

    /**
     * Convert the Guzzle response instance
     *
     * @param ResponseInterface $response
     * @return array<array-key, mixed>
     */
    private function responseAsJson(ResponseInterface $response) : array {
        /** @var array<array-key, mixed> */
        $json = json_decode($response->getBody()->getContents(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new Exception('B2 API returned invalid JSON: ' . json_last_error_msg(), 503);
        }

        return $json;
    }

    /**
     * Get the URL for a file
     *
     * @param string $fileName
     * @return string
     */
    private function getFileUrl(string $fileName) : string {
        return $this->downloadUrl . '/file/' . $this->bucketName . '/' . $fileName;
    }
}
