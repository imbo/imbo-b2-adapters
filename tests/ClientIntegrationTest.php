<?php declare(strict_types=1);

namespace Imbo\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[CoversClass(Client::class)]
#[RequiresEnvironmentVariable('B2_KEY_ID')]
#[RequiresEnvironmentVariable('B2_APPLICATION_KEY')]
#[RequiresEnvironmentVariable('B2_BUCKET_ID')]
#[RequiresEnvironmentVariable('B2_BUCKET_NAME')]
class ClientIntegrationTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
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
