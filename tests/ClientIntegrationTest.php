<?php declare(strict_types=1);
namespace Imbo\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[CoversClass(Client::class)]
class ClientIntegrationTest extends TestCase
{
    private Client $client;

    public function setUp(): void
    {
        $required = [
            'B2_KEY_ID',
            'B2_APPLICATION_KEY',
            'B2_BUCKET_ID',
            'B2_BUCKET_NAME',
        ];
        $missing = [];

        foreach ($required as $var) {
            if (empty(getenv($var))) {
                $missing[] = $var;
            }
        }

        if (count($missing)) {
            $this->markTestSkipped(sprintf('Missing required environment variable(s) for the integration tests: %s', join(', ', $missing)));
        }

        $this->client = new Client(
            (string) getenv('B2_KEY_ID'),
            (string) getenv('B2_APPLICATION_KEY'),
            (string) getenv('B2_BUCKET_ID'),
            (string) getenv('B2_BUCKET_NAME'),
        );
        $this->client->emptyBucket();
    }

    public function testCanUploadFile(): void
    {
        $this->assertTrue(
            $this->client->uploadFile('some/name', 'this is my content'),
        );
    }

    public function testCanGetFileInfo(): void
    {
        $this->assertTrue(
            $this->client->uploadFile('some/name', 'this is my content'),
        );

        $info = $this->client->getFileInfo('some/name');

        $this->assertArrayHasKey(
            'x-bz-info-src_last_modified_millis',
            $info,
            'File info is missing an important key',
        );

        $this->assertEqualsWithDelta(
            time() * 1000,
            (int) $info['x-bz-info-src_last_modified_millis'],
            5000,
            'Last modification timestamp is off',
        );
    }
}
