<?php declare(strict_types=1);
namespace Imbo\Storage;

use ChrisWhite\B2\Bucket;
use ChrisWhite\B2\Client;
use ChrisWhite\B2\Exceptions\NotFoundException;
use ChrisWhite\B2\File;
use DateTime;
use DateTimeZone;
use Exception;
use Imbo\Exception\StorageException;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\Storage\B2
 */
class B2Test extends TestCase {
    private string $bucketId   = 'bucket-id';
    private string $bucketName = 'bucket-name';

    private function getAdapter(Client $client) : B2 {
        return new B2('key-id', 'application-key', $this->bucketId, $this->bucketName, $client);
    }

    /**
     * @covers ::__construct
     * @covers ::getImagePath
     * @covers ::store
     */
    public function testCanStoreImage() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('upload')
            ->with([
                'BucketId' => $this->bucketId,
                'FileName' => 'user/image-id',
                'Body'     => 'some image data',
            ]);

        $this->assertTrue(
            $this->getAdapter($client)->store('user', 'image-id', 'some image data'),
            'Expected store() to return true',
        );
    }

    /**
     * @covers ::store
     */
    public function testThrowsExceptionWhenClientIsUnableToUploadImage() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('upload')
            ->willThrowException($e = new Exception('some error'));

        $this->expectExceptionObject(new StorageException('Unable to upload image to B2', 503, $e));
        $this->getAdapter($client)->store('user', 'image-id', 'some image data');
    }

    /**
     * @covers ::delete
     */
    public function testCanDeleteImage() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('deleteFile')
            ->with([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => 'user/image-id'
            ]);

        $this->assertTrue(
            $this->getAdapter($client)->delete('user', 'image-id'),
            'Expected delete() to return true',
        );
    }

    /**
     * @return array<int, array{0: Exception, 1: string, 2: int}>
     */
    public function getDeleteExceptions() : array {
        return [
            [
                new NotFoundException(),
                'File not found',
                404,
            ],
            [
                new Exception(),
                'Unable to delete image',
                503,
            ],
        ];
    }

    /**
     * @dataProvider getDeleteExceptions
     * @covers ::delete
     */
    public function testThrowsExceptionWhenClientIsUnableToDeleteImage(Exception $e, string $expectedExceptionMessage, int $expectedExceptionCode) : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('deleteFile')
            ->willThrowException($e);

        $this->expectExceptionObject(new StorageException($expectedExceptionMessage, $expectedExceptionCode, $e));
        $this->getAdapter($client)->delete('user', 'image-id');
    }

    /**
     * @covers ::getImage
     */
    public function testCanGetImage() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('download')
            ->with([
                'BucketName' => $this->bucketName,
                'FileName'   => 'user/image-id',
            ])
            ->willReturn('image data');

        $this->assertSame(
            'image data',
            $this->getAdapter($client)->getImage('user', 'image-id'),
        );
    }

    /**
     * @covers ::getImage
     */
    public function testThrowsExceptionWhenFetchingImageThatDoesNotExist() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('download')
            ->with([
                'BucketName' => $this->bucketName,
                'FileName'   => 'user/image-id',
            ])
            ->willThrowException($e = new NotFoundException());

        $this->expectExceptionObject(new StorageException('File not found', 404, $e));
        $this->getAdapter($client)->getImage('user', 'image-id');
    }

    /**
     * @covers ::getLastModified
     */
    public function testCanGetLastModified() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('getFile')
            ->with([
                'BucketName' => $this->bucketName,
                'FileName'   => 'user/image-id',
            ])
            ->willReturn($this->createConfiguredMock(File::class, [
                'getUploadTimestamp' => 1462212185001,
            ]));

        $lastModified = $this->getAdapter($client)->getLastModified('user', 'image-id');
        $this->assertEquals(
            $lastModified->getTimestamp(),
            (new DateTime('@1462212185', new DateTimeZone('UTC')))->getTimestamp()
        );
    }

    /**
     * @covers ::getLastModified
     */
    public function testThrowsExceptionWhenFetchingLastModifiedDateForImageThatDoesNotExist() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('getFile')
            ->with([
                'BucketName' => $this->bucketName,
                'FileName'   => 'user/image-id',
            ])
            ->willThrowException($e = new NotFoundException());

        $this->expectExceptionObject(new StorageException('File not found', 404, $e));
        $this->getAdapter($client)->getLastModified('user', 'image-id');
    }

    /**
     * @return array<int, array{0: Bucket[], 1: bool}>
     */
    public function getBucketsForStatus() : array {
        return [
            [
                [],
                false,
            ],
            [
                [$this->createMock(Bucket::class)],
                true,
            ],
        ];
    }

    /**
     * @dataProvider getBucketsForStatus
     * @covers ::getStatus
     * @param Bucket[] $buckets
     */
    public function testCanGetStatus(array $buckets, bool $expectedStatus) : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('listBuckets')
            ->willReturn($buckets);

        $this->assertSame(
            $expectedStatus,
            $this->getAdapter($client)->getStatus(),
            'Incorrect status',
        );
    }

    /**
     * @return array<int, array{0: bool, 1: bool}>
     */
    public function getImageExistsData() : array {
        return [
            [
                false, false,
            ],
            [
                true, true,
            ],
        ];
    }

    /**
     * @dataProvider getImageExistsData
     * @covers ::imageExists
     */
    public function testCanCheckIfImageExists(bool $clientResponse, bool $imageExists) : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('fileExists')
            ->with([
                'BucketId' => $this->bucketId,
                'FileName' => 'user/image-id',
            ])
            ->willReturn($clientResponse);

        $this->assertSame(
            $imageExists,
            $this->getAdapter($client)->imageExists('user', 'image-id'),
            'Incorrect result for imageExists',
        );
    }

    /**
     * @covers ::imageExists
     */
    public function testImageExistsHandlesExceptions() : void {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('fileExists')
            ->with([
                'BucketId' => $this->bucketId,
                'FileName' => 'user/image-id',
            ])
            ->willThrowException(new NotFoundException());

        $this->assertFalse(
            $this->getAdapter($client)->imageExists('user', 'image-id'),
            'Expected file to not exist',
        );
    }
}