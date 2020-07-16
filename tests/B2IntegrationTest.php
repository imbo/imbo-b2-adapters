<?php declare(strict_types=1);
namespace Imbo\Storage;

use ChrisWhite\B2\Client;
use ChrisWhite\B2\File;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\Storage\B2
 * @group integration
 */
class B2IntegrationTest extends TestCase {
    private B2 $adapter;
    private string $user    = 'user';
    private string $imageId = 'image-id';

    public function setUp() : void {
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

        // Empty the bucket used for testing
        $client   = new Client((string) getenv('B2_KEY_ID'), (string) getenv('B2_APPLICATION_KEY'));
        $filename = sprintf('%s/%s', $this->user, $this->imageId);

        /** @var File[] */
        $files    = $client->listFiles(['FileName' => $filename, 'BucketId' => (string) getenv('B2_BUCKET_ID')]);

        foreach ($files as $file) {
            $client->deleteFile(['FileName' => $filename, 'FileId' => $file->getId()]);
        }

        $this->adapter = new B2(
            (string) getenv('B2_KEY_ID'),
            (string) getenv('B2_APPLICATION_KEY'),
            (string) getenv('B2_BUCKET_ID'),
            (string) getenv('B2_BUCKET_NAME'),
            $client,
        );
    }

    /**
     * @covers ::store
     * @covers ::delete
     * @covers ::getImage
     * @covers ::getLastModified
     * @covers ::getStatus
     * @covers ::imageExists
     */
    public function testCanIntegrateWithB2() : void {
        $this->assertTrue(
            $this->adapter->getStatus(),
            'Expected status to be true',
        );

        $this->assertFalse(
            $this->adapter->imageExists($this->user, $this->imageId),
            'Did not expect image to exist',
        );

        $this->assertTrue(
            $this->adapter->store($this->user, $this->imageId, (string) file_get_contents(__DIR__ . '/fixtures/test-image.png')),
            'Expected adapter to store image',
        );

        $this->assertEqualsWithDelta(
            (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp(),
            $this->adapter->getLastModified($this->user, $this->imageId)->getTimestamp(),
            5,
            'Expected timestamps to be equal',
        );

        $this->assertTrue(
            $this->adapter->imageExists($this->user, $this->imageId),
            'Expected image to exist',
        );

        $this->assertSame(
            (string) file_get_contents(__DIR__ . '/fixtures/test-image.png'),
            $this->adapter->getImage($this->user, $this->imageId),
            'Expected images to match'
        );

        $this->assertTrue(
            $this->adapter->delete($this->user, $this->imageId),
            'Expected image to be deleted',
        );

        $this->assertFalse(
            $this->adapter->imageExists($this->user, $this->imageId),
            'Did not expect image to exist',
        );
    }
}
