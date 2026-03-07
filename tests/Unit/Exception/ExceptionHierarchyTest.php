<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Exception;

use Ecourty\TorrentTrackerClient\Exception\ConnectionException;
use Ecourty\TorrentTrackerClient\Exception\InvalidResponseException;
use Ecourty\TorrentTrackerClient\Exception\TimeoutException;
use Ecourty\TorrentTrackerClient\Exception\TrackerException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function testAllExceptionsExtendTrackerException(): void
    {
        self::assertInstanceOf(TrackerException::class, new ConnectionException());
        self::assertInstanceOf(TrackerException::class, new InvalidResponseException());
        self::assertInstanceOf(TrackerException::class, new TimeoutException());
    }

    public function testTrackerExceptionExtendsRuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new TrackerException());
    }
}
