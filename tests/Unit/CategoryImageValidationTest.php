<?php
namespace Tests\Unit;

use App\Http\Requests\Admin\CategoryRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class CategoryImageValidationTest extends TestCase
{
    public function test_image_is_optional_and_real_png_is_accepted(): void
    {
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rules = (new CategoryRequest())->rules();
        $this->assertTrue($validator->make(['name' => 'Morning Routine'], $rules)->passes());
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $image = UploadedFile::fake()->createWithContent('category.png', $png);
        $this->assertTrue($validator->make(['name' => 'Morning Routine', 'image' => $image], $rules)->passes());
    }

    public function test_non_image_and_oversized_uploads_are_rejected(): void
    {
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rules = (new CategoryRequest())->rules();
        foreach ([UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;')->mimeType('text/plain'), UploadedFile::fake()->create('large.png', 5121, 'image/png')] as $image) {
            $this->assertTrue($validator->make(['name' => 'Morning Routine', 'image' => $image], $rules)->fails());
        }
    }
}
