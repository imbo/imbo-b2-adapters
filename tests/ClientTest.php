<?php declare(strict_types=1);

namespace Imbo\Storage;

use ArrayObject;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Imbo\Storage\Client\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function sprintf;

#[CoversClass(Client::class)]
class ClientTest extends TestCase
{
    private string $keyId = 'keyId';
    private string $applicationKey = 'applicationKey';
    private string $bucketId = 'bucketId';
    private string $bucketName = 'bucketName';
    private string $authToken = 'token';
    private string $downloadUrl = 'downloadUrl';
    private string $apiUrl = 'apiUrl';

    /**
     * @param list<ResponseInterface|Throwable> $responses
     *
     * @return array{0:HttpClient,1:array<array{request:RequestInterface,response:ResponseInterface,error:mixed,options:array<mixed>}>}
     */
    private function getMockClient(array $responses): array
    {
        $history = new ArrayObject();
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));

        /** @var array<array{request:RequestInterface,response:ResponseInterface,error:mixed,options:array<mixed>}> $history */
        return [new HttpClient(['handler' => $handler]), $history];
    }

    /**
     * @return array{0:HttpClient,1:array<array{request:RequestInterface,response:ResponseInterface,error:mixed,options:array<mixed>}>}
     */
    private function getMockedAuthClient(): array
    {
        return $this->getMockClient([$this->getAuthResponse()]);
    }

    private function getAuthResponse(): Response
    {
        return new Response(200, [], (string) json_encode([
            'authorizationToken' => $this->authToken,
            'apiInfo' => [
                'storageApi' => [
                    'downloadUrl' => $this->downloadUrl,
                    'apiUrl' => $this->apiUrl,
                ],
            ],
        ]));
    }

    public function testCanConstructClient(): void
    {
        [$authClient, $history] = $this->getMockedAuthClient();

        new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
        );

        $this->assertCount(1, $history, 'Expected one transaction');
        $request = $history[0]['request'];
        $this->assertSame('Basic a2V5SWQ6YXBwbGljYXRpb25LZXk=', $request->getHeaderLine('Authorization'));
        $this->assertSame('GET', $request->getMethod());
    }

    public function testClientConstructionCanFail(): void
    {
        $this->expectExceptionObject(new Exception('Unable to create HttpClient for the B2 API', 503));
        [$authClient] = $this->getMockClient([new Response(400)]);
        new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
        );
    }

    public function testClientConstructionCanFailOnConnectionError(): void
    {
        $this->expectExceptionObject(new Exception('Unable to create HttpClient for the B2 API', 503));
        [$authClient] = $this->getMockClient([
            new ConnectException('Connection failed', new Request('GET', 'https://api.backblazeb2.com')),
        ]);
        new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
        );
    }

    public function testClientConstructionCanFailWhenApiIsNotAvailable(): void
    {
        $this->expectExceptionObject(new Exception('The B2 storage API is not enabled for the specified API key', 503));
        [$authClient] = $this->getMockClient([new Response(200, [], '{}')]);
        new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
        );
    }

    public function testCanUploadFile(): void
    {
        $uploadUrl = 'uploadUrl';
        $uploadToken = 'uploadToken';
        [$httpClient, $history] = $this->getMockClient(
            [
                new Response(400), // Trigger another attempt
                new Response(200, [], (string) json_encode([
                    'authorizationToken' => $uploadToken,
                ])), // Trigger another attempt
                new Response(200, [], (string) json_encode([
                    'uploadUrl' => $uploadUrl,
                ])), // Trigger another attempt
                new Response(200, [], (string) json_encode([
                    'uploadUrl' => $uploadUrl,
                    'authorizationToken' => $uploadToken,
                ])),
                new Response(200),
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->assertTrue(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->uploadFile('filename', 'data'),
            'Expected file to be uploaded',
        );

        parse_str($history[0]['request']->getUri()->getQuery(), $query);

        $this->assertCount(5, $history, 'Expected five transactions');
        $this->assertArrayHasKey('bucketId', $query, 'Expected bucketId in the query string');
        $this->assertSame(
            $this->bucketId,
            $query['bucketId'],
        );
        $this->assertSame(
            $uploadToken,
            $history[4]['request']->getHeaderLine('Authorization'),
            'Incorrect auth token for the upload',
        );
        $this->assertSame(
            4,
            (int) $history[4]['request']->getHeaderLine('Content-Length'),
            'Incorrect content length for the upload',
        );
        $this->assertSame(
            'a17c9aaa61e80a1bf71d0d850af4e5baa9800bbd',
            $history[4]['request']->getHeaderLine('X-Bz-Content-Sha1'),
            'Incorrect SHA for the upload',
        );
        $this->assertSame(
            'filename',
            $history[4]['request']->getHeaderLine('X-Bz-File-Name'),
            'Incorrect filename for the upload',
        );
        $this->assertEqualsWithDelta(
            time() * 1_000,
            (int) $history[4]['request']->getHeaderLine('X-Bz-Info-src_last_modified_millis'),
            1_000,
            'Incorrect last modification timestamp for the upload',
        );
        $this->assertSame(
            'data',
            $history[4]['request']->getBody()->getContents(),
            'Incorrect body for the upload',
        );
        $this->assertSame(
            $uploadUrl,
            $history[4]['request']->getUri()->getPath(),
            'Incorrect body for the upload',
        );
    }

    public function testThrowsExceptionWhenUploadingFileFails(): void
    {
        [$httpClient] = $this->getMockClient(
            [
                new Response(400), // Trigger another attempt
                new Response(400), // Trigger another attempt
                new Response(400), // Trigger another attempt
                new Response(400), // Trigger last attempt
                new Response(200, [], (string) json_encode([
                    'uploadUrl' => 'uploadUrl',
                    'authorizationToken' => 'uploadToken',
                ])),
                new Response(400), // Fail on the last attempt
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->expectExceptionObject(new Exception('Unable to upload file to B2', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->uploadFile('filename', 'data');
    }

    public function testCanDeleteFile(): void
    {
        [$httpClient, $history] = $this->getMockClient(
            [
                new Response(200), // Response for fileExists
                new Response(200, [], (string) json_encode([
                    'files' => [
                        [
                            'fileId' => 'id1',
                            'fileName' => 'some/name',
                        ],
                        [
                            'fileId' => 'id2',
                            'fileName' => 'some/name',
                        ],

                        // This should normally not occur, but
                        // adding it here to verify that the
                        // code does not accidentally delete the
                        // file
                        [
                            'fileId' => 'id3',
                            'fileName' => 'some/other/name',
                        ],
                    ],
                    'nextFileName' => null,
                    'nextFileId' => null,
                ])),
                new Response(200),
                new Response(200),
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->assertTrue(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->deleteFile('some/name'),
            'Expected to delete file',
        );

        $this->assertCount(4, $history, 'Expected four transactions');
        parse_str($history[1]['request']->getUri()->getQuery(), $query);
        $this->assertSame(
            [
                'bucketId' => $this->bucketId,
                'startFileName' => 'some/name',
            ],
            $query,
            'Unexpected query string',
        );
        $this->assertSame(
            [
                'fileId' => 'id1',
                'fileName' => 'some/name',
            ],
            json_decode($history[2]['request']->getBody()->getContents(), true),
            'Unexpected request body',
        );
        $this->assertSame(
            [
                'fileId' => 'id2',
                'fileName' => 'some/name',
            ],
            json_decode($history[3]['request']->getBody()->getContents(), true),
            'Unexpected request body',
        );
    }

    public function testDeleteFileThrowsExceptionWhenFileDoesNotExist(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(404)]);
        $this->expectExceptionObject(new Exception('File does not exist', 404));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->deleteFile('filename');
    }

    public function testDeleteFileThrowsExceptionWhenDeleteFails(): void
    {
        [$httpClient] = $this->getMockClient(
            [
                new Response(200), // Response for fileExists
                new Response(200, [], (string) json_encode([
                    'files' => [
                        [
                            'fileId' => 'id1',
                            'fileName' => 'some/name',
                        ],
                    ],
                    'nextFileName' => null,
                    'nextFileId' => null,
                ])),
                new Response(400),
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->expectExceptionObject(new Exception('Unable to delete file version', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->deleteFile('some/name');
    }

    public function testDeleteFileThrowsExceptionWhenUnableToListFileVersions(): void
    {
        [$httpClient] = $this->getMockClient(
            [
                new Response(200), // Response for fileExists
                new Response(500),
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->expectExceptionObject(new Exception('Unable to list file versions', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->deleteFile('some/name');
    }

    public function testCanEmptyBucket(): void
    {
        [$httpClient, $history] = $this->getMockClient(
            [
                new Response(200, [], (string) json_encode([
                    'nextFileName' => 'name2',
                    'nextFileId' => 'id3',
                    'files' => [
                        [
                            'fileId' => 'id1',
                            'fileName' => 'name',
                        ],
                        [
                            'fileId' => 'id2',
                            'fileName' => 'name',
                        ],
                    ],
                ])),
                new Response(200), // delete id1
                new Response(200), // delete id2
                new Response(200, [], (string) json_encode([
                    'nextFileName' => null,
                    'nextFileId' => null,
                    'files' => [
                        [
                            'fileId' => 'id3',
                            'fileName' => 'name2',
                        ],
                        [
                            'fileId' => 'id4',
                            'fileName' => 'name3',
                        ],
                    ],
                ])),
                new Response(200), // delete id3
                new Response(200), // delete id4
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->assertTrue(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->emptyBucket(),
            'Expected to empty bucket',
        );

        $this->assertCount(6, $history, 'Expected 6 transactions');
        $this->assertSame(
            ['fileId' => 'id1', 'fileName' => 'name'],
            json_decode($history[1]['request']->getBody()->getContents(), true),
        );
        $this->assertSame(
            ['fileId' => 'id2', 'fileName' => 'name'],
            json_decode($history[2]['request']->getBody()->getContents(), true),
        );
        parse_str($history[3]['request']->getUri()->getQuery(), $query);
        $this->assertSame(
            ['bucketId' => $this->bucketId, 'startFileName' => 'name2', 'startFileId' => 'id3'],
            $query,
        );
        $this->assertSame(
            ['fileId' => 'id3', 'fileName' => 'name2'],
            json_decode($history[4]['request']->getBody()->getContents(), true),
        );
        $this->assertSame(
            ['fileId' => 'id4', 'fileName' => 'name3'],
            json_decode($history[5]['request']->getBody()->getContents(), true),
        );
    }

    public function testEmptyBucketFailsWhenUnableToListFileVersions(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(500)]);
        $this->expectExceptionObject(new Exception('Unable to list file versions', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->emptyBucket();
    }

    public function testEmptyBucketFailsWhenUnableToDeleteFileVersions(): void
    {
        [$httpClient] = $this->getMockClient(
            [
                new Response(200, [], (string) json_encode([
                    'nextFileName' => null,
                    'nextFileId' => null,
                    'files' => [
                        [
                            'fileId' => 'id',
                            'fileName' => 'name',
                        ],
                    ],
                ])),
                new Response(400),
            ],
        );

        [$authClient] = $this->getMockedAuthClient();
        $this->expectExceptionObject(new Exception('Unable to delete file version', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->emptyBucket();
    }

    public function testCanGetStatus(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient, $history] = $this->getMockClient([new Response(200)]);
        $this->assertTrue(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->getStatus(),
            'Expected success status',
        );

        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        $this->assertArrayHasKey('bucketId', $query, 'Expected bucketId in the query string');
        $this->assertSame(
            $this->bucketId,
            $query['bucketId'],
            'Incorrect bucket ID',
        );
    }

    public function testGetStatusReturnsFalseOnFailure(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(400)]);
        $this->assertFalse(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->getStatus(),
            'Expected failure status',
        );
    }

    public function testCanCheckIfFileExists(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient, $history] = $this->getMockClient([new Response(200)]);
        $file = 'some/file';
        $this->assertTrue(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->fileExists($file),
            'Expected file to exist',
        );

        $this->assertSame(
            sprintf('%s/file/%s/%s', $this->downloadUrl, $this->bucketName, $file),
            $history[0]['request']->getUri()->getPath(),
            'Incorrect path in URI',
        );

        $this->assertSame(
            'HEAD',
            $history[0]['request']->getMethod(),
            'Incorrect HTTP method',
        );
    }

    public function testCheckIfFileExistsReturnsFalseWhenFileDoesNotExist(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(404)]);
        $this->assertFalse(
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->fileExists('some/file'),
            'Did not expect file to exist',
        );
    }

    public function testCheckIfFileExistsThrowsExceptionOnFailure(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(400)]);
        $this->expectExceptionObject(new Exception('Unable to check if file exists', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->fileExists('some/file');
    }

    public function testCanGetFile(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient, $history] = $this->getMockClient([new Response(200, [], 'some file content')]);
        $file = 'some/file';
        $this->assertSame(
            'some file content',
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->getFile($file),
            'Incorrect file content returned',
        );

        $this->assertSame(
            sprintf('%s/file/%s/%s', $this->downloadUrl, $this->bucketName, $file),
            $history[0]['request']->getUri()->getPath(),
            'Incorrect file path',
        );
    }

    public function testGetFileThrowsExceptionIfFilesDoesNotExist(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(404)]);
        $this->expectExceptionObject(new Exception('File does not exist', 404));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->getFile('some/file');
    }

    public function testGetFileThrowsExceptionOnError(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(500)]);
        $this->expectExceptionObject(new Exception('Unable to get file', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->getFile('some/file');
    }

    public function testCanGetFileInfo(): void
    {
        $file = 'some/file';
        $headers = [
            'Cache-Control' => 'max-age=0, no-cache, no-store',
            'x-bz-file-name' => 'some/name',
            'Date' => 'Sat, 29 Aug 2020 08:09:07 GMT',
        ];

        [$authClient] = $this->getMockedAuthClient();
        [$httpClient, $history] = $this->getMockClient([new Response(200, $headers)]);
        $this->assertSame(
            $headers,
            (new Client(
                $this->keyId,
                $this->applicationKey,
                $this->bucketId,
                $this->bucketName,
                $authClient,
                $httpClient,
            ))->getFileInfo($file),
            'Incorrect file info returned',
        );

        $this->assertSame(
            sprintf('%s/file/%s/%s', $this->downloadUrl, $this->bucketName, $file),
            $history[0]['request']->getUri()->getPath(),
            'Incorrect file path',
        );

        $this->assertSame(
            'HEAD',
            $history[0]['request']->getMethod(),
            'Incorrect HTTP method used',
        );
    }

    public function testGetFileInfoThrowsExceptionWhenFileDoesNotExist(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(404)]);
        $this->expectExceptionObject(new Exception('File does not exist', 404));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->getFileInfo('some/file');
    }

    public function testGetFileInfoThrowsExceptionOnError(): void
    {
        [$authClient] = $this->getMockedAuthClient();
        [$httpClient] = $this->getMockClient([new Response(403)]);
        $this->expectExceptionObject(new Exception('Unable to get file info', 503));
        (new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
            $httpClient,
        ))->getFileInfo('some/file');
    }

    public function testClientFailsWhenApiReturnsIncorrectJson(): void
    {
        [$authClient] = $this->getMockClient([new Response(200, [], 'OK')]);
        $this->expectExceptionObject(new Exception('B2 API returned invalid JSON: Syntax error', 503));
        new Client(
            $this->keyId,
            $this->applicationKey,
            $this->bucketId,
            $this->bucketName,
            $authClient,
        );
    }
}
