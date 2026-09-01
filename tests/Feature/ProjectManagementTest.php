<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotoDerivative;
use App\Models\Project;
use App\Models\User;
use App\Services\Media\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_project_name_and_description(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Original project',
            'description' => 'Original description',
        ]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);

        $response = $this->actingAs($owner)->patch(route('projects.update', $project), [
            'name' => 'Renamed project',
            'description' => 'Updated description',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.success', 'Project updated.')
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Renamed project',
            'description' => 'Updated description',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_archive_project_and_dashboard_exposes_archived_status(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);

        $this->actingAs($owner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'description' => $project->description,
                'status' => 'archived',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $page = $this->inertiaPage(
            $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent(),
        );
        $payload = collect($page['props']['projects'])->firstWhere('id', $project->id);

        $this->assertIsArray($payload);
        $this->assertSame('archived', $payload['status']);
        $this->assertSame($project->description, $payload['description']);
    }

    public function test_owner_can_delete_project_and_all_media_bytes(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);
        $media = app(MediaStore::class);

        $original = $media->writeBytes('project-'.$project->id, 'original bytes', 'original.jpg', 'image/jpeg');
        $photo = Photo::factory()->create([
            'project_id' => $project->id,
            'path' => $media->recordPath($original),
        ]);
        $derivative = $media->writeBytes(
            'project-'.$project->id.'/derivatives',
            'derivative bytes',
            'approved.jpg',
            'image/jpeg',
        );
        PhotoDerivative::query()->create([
            'project_id' => $project->id,
            'photo_id' => $photo->id,
            'type' => Domain::DERIVATIVE_APPROVED_RENDER,
            'storage_path' => $media->recordPath($derivative),
            'adjustments' => ['exposure' => 0.2],
            'provenance' => 'test',
        ]);

        $response = $this->actingAs($owner)->delete(route('projects.destroy', $project));

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.success', 'Project deleted.')
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('photo_derivatives', ['photo_id' => $photo->id]);
        $this->assertFalse($media->exists($original['path']));
        $this->assertFalse($media->exists($derivative['path']));
    }

    public function test_non_owner_member_cannot_update_project(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Owner project',
        ]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);
        $project->members()->attach($member->id, ['role' => Domain::ROLE_PHOTOGRAPHER]);

        $this->actingAs($member)
            ->patch(route('projects.update', $project), [
                'name' => 'Should not save',
                'description' => 'Should not save',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Owner project',
        ]);
    }

    public function test_non_owner_member_cannot_delete_project(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);
        $project->members()->attach($member->id, ['role' => Domain::ROLE_VIEWER]);

        $this->actingAs($member)
            ->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_photographer_can_delete_photo_and_derivative_bytes(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);
        $media = app(MediaStore::class);
        $original = $media->writeBytes('project-'.$project->id, 'original bytes', 'original.jpg', 'image/jpeg');
        $photo = Photo::factory()->create([
            'project_id' => $project->id,
            'path' => $media->recordPath($original),
        ]);
        $derivative = $media->writeBytes(
            'project-'.$project->id.'/derivatives',
            'derivative bytes',
            'preview.jpg',
            'image/jpeg',
        );
        PhotoDerivative::query()->create([
            'project_id' => $project->id,
            'photo_id' => $photo->id,
            'type' => Domain::DERIVATIVE_RETOUCH_PREVIEW,
            'storage_path' => $media->recordPath($derivative),
            'adjustments' => ['warmth' => 0.1],
            'provenance' => 'test',
        ]);

        $response = $this
            ->from(route('workspace.show', $project))
            ->actingAs($owner)
            ->delete(route('workspace.photos.destroy', [$project, $photo]));

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.success', 'Photo deleted.')
            ->assertRedirect(route('workspace.show', $project));

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('photo_derivatives', ['photo_id' => $photo->id]);
        $this->assertFalse($media->exists($original['path']));
        $this->assertFalse($media->exists($derivative['path']));
    }

    public function test_root_redirects_member_to_their_newest_project(): void
    {
        $user = User::factory()->create();
        $oldProject = Project::factory()->create(['owner_id' => $user->id]);
        $newProject = Project::factory()->create(['owner_id' => $user->id]);
        $oldProject->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);
        $newProject->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);

        $this->actingAs($user)
            ->get(route('root'))
            ->assertRedirect(route('workspace.show', $newProject));
    }

    public function test_root_redirects_non_member_to_dashboard(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $member->id]);
        $project->members()->attach($member->id, ['role' => Domain::ROLE_OWNER]);

        $this->actingAs($outsider)
            ->get(route('root'))
            ->assertRedirect(route('dashboard'));
    }

    /**
     * @return array{props: array<string, mixed>}
     */
    private function inertiaPage(string $content): array
    {
        preg_match('/data-page="([^\"]+)"/', $content, $matches);
        $this->assertNotEmpty($matches, 'Inertia data-page payload must exist');

        $page = json_decode(htmlspecialchars_decode($matches[1], ENT_QUOTES), true);
        $this->assertIsArray($page);
        $this->assertArrayHasKey('props', $page);
        $this->assertIsArray($page['props']);

        return $page;
    }
}
