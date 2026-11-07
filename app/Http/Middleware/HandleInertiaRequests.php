<?php

namespace App\Http\Middleware;

use App\Actions\Tenancy\GetAccessibleTenants;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private GetAccessibleTenants $accessibleTenants) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'tenancy' => fn (): array => $this->tenantData($request),
        ];
    }

    /**
     * @return array{current: array{id: string, name: string|null}|null, available: list<array{id: string, name: string|null}>}
     */
    private function tenantData(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['current' => null, 'available' => []];
        }

        $tenant = $request->attributes->get('currentTenant');

        return [
            'current' => $tenant instanceof Tenant ? [
                'id' => (string) $tenant->getKey(),
                'name' => $tenant->name,
            ] : null,
            'available' => $this->accessibleTenants->handle($user),
        ];
    }
}
