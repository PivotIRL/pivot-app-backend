<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * The Organization error renderer must not change any other API. These probe routes are not handled by an
 * Organization controller, so they must keep the application's shared responses exactly as before.
 * No database access: the probes throw the exceptions directly.
 */
class NonOrganizationErrorResponsesUnchangedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/__probe')->group(function () {
            Route::get('missing-model', fn () => throw (new ModelNotFoundException)->setModel(User::class, [1]));
            Route::get('missing-organization-model', fn () => throw (new ModelNotFoundException)->setModel(Organization::class, [1]));
            Route::get('duplicate', fn () => throw new UniqueConstraintViolationException('pgsql', 'insert', [], new PDOException('SQLSTATE[23505]')));
            Route::get('query', fn () => throw new QueryException('pgsql', 'select', [], new PDOException('SQLSTATE[08006]')));
            Route::get('unexpected', fn () => throw new RuntimeException('internal detail'));
        });
    }

    public function test_missing_model_keeps_the_shared_message(): void
    {
        $this->getJson('/api/v1/__probe/missing-model')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Requested endpoint was not found.']);

        // Even an Organization model, when thrown outside Organization routes.
        $this->getJson('/api/v1/__probe/missing-organization-model')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Requested endpoint was not found.']);
    }

    public function test_database_errors_keep_the_shared_422(): void
    {
        $this->getJson('/api/v1/__probe/duplicate')
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid data provided for this operation.']);

        $this->getJson('/api/v1/__probe/query')
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid data provided for this operation.']);
    }

    public function test_unexpected_errors_keep_the_shared_behaviour(): void
    {
        config(['app.debug' => false]);
        $this->getJson('/api/v1/__probe/unexpected')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Unexpected server error. Please try again later.']);

        config(['app.debug' => true]);
        $this->getJson('/api/v1/__probe/unexpected')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'internal detail']);
    }

    public function test_mobile_routes_keep_their_responses(): void
    {
        $this->getJson('/api/v1/announcements')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->getJson('/api/v1/this-endpoint-does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Requested endpoint was not found.']);
    }
}
