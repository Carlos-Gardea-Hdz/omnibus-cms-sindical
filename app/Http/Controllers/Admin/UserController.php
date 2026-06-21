<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\CreateUserAction;
use App\Domain\Identity\Actions\DeleteUserAction;
use App\Domain\Identity\Actions\UpdateUserAction;
use App\Domain\Identity\Data\UpdateUserData;
use App\Domain\Identity\Data\UserData;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * User administration (SPEC §3.1 AUTH-04, slice-008 §B). Gated `role:administrator`
 * upstream in routes/web.php, so an editor/manager hitting any of these is a 403; the
 * group also carries `org.scope` (sets context) + `demo` (a demo user is blocked on the
 * write routes).
 *
 * Org-confinement is an EXPLICIT `where`, NEVER the global scope: the {@see User} model
 * carries NO {@see \App\Support\OrganizationScope} (Deviation A — the login lookup runs
 * before context), so route-model binding does not auto-scope {user}. An administrator
 * therefore sees + binds only its own-org users (a cross-org target → 404), while a
 * super_admin runs cross-org.
 *
 * Anemic by law (≤15 lines/method): each mutation hands a validated DTO (resolved via
 * the method signature → web failure is 302 + session errors, never 422) plus the acting
 * {@see User} to its Action, which owns the assignable-set check, the server-side
 * organization stamp for an administrator, the super_admin singleton swap, and the
 * self-delete / last-super_admin / self-elevation guards (each rendered to a graceful
 * 302 + field error / flash, never a 500). No `Request`, no `DB` facade here.
 */
final class UserController extends Controller
{
    public function index(): Response
    {
        $actor = $this->actor();
        $crossOrg = $actor->role === UserRole::SuperAdmin;

        $users = User::query()
            ->with('organization:id,name')
            ->when(! $crossOrg, fn ($query) => $query->where('organization_id', $actor->organization_id))
            ->orderBy('username')
            ->get()
            ->map(fn (User $user): array => $this->mapRow($user))
            ->all();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'can' => [
                'create' => true,
                'assign_super_admin' => $crossOrg,
                'manage_cross_org' => $crossOrg,
            ],
            'filters' => ['organization_id' => $crossOrg ? null : $actor->organization_id],
        ]);
    }

    public function create(): Response
    {
        $actor = $this->actor();
        $isSuper = $actor->role === UserRole::SuperAdmin;

        return Inertia::render('Admin/Users/Create', [
            'assignable_roles' => $this->assignableRoles($actor),
            'organizations' => $isSuper ? $this->organizationOptions() : null,
        ]);
    }

    public function store(UserData $data, CreateUserAction $action): RedirectResponse
    {
        $action->handle($data, $this->actor());

        return redirect()->route('admin.users.index')->with('success', __('users.created'));
    }

    public function edit(User $user): Response
    {
        $actor = $this->actor();
        $this->assertReachable($actor, $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'organization_id' => $user->organization_id,
                'is_demo' => $user->is_demo,
            ],
            'assignable_roles' => $this->assignableRoles($actor),
            'organizations' => $actor->role === UserRole::SuperAdmin ? $this->organizationOptions() : null,
            'is_self' => $user->is($actor),
            'is_only_super_admin' => $user->role === UserRole::SuperAdmin
                && User::query()->where('role', UserRole::SuperAdmin->value)->count() === 1,
        ]);
    }

    public function update(User $user, UpdateUserData $data, UpdateUserAction $action): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertReachable($actor, $user);
        $action->handle($data, $user, $actor);

        return redirect()->route('admin.users.index')->with('success', __('users.updated'));
    }

    public function destroy(User $user, DeleteUserAction $action): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertReachable($actor, $user);
        $action->handle($user, $actor);

        return redirect()->route('admin.users.index')->with('success', __('users.deleted'));
    }

    /** The authenticated acting user (the `role:administrator` gate guarantees presence). */
    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, HttpResponse::HTTP_FORBIDDEN);

        return $actor;
    }

    /**
     * 404 a cross-org target for a non-super_admin (write-isolation crown). The User
     * model has no global scope, so route-model binding does not confine {user}; an
     * administrator may only reach an own-org user.
     */
    private function assertReachable(User $actor, User $target): void
    {
        if ($actor->role === UserRole::SuperAdmin) {
            return;
        }

        abort_if($target->organization_id !== $actor->organization_id, HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * The roles an actor may assign (administrator excludes super_admin — AUTH-04).
     *
     * @return list<array{value: string, label_key: string}>
     */
    private function assignableRoles(User $actor): array
    {
        $roles = $actor->role === UserRole::SuperAdmin
            ? UserRole::cases()
            : array_filter(UserRole::cases(), fn (UserRole $role): bool => $role !== UserRole::SuperAdmin);

        return array_values(array_map(
            fn (UserRole $role): array => ['value' => $role->value, 'label_key' => $role->labelKey()],
            $roles,
        ));
    }

    /**
     * The organization select options for a super_admin (id + name only).
     *
     * @return list<array{id: int, name: string}>
     */
    private function organizationOptions(): array
    {
        return array_values(
            Organization::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ])->all()
        );
    }

    /**
     * Shape one user row for the admin index (snake_case contract — NO password / token).
     *
     * @return array<string, mixed>
     */
    private function mapRow(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label_key' => $user->role->labelKey(),
            'organization_id' => $user->organization_id,
            'organization_name' => $user->organization?->name,
            'is_demo' => $user->is_demo,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
