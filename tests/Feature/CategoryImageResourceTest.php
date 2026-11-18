<?php
namespace Tests\Feature;

use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Hobby;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryImageResourceTest extends TestCase
{
    public function test_activity_exposes_replacement_image_url_and_missing_image(): void
    {
        Storage::fake('public');
        $hobby = new Hobby(['name' => 'Morning Routine', 'image_path' => 'categories/first.jpg']);
        $hobby->id = 14;
        $activity = new Activity();
        $activity->setRelation('hobby', $hobby);
        $first = (new ActivityResource($activity))->resolve();
        $this->assertSame(Storage::disk('public')->url('categories/first.jpg'), $first['hobby']['image_url']);
        $hobby->image_path = 'categories/replacement.jpg';
        $next = (new ActivityResource($activity))->resolve();
        $this->assertNotSame($first['hobby']['image_url'], $next['hobby']['image_url']);
        $hobby->image_path = null;
        $this->assertNull((new ActivityResource($activity))->resolve()['hobby']['image_url']);
    }
}
