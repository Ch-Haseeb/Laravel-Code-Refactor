<?php

namespace  App\Tests;

use PHPUnit\Framework\TestCase;
use App\Helpers\TeHelper;
use Carbon\Carbon;

class TeHelperTest extends TestCase
{

    public function testWillExpireAt()
    {

        $dueTime = Carbon::now()->addHours(2)->toDateTimeString();
        $createdAt = Carbon::now()->toDateTimeString();
        $expected = $dueTime;
        $this->assertEquals($expected, TeHelper::willExpireAt($dueTime, $createdAt));

       
        $dueTime = Carbon::now()->addHours(20)->toDateTimeString();
        $createdAt = Carbon::now()->toDateTimeString();
        $expected = Carbon::parse($createdAt)->addMinutes(90)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($dueTime, $createdAt));

        $dueTime = Carbon::now()->addHours(50)->toDateTimeString();
        $createdAt = Carbon::now()->toDateTimeString();
        $expected = Carbon::parse($createdAt)->addHours(16)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($dueTime, $createdAt));

        $dueTime = Carbon::now()->addHours(100)->toDateTimeString();
        $createdAt = Carbon::now()->toDateTimeString();
        $expected = Carbon::parse($dueTime)->subHours(48)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($dueTime, $createdAt));
    }
}
